<?php

namespace App\Http\Controllers;

use App\Models\PdvCashRegister;
use App\Models\User;
use App\Services\PdvCashService;
use App\Services\PdvOperatorAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PdvCashWorkflowController extends Controller
{
    public function setPin(Request $request,PdvOperatorAuthorization $authorization)
    {
        $data=$request->validate([
            'current_password'=>['required','string'],
            'pin'=>['required','digits:6','confirmed'],
        ]);
        $authorization->setPin($request->user(),$data['current_password'],$data['pin'],$request->ip());
        return redirect()->route('pdv.index')->with('success','PIN do operador configurado.');
    }

    public function handover(Request $request,PdvCashService $cash,PdvOperatorAuthorization $authorization)
    {
        $data=$request->validate([
            'outgoing_credential'=>['required','string'],
            'incoming_user_id'=>['required','integer','exists:users,id'],
            'incoming_credential'=>['required','string'],
            'counted_amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'notes'=>['nullable','string','max:1000'],
        ]);
        $outgoing=$request->user();
        $incoming=User::query()->findOrFail((int)$data['incoming_user_id']);
        if ($incoming->id===$outgoing->id || !$incoming->is_active || !$incoming->canAccess('pdv')) {
            throw ValidationException::withMessages(['incoming_user_id'=>'Selecione outro operador habilitado para o PDV.']);
        }
        $authorization->verify($outgoing,$data['outgoing_credential'],$request->ip());
        $authorization->verify($incoming,$data['incoming_credential'],$request->ip());
        $cash->handover($outgoing->id,$incoming->id,(string)$data['counted_amount'],$data['notes'] ?? null);

        // Replaces the authenticated operator only AFTER the DB transaction commits.
        Auth::login($incoming,false);
        $request->session()->regenerate();
        return redirect()->route('pdv.index')
            ->with('success','Turno transferido. Caixa conferido e novo operador identificado.');
    }

    public function registers(Request $request)
    {
        abort_unless($request->user()->role==='admin',403);
        $registers=PdvCashRegister::query()->with(['sessions'=>fn($q)=>$q->open()->with('user:id,name')])
            ->orderBy('id')->get();
        return view('pdv.cash-registers',compact('registers'));
    }

    public function createRegister(Request $request,PdvOperatorAuthorization $authorization)
    {
        abort_unless($request->user()->role==='admin',403);
        $data=$request->validate([
            'code'=>['required','string','max:30','regex:/^[A-Za-z0-9_-]+$/','unique:pdv_cash_registers,code'],
            'name'=>['required','string','min:3','max:100'],
            'credential'=>['required','string'],
        ]);
        $authorization->verify($request->user(),$data['credential'],$request->ip());
        PdvCashRegister::query()->create([
            'code'=>strtoupper($data['code']),
            'name'=>trim($data['name']),
            'is_active'=>true,
        ]);
        return back()->with('success','Caixa cadastrado.');
    }

    public function updateRegister(Request $request,PdvCashRegister $register,PdvOperatorAuthorization $authorization)
    {
        abort_unless($request->user()->role==='admin',403);
        $data=$request->validate([
            'name'=>['required','string','min:3','max:100'],
            'is_active'=>['required','boolean'],
            'credential'=>['required','string'],
        ]);
        $authorization->verify($request->user(),$data['credential'],$request->ip());
        if (!$data['is_active'] && $register->sessions()->open()->exists()) {
            throw ValidationException::withMessages([
                'is_active'=>'Feche o turno deste caixa antes de desativá-lo.',
            ]);
        }
        $register->update(['name'=>trim($data['name']),'is_active'=>(bool)$data['is_active']]);
        return back()->with('success','Caixa atualizado.');
    }
}

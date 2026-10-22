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
        // A six-digit PIN may unlock only an account limited to the PDV.
        // Administrator, manager, or multi-module accounts must enter full password.
        $credentials=$data['incoming_credential'];
        $cashierOnly=$incoming->role==='operator'
            && !array_diff($incoming->permissions ?? [],['pdv']);
        if (!$cashierOnly && !\Illuminate\Support\Facades\Hash::check(
            $credentials,(string)$incoming->password
        )) {
            throw ValidationException::withMessages([
                'incoming_credential'=>'Este perfil exige a senha completa para assumir o turno.',
            ]);
        }
        $authorization->verify($incoming,$credentials,$request->ip());
        $cash->handover($outgoing->id,$incoming->id,(string)$data['counted_amount'],$data['notes'] ?? null);

        // Replaces the authenticated operator only AFTER the DB transaction commits.
        Auth::login($incoming,false);
        $request->session()->regenerate();
        return redirect()->route('pdv.index')
            ->with('success','Turno transferido. Caixa conferido e novo operador identificado.');
    }

    /**
     * The original cashier is unavailable. A separately authenticated manager
     * performs the blind count and closes exactly that overdue session.
     */
    public function closeOverdue(Request $request,PdvCashService $cash,PdvOperatorAuthorization $authorization)
    {
        abort_unless(in_array($request->user()->role,['admin','manager'],true)
            && $request->user()->canAccess('pdv'),403);

        $data=$request->validate([
            'cash_session_id'=>['required','integer','exists:pdv_cash_sessions,id'],
            'counted_amount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'reason'=>['required','string','min:5','max:1000'],
            'credential'=>['required','string'],
        ]);
        $authorization->verify($request->user(),$data['credential'],$request->ip());
        $session=$cash->closeOverdueBySupervisor(
            (int)$data['cash_session_id'],
            (int)$request->user()->id,
            (string)$data['counted_amount'],
            $data['reason'],
        );
        return redirect()->route('pdv.cash.report',$session)
            ->with('success','Caixa do dia anterior regularizado. Confira o relatório do fechamento.');
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
        $request->merge(['code'=>strtoupper(trim((string)$request->input('code')))]);
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

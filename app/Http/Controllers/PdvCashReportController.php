<?php

namespace App\Http\Controllers;

use App\Models\PdvCashSession;
use App\Services\PdvCashService;
use Illuminate\Http\Request;

final class PdvCashReportController extends Controller
{
    public function index(Request $request)
    {
        $query=PdvCashSession::query()->with('user:id,name')->latest('opened_at');
        if($request->user()->role!=='admin') $query->where('user_id',$request->user()->id);
        return view('pdv.cash-history',['sessions'=>$query->paginate(30)]);
    }

    public function show(Request $request, PdvCashSession $cashSession, PdvCashService $cash)
    {
        abort_unless($request->user()->role==='admin'
            || $cashSession->user_id===$request->user()->id,403);

        $cashSession->load(['user:id,name','closer:id,name','reviewer:id,name','movements.user:id,name']);
        $breakdown=$cashSession->closed_at && is_array($cashSession->closing_breakdown)
            ? $cashSession->closing_breakdown
            : $cash->breakdown($cashSession);

        return view('pdv.cash-report',[
            'session'=>$cashSession,
            'breakdown'=>$breakdown,
            'methods'=>\App\Models\PaymentMethod::query()->pluck('name','code'),
        ]);
    }

    public function review(Request $request, PdvCashSession $cashSession, PdvCashService $cash)
    {
        abort_unless($request->user()->role==='admin',403);
        $data=$request->validate(['review_notes'=>['required','string','min:5','max:1000']]);
        $cash->review($cashSession,(int)$request->user()->id,$data['review_notes']);
        return redirect()->route('pdv.cash.report',$cashSession)
            ->with('success','Conferência administrativa registrada.');
    }
}

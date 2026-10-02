<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinancialReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FinancialReceiptController extends Controller
{
    public function index(Request $request)
    {
        $month=(string)$request->query('month',now()->format('Y-m'));
        try{$period=\Carbon\Carbon::createFromFormat('Y-m',$month)->startOfMonth();}catch(\Throwable){$period=now()->startOfMonth();}

        return view('finance.receipts.index',[
            'receipts'=>FinancialReceipt::query()->with('customer')
                ->whereYear('receipt_date',$period->year)->whereMonth('receipt_date',$period->month)
                ->orderByDesc('receipt_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'month'=>$period->format('Y-m'),
            'prevMonth'=>$period->copy()->subMonth()->format('Y-m'),
            'nextMonth'=>$period->copy()->addMonth()->format('Y-m'),
            'monthLabel'=>ucfirst($period->locale('pt_BR')->translatedFormat('F Y')),
        ]);
    }

    public function create()
    {
        return view('finance.receipts.form',[
            'customers'=>Customer::query()->orderBy('name')->get(['id','name','document']),
        ]);
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'issuer_mode'=>['required','in:company,person'],
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'recipient_name'=>['required','string','max:190'],
            'recipient_document'=>['nullable','string','max:30'],
            'amount'=>['required','numeric','gt:0','max:9999999999.99','decimal:0,2'],
            'receipt_date'=>['required','date'],
            'reference'=>['required','string','max:500'],
            'copies'=>['required','integer','in:1,2'],
            'attachment'=>['nullable','file','max:10240'],
        ]);

        if($request->hasFile('attachment')) {
            $file=$request->file('attachment');
            $data['attachment_name']=$file->getClientOriginalName();
            $data['attachment_path']=$file->store('finance/receipts','local');
        }
        unset($data['attachment']);
        $data['created_by']=$request->user()->id;

        $receipt=FinancialReceipt::query()->create($data);
        return redirect()->route('finance.receipts.print',$receipt);
    }

    public function print(FinancialReceipt $receipt)
    {
        $receipt->load(['customer','creator']);
        return view('finance.receipts.print',compact('receipt'));
    }

    public function attachment(FinancialReceipt $receipt)
    {
        abort_unless($receipt->attachment_path && Storage::disk('local')->exists($receipt->attachment_path),404);
        return Storage::disk('local')->download($receipt->attachment_path,$receipt->attachment_name ?: basename($receipt->attachment_path));
    }
}

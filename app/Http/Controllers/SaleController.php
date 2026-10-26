<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\AppSetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Services\SalesService;
use App\Services\QuotePdfService;
use RuntimeException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $status=$request->query('status','');
        $term=trim((string)$request->query('search',''));
        $perPage=AppSetting::tablePerPage($request);
        $month=(string)$request->query('month',now()->format('Y-m'));

        try {
            $period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();
        } catch (\Throwable $e) {
            $period=now()->startOfMonth();
        }

        $month=$period->format('Y-m');
        $prevMonth=$period->copy()->subMonth()->format('Y-m');
        $nextMonth=$period->copy()->addMonth()->format('Y-m');
        $monthLabel=ucfirst($period->locale('pt_BR')->translatedFormat('F Y'));

        $sales=Sale::with(['customer','user'])
            ->where(function($q) use ($period) {
                $q->where(function($dateQuery) use ($period) {
                    $dateQuery->whereNotNull('operation_date')
                        ->whereYear('operation_date',$period->year)
                        ->whereMonth('operation_date',$period->month);
                })->orWhere(function($legacyQuery) use ($period) {
                    $legacyQuery->whereNull('operation_date')
                        ->whereYear('created_at',$period->year)
                        ->whereMonth('created_at',$period->month);
                });
            })
            ->when($term,fn($q)=>$q->where(function($search) use($term){
                $numeric=(int)preg_replace('/\D+/','',$term);
                if($numeric>0) $search->orWhere('id',$numeric);
                $search->orWhere('keyword','like',"%{$term}%")
                    ->orWhere('notes','like',"%{$term}%")
                    ->orWhereHas('customer',fn($customer)=>$customer
                        ->where('name','like',"%{$term}%")
                        ->orWhere('document','like',"%{$term}%"))
                    ->orWhereHas('user',fn($user)=>$user->where('name','like',"%{$term}%"));
            }))
            ->when(in_array($status,['completed','cancelled','converted'],true),fn($q)=>$q->where('status',$status))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return view('sales.index',compact('sales','status','term','month','prevMonth','nextMonth','monthLabel'));
    }

    public function create()
    {
        return view('sales.create',[
            'products'=>Product::where('is_active',true)
                ->orderBy('name')
                ->get(['id','name','sku','unit','sale_price','stock_quantity','control_stock']),
            'services'=>Service::where('is_active',true)
                ->orderBy('name')
                ->get(['id','name','sale_price','service_list_item','cnae']),
            'customers'=>Customer::orderBy('name')->get(['id','name','document','final_consumer']),
            'paymentMethods'=>PaymentMethod::options(),
            'defaultFinalConsumer'=>(bool)AppSetting::value('operations','default_final_consumer',true),
            'defaultDueDays'=>max(0,(int)AppSetting::value('operations','default_due_days',0)),
        ]);
    }

    public function store(Request $request, SalesService $sales)
    {
        $data=$request->validate([
            'customer_id'=>['nullable','integer','exists:customers,id'],
            'operation_type'=>['required','in:sale,quote'],
            'operation_date'=>['required','date'],
            'quote_expires_at'=>['nullable','date','after_or_equal:operation_date'],
            'after_save'=>['nullable','in:show,pdf'],
            'final_consumer'=>['nullable','boolean'],
            'keyword'=>['nullable','string','max:190'],
            'notes'=>['nullable','string','max:5000'],

            'items'=>['required','array','min:1','max:100'],
            'items.*.item_type'=>['required','in:product,service,freight,expense'],
            'items.*.product_id'=>['nullable','integer','exists:products,id'],
            'items.*.service_id'=>['nullable','integer','exists:services,id'],
            'items.*.description'=>['nullable','string','max:190'],
            'items.*.quantity'=>['required','numeric','gt:0','max:9999999999','decimal:0,3'],
            'items.*.unit_price'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'items.*.discount'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'items.*.notes'=>['nullable','string','max:1000'],

            'payments'=>['nullable','array','max:60'],
            'payments.*.amount'=>['required_with:payments','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'payments.*.due_date'=>['nullable','date'],
            'payments.*.payment_method'=>['nullable',Rule::in(array_keys(PaymentMethod::options()))],
            'payments.*.receivable'=>['nullable','boolean'],
        ]);

        $data['final_consumer']=$request->boolean('final_consumer',true);

        $sale=$sales->create($data,(int)$request->user()->id);

        $message=$sale->operation_type==='quote'
            ? 'Orçamento salvo.'
            : 'Venda concluída e estoque atualizado.';

        if($sale->operation_type==='quote' && $request->input('after_save')==='pdf') {
            return redirect()->route('sales.quote.pdf',$sale)->with('success',$message);
        }
        return redirect()->route('sales.show',$sale)->with('success',$message);
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.product','items.service','payments','customer','user','convertedSale','sourceQuote']);
        $saleReturns=$sale->returns()->withCount('items')->orderByDesc('return_date')->orderByDesc('id')->get();
        $paymentLabels=PaymentMethod::query()->orderBy('sort_order')->pluck('name','code')->all();
        $nfceDocument=\App\Models\FiscalDocumentJob::query()
            ->where('sale_id',$sale->id)
            ->where('document_type','nfce')
            ->latest('id')
            ->first();

        return view('sales.show',compact('sale','saleReturns','paymentLabels','nfceDocument'));
    }

    public function receipt(Request $request, Sale $sale, \App\Services\SaleReceiptPdfService $pdf)
    {
        // Comprovante comercial: o Chrome recebe um PDF inline, como no orçamento.
        // Não preparar NFC-e nem movimentar venda, caixa, estoque ou financeiro.
        abort_unless($sale->operation_type==='sale',404);
        if ($request->query('format') === 'a4') {
            try {
                $html = $pdf->renderHtml($sale);
                // O HTML do comprovante utiliza a mesma fonte de dados da via térmica.
                // Remover o ajuste automático de bobina e aplicar papel A4.
                $html = preg_replace('~<script id="receipt-paper-fit">.*?</script>~s', '', $html);
                $a4Styles = '<style id="sale-a4-print">@page{size:A4 portrait;margin:12mm!important}html,body{background:#fff!important;font-size:12px!important}.receipt,.receipt-copy{width:100%!important;max-width:186mm!important;margin:0 auto 12mm!important;padding:5mm!important}.receipt .brand{font-size:18px!important}.receipt .items th,.receipt .items td{font-size:11px!important}.receipt .meta,.receipt .row,.receipt .section{font-size:11px!important}.screen-actions,.post-sale-status{display:none!important}@media print{.receipt{box-shadow:none!important;break-inside:auto!important}}</style>';
                $html = str_replace('</head>', $a4Styles.'</head>', $html);
                return response($html, 200, [
                    'Content-Type'=>'text/html; charset=UTF-8',
                    'Cache-Control'=>'private, no-store, max-age=0',
                    'X-Content-Type-Options'=>'nosniff',
                ]);
            } catch (RuntimeException $e) {
                abort(503, \App\Support\UserFacingMessage::fromThrowable($e,
                    'Não foi possível gerar o comprovante A4.'));
            }
        }
        try {
            $bytes=$pdf->render($sale);
        } catch (RuntimeException $e) {
            abort(503,\App\Support\UserFacingMessage::fromThrowable($e,
                'Não foi possível gerar o comprovante. Tente novamente ou solicite suporte.'));
        }
        $name='Comprovante-Venda-'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT).'.pdf';
        $mode=$request->boolean('download')?'attachment':'inline';
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>$mode.'; filename="'.$name.'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function quotePdf(Request $request, Sale $sale, QuotePdfService $pdf)
    {
        abort_unless($sale->operation_type==='quote',404);
        try {
            $bytes=$pdf->render($sale);
        } catch (RuntimeException $e) {
            abort(503,\App\Support\UserFacingMessage::fromThrowable($e,
                'Não foi possível gerar o PDF do orçamento. Tente novamente ou solicite suporte.'));
        }
        $name='Orcamento-'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT).'.pdf';
        $mode=$request->boolean('download')?'attachment':'inline';
        return response($bytes,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>$mode.'; filename="'.$name.'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function convertQuote(Sale $sale, SalesService $sales, Request $request)
    {
        $converted=$sales->convertQuote($sale,(int)$request->user()->id);
        return redirect()->route('sales.show',$converted)
            ->with('success','Orçamento convertido na venda #'.$converted->id.'.');
    }

    public function cancel(Request $request, Sale $sale, SalesService $sales)
    {
        $sales->cancel($sale,(int)$request->user()->id);

        $message=$sale->operation_type==='quote'
            ? 'Orçamento cancelado.'
            : 'Venda cancelada; quantidades devolvidas ao estoque.';

        return redirect()->route('sales.show',$sale)->with('success',$message);
    }
}

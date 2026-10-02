<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\FinancialEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class AccountingExportService
{
    public function generate(string $month): string
    {
        $period=Carbon::createFromFormat('Y-m',$month)->startOfMonth();
        $start=$period->copy()->startOfMonth()->toDateString();
        $end=$period->copy()->endOfMonth()->toDateString();

        $entries=FinancialEntry::query()
            ->with(['category','account','customer'])
            ->whereBetween('competence_date',[$start,$end])
            ->orderBy('competence_date')
            ->orderBy('id')
            ->get();

        $handle=fopen('php://temp','w+');

        fputcsv($handle,[
            'ID','Tipo','Status','Competência','Emissão','Vencimento','Descrição','Documento',
            'Categoria','Conta','Pessoa','Centro de custo','Forma de pagamento','Valor','Baixado',
            'Venda','Palavras-chave'
        ],';');

        foreach($entries as $entry) {
            fputcsv($handle,[
                $entry->id,
                $entry->type,
                $entry->status,
                optional($entry->competence_date)->format('Y-m-d'),
                optional($entry->issue_date)->format('Y-m-d'),
                optional($entry->due_date)->format('Y-m-d'),
                $entry->description,
                $entry->document_number,
                $entry->category?->name,
                $entry->account?->name,
                $entry->customer?->name,
                $entry->cost_center,
                $entry->payment_method,
                number_format((float)$entry->amount,2,'.',''),
                number_format((float)$entry->paid_amount,2,'.',''),
                $entry->sale_id,
                $entry->keywords,
            ],';');
        }

        rewind($handle);
        $csv=stream_get_contents($handle);
        fclose($handle);

        $path='accounting/exports/'.$period->format('Y').'/nextor-contabil-'.$period->format('Y-m').'.csv';
        Storage::disk('local')->put($path,"\xEF\xBB\xBF".$csv);

        return $path;
    }

    public function listExports(): array
    {
        return collect(Storage::disk('local')->allFiles('accounting/exports'))
            ->filter(fn($path)=>str_ends_with(strtolower($path),'.csv'))
            ->sortDesc()
            ->take(24)
            ->map(fn($path)=>[
                'path'=>$path,
                'name'=>basename($path),
                'size'=>Storage::disk('local')->size($path),
                'modified'=>Storage::disk('local')->lastModified($path),
            ])
            ->values()
            ->all();
    }

    public function automaticEnabled(): bool
    {
        return (bool)AppSetting::value('accounting','automatic_monthly_export',false);
    }
}

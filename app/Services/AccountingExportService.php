<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CompanySetting;
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

        $directory='accounting/exports/'.$period->format('Y');
        $baseName='nextor-contabil-'.$period->format('Y-m');
        $path=$directory.'/'.$baseName.'.csv';
        Storage::disk('local')->put($path,"\xEF\xBB\xBF".$csv);

        $company=CompanySetting::current();
        $accounting=AppSetting::groupValues('accounting',[]);
        $metadata=[
            'version'=>1,
            'competence'=>$period->format('Y-m'),
            'generated_at'=>now()->toIso8601String(),
            'format'=>'csv',
            'company'=>[
                'document'=>$company->document,
                'legal_name'=>$company->legal_name,
                'trade_name'=>$company->trade_name,
            ],
            'accounting'=>[
                'office_name'=>$accounting['office_name'] ?? null,
                'accountant_name'=>$accounting['accountant_name'] ?? null,
                'accountant_document'=>$accounting['accountant_document'] ?? null,
                'crc'=>$accounting['crc'] ?? null,
                'email'=>$accounting['email'] ?? null,
                'phone'=>$accounting['phone'] ?? null,
                'accounting_system'=>$accounting['accounting_system'] ?? null,
                'notes'=>$accounting['notes'] ?? null,
            ],
        ];
        Storage::disk('local')->put(
            $directory.'/'.$baseName.'.meta.json',
            json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        );

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

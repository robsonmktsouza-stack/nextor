<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use Illuminate\Support\Facades\Storage;

/**
 * Localiza somente arquivos fiscais existentes no armazenamento privado.
 * Não transforma recibos comerciais em documentos auxiliares fiscais.
 */
final class FiscalDocumentUtilityService
{
    private const TYPES=['nfe','nfce','nfse','cte','mdfe'];
    private const LABELS=[
        'nfe'=>'NFe','nfce'=>'NFCe','nfse'=>'NFSe','cte'=>'CTe','mdfe'=>'MDFe',
    ];
    private const AUXILIARY=[
        'nfe'=>'danfe.pdf','nfce'=>'danfe.pdf','nfse'=>'danfse.pdf',
        'cte'=>'dacte.pdf','mdfe'=>'damdfe.pdf',
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    public function xmlPath(FiscalDocumentJob $document): ?string
    {
        $type=(string)$document->document_type;
        $path=(string)$document->xml_path;

        if (!in_array($type,self::TYPES,true)
            || !in_array($document->status,['authorized','cancelled'],true)
            || $path==='' || str_contains($path,'..')) {
            return null;
        }

        $prefix='fiscal/'.$type.'/'.$document->id.'/';
        if (!str_starts_with($path,$prefix)
            || preg_match('/^fiscal\/[a-z]+\/[0-9]+\/[A-Za-z0-9_-]+\.xml$/',$path)!==1
            || ($type==='nfce' && !in_array($path,[
                $prefix.'authorized.xml',$prefix.'authorized-recovered.xml',
            ],true))
            || !Storage::disk('local')->exists($path)) {
            return null;
        }

        return $path;
    }

    public function auxiliaryPath(FiscalDocumentJob $document): ?string
    {
        $type=(string)$document->document_type;
        if (!in_array($type,self::TYPES,true)
            || !in_array($document->status,['authorized','cancelled'],true)) {
            return null;
        }

        $path='fiscal/'.$type.'/'.$document->id.'/'.self::AUXILIARY[$type];
        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public function canOpenAuxiliary(FiscalDocumentJob $document): bool
    {
        // A NFC-e dispõe de um DANFE real renderizado a partir do nfeProc.
        // Outros modelos apenas podem servir PDFs previamente gerados.
        return ($document->document_type==='nfce' && $this->xmlPath($document)!==null)
            || $this->auxiliaryPath($document)!==null;
    }

    public function filename(FiscalDocumentJob $document, string $extension): string
    {
        $name=self::LABELS[$document->document_type] ?? 'Fiscal';
        $series=(int)($document->series ?? 0);
        $number=(int)($document->document_number ?? $document->id);
        return $name.'-S'.$series.'-N'.$number.'-'.$document->id.'.'.$extension;
    }
}

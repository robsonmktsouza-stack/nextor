<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class BankStatementParser
{
    public function parse(UploadedFile $file): array
    {
        $ext=strtolower($file->getClientOriginalExtension());

        return match($ext) {
            'ofx'=>$this->parseOfx((string)file_get_contents($file->getRealPath())),
            'csv'=>$this->parseCsv((string)file_get_contents($file->getRealPath())),
            'xlsx'=>$this->parseXlsx($file->getRealPath()),
            default=>throw ValidationException::withMessages(['file'=>'Formato não suportado. Use OFX, CSV ou XLSX.']),
        };
    }

    private function parseOfx(string $raw): array
    {
        $rows=[];
        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>|<\/BANKTRANLIST>))/is',$raw,$blocks);

        foreach($blocks[1] ?? [] as $i=>$block) {
            $amount=$this->tag($block,'TRNAMT');
            $date=$this->dateValue($this->tag($block,'DTPOSTED'));
            if($amount===null || !$date) continue;

            $description=trim(implode(' - ',array_filter([$this->tag($block,'NAME'),$this->tag($block,'MEMO')])));
            $value=$this->decimal($amount);

            $rows[]=[
                'sequence'=>$i+1,
                'external_id'=>$this->tag($block,'FITID'),
                'transaction_date'=>$date,
                'amount'=>$value,
                'description'=>$description ?: 'Movimentação bancária',
                'transaction_type'=>$value>=0 ? 'credit' : 'debit',
            ];
        }

        return $this->finish($rows);
    }

    private function parseCsv(string $raw): array
    {
        $raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
        $lines=preg_split('/\R/',$raw,-1,PREG_SPLIT_NO_EMPTY);
        if(!$lines) return $this->finish([]);

        $delimiter=substr_count($lines[0],';')>=substr_count($lines[0],',') ? ';' : ',';
        $headers=array_map(fn($v)=>$this->normalize($v),str_getcsv(array_shift($lines),$delimiter));

        $find=function(array $names) use ($headers) {
            foreach($names as $name) {
                $idx=array_search($name,$headers,true);
                if($idx!==false) return $idx;
            }
            return null;
        };

        $dateIdx=$find(['data','date','datamovimento','lancamento']);
        $amountIdx=$find(['valor','amount','valortransacao']);
        $descIdx=$find(['descricao','description','historico','memo','detalhes']);
        $idIdx=$find(['id','fitid','documento','numero']);

        if($dateIdx===null || $amountIdx===null) {
            throw ValidationException::withMessages(['file'=>'CSV precisa conter colunas de data e valor.']);
        }

        $rows=[];
        foreach($lines as $i=>$line) {
            $cols=str_getcsv($line,$delimiter);
            $date=$this->dateValue($cols[$dateIdx] ?? null);
            if(!$date) continue;
            $value=$this->decimal($cols[$amountIdx] ?? '0');
            $rows[]=[
                'sequence'=>$i+1,
                'external_id'=>$idIdx!==null ? trim((string)($cols[$idIdx] ?? '')) : null,
                'transaction_date'=>$date,
                'amount'=>$value,
                'description'=>trim((string)($descIdx!==null ? ($cols[$descIdx] ?? '') : '')) ?: 'Movimentação bancária',
                'transaction_type'=>$value>=0 ? 'credit' : 'debit',
            ];
        }

        return $this->finish($rows);
    }

    private function parseXlsx(string $path): array
    {
        if(!class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file'=>'A extensão ZIP do PHP é necessária para importar XLSX.']);
        }

        $zip=new ZipArchive();
        if($zip->open($path)!==true) {
            throw ValidationException::withMessages(['file'=>'Não foi possível abrir o XLSX.']);
        }

        $shared=[];
        $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
        if($sharedXml!==false) {
            $xml=@simplexml_load_string($sharedXml);
            if($xml) foreach($xml->si as $si) $shared[]=trim((string)$si->t ?: implode('',array_map('strval',iterator_to_array($si->r->t ?? []))));
        }

        $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if($sheet===false) throw ValidationException::withMessages(['file'=>'Planilha XLSX sem primeira aba legível.']);

        $xml=@simplexml_load_string($sheet);
        if(!$xml) throw ValidationException::withMessages(['file'=>'XLSX inválido.']);

        $matrix=[];
        foreach($xml->sheetData->row as $row) {
            $values=[];
            foreach($row->c as $cell) {
                $ref=(string)$cell['r'];
                preg_match('/^[A-Z]+/',$ref,$m);
                $col=$this->columnIndex($m[0] ?? 'A');
                $value=(string)$cell->v;
                if((string)$cell['t']==='s') $value=$shared[(int)$value] ?? '';
                $values[$col]=$value;
            }
            ksort($values);
            $matrix[]=array_values($values);
        }

        if(!$matrix) return $this->finish([]);

        $headers=array_map(fn($v)=>$this->normalize((string)$v),array_shift($matrix));
        $dateIdx=$this->headerIndex($headers,['data','date','datamovimento','lancamento']);
        $amountIdx=$this->headerIndex($headers,['valor','amount','valortransacao']);
        $descIdx=$this->headerIndex($headers,['descricao','description','historico','memo','detalhes']);

        if($dateIdx===null || $amountIdx===null) {
            throw ValidationException::withMessages(['file'=>'XLSX precisa conter colunas de data e valor.']);
        }

        $rows=[];
        foreach($matrix as $i=>$cols) {
            $rawDate=$cols[$dateIdx] ?? null;
            if(is_numeric($rawDate)) $rawDate=date('Y-m-d',((int)$rawDate-25569)*86400);
            $date=$this->dateValue($rawDate);
            if(!$date) continue;
            $value=$this->decimal($cols[$amountIdx] ?? '0');
            $rows[]=[
                'sequence'=>$i+1,'external_id'=>null,'transaction_date'=>$date,'amount'=>$value,
                'description'=>trim((string)($descIdx!==null ? ($cols[$descIdx] ?? '') : '')) ?: 'Movimentação bancária',
                'transaction_type'=>$value>=0 ? 'credit' : 'debit',
            ];
        }

        return $this->finish($rows);
    }

    private function finish(array $rows): array
    {
        if(!$rows) throw ValidationException::withMessages(['file'=>'Nenhuma movimentação bancária foi encontrada no arquivo.']);
        $dates=array_column($rows,'transaction_date');
        sort($dates);
        return ['rows'=>$rows,'period_start'=>$dates[0] ?? null,'period_end'=>$dates[count($dates)-1] ?? null];
    }

    private function tag(string $block,string $tag): ?string
    {
        if(preg_match('/<'.$tag.'>([^<\r\n]*)/i',$block,$m)) return trim($m[1]);
        return null;
    }

    private function dateValue(?string $value): ?string
    {
        $value=trim((string)$value);
        if($value==='') return null;
        if(preg_match('/^(\d{4})(\d{2})(\d{2})/',$value,$m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        foreach(['d/m/Y','Y-m-d','m/d/Y'] as $format) {
            $date=\DateTime::createFromFormat($format,$value);
            if($date) return $date->format('Y-m-d');
        }
        return null;
    }

    private function decimal(mixed $value): float
    {
        $text=trim((string)$value);
        $text=preg_replace('/[^0-9,.-]/','',$text);
        if(str_contains($text,',') && str_contains($text,'.')) $text=str_replace('.','',$text);
        $text=str_replace(',','.',$text);
        return round((float)$text,2);
    }

    private function normalize(string $value): string
    {
        $value=iconv('UTF-8','ASCII//TRANSLIT',$value) ?: $value;
        return strtolower(preg_replace('/[^a-zA-Z0-9]/','',$value));
    }

    private function columnIndex(string $letters): int
    {
        $n=0;
        foreach(str_split($letters) as $letter) $n=$n*26+(ord($letter)-64);
        return max(0,$n-1);
    }

    private function headerIndex(array $headers,array $names): ?int
    {
        foreach($names as $name) {
            $idx=array_search($name,$headers,true);
            if($idx!==false) return $idx;
        }
        return null;
    }
}

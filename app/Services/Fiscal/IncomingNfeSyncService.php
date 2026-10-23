<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Models\IncomingNfeSyncState;
use App\Support\InstanceIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class IncomingNfeSyncService
{
    public function __construct(
        private readonly IncomingNfeParser $parser,
        private readonly IncomingNfeAcbrGateway $gateway,
    ) {}

    public function companyCnpj(): string
    {
        $cnpj=preg_replace('/\D/','',(string)CompanySetting::current()->document);
        if(!preg_match('/^\d{14}$/',$cnpj)) {
            throw ValidationException::withMessages([
                'document'=>'Configure o CNPJ da empresa antes de buscar notas de entrada.',
            ]);
        }
        InstanceIdentity::assertCompanyDocument($cnpj);
        return $cnpj;
    }

    public function state(): IncomingNfeSyncState
    {
        return IncomingNfeSyncState::query()->firstOrCreate(
            ['cnpj'=>$this->companyCnpj(),'environment'=>'production'],
            ['last_nsu'=>'000000000000000','max_nsu'=>'000000000000000'],
        );
    }

    public function isReady(): bool
    {
        $company=CompanySetting::current();
        return (bool)($company->certificate_path && $company->certificate_password
            && (!$company->certificate_expires_at || $company->certificate_expires_at->isFuture())
            && Storage::disk('local')->exists($company->certificate_path));
    }

    /**
     * Scheduled and interactive checks use the SAME lock/cursor/cooldown.
     * Do not make repeated requests when cStat=137 or 656.
     */
    public function synchronize(bool $scheduled=false): array
    {
        $company=CompanySetting::current();
        $state=$this->state();
        if($scheduled && !$state->auto_sync) return ['status'=>'disabled','imported'=>0];
        if($state->next_check_at && $state->next_check_at->isFuture()) {
            return ['status'=>'cooldown','imported'=>0,'next_check_at'=>$state->next_check_at];
        }
        $key='incoming-nfe:'.$state->cnpj;
        $lock=Cache::lock($key,360);
        if(!$lock->get()) return ['status'=>'busy','imported'=>0];
        try {
            $state->refresh();
            if($state->next_check_at && $state->next_check_at->isFuture()) {
                return ['status'=>'cooldown','imported'=>0,'next_check_at'=>$state->next_check_at];
            }
            if(!$this->isReady()) {
                throw new RuntimeException('Configure o certificado A1 da empresa em Ajustes → Fiscal.');
            }
            $raw=$this->gateway->fetch($company,$state->cnpj,(string)$state->last_nsu);
            $envelope=$this->parser->envelope($raw);
            $code=$envelope['status'];
            if(!in_array($code,['137','138'],true)) {
                $hours=$code==='656'?24:2;
                $state->update([
                    'last_checked_at'=>now(),'last_status'=>$code,
                    'last_error'=>$code==='656'
                        ? 'A SEFAZ limitou novas consultas. A próxima busca ocorrerá após o intervalo de segurança.'
                        : 'A SEFAZ não concluiu a consulta. O Lumeron tentará novamente.',
                    'next_check_at'=>now()->addHours($hours),
                ]);
                return ['status'=>'error','imported'=>0];
            }
            if(strcmp($envelope['last_nsu'],(string)$state->last_nsu)<0
                || strcmp($envelope['max_nsu'],$envelope['last_nsu'])<0) {
                throw new RuntimeException('O retorno da SEFAZ contém sequência NSU inconsistente.');
            }

            // Persist every recognized document before advancing the cursor.
            // Repeated NSUs and the same key are idempotent.
            $imported=0;
            foreach($envelope['documents'] as $row) {
                $key=$row['access_key'];
                $xml=$row['xml'];
                if($xml!==null) {
                    try {
                        $this->importXml($xml,$row['nsu']);
                        $imported++;
                        continue;
                    } catch(\InvalidArgumentException $exception) {
                        // Do not promote an XML of another model/CNPJ.
                        if(!preg_match('/^\d{44}$/',$key)) continue;
                    }
                }
                if(!preg_match('/^\d{44}$/',$key)) continue;
                $values=$row['values'];
                // Summary only. Do not change purchase status or overwrite a full XML.
                $summary=IncomingNfe::query()->firstOrNew(['access_key'=>$key]);
                if(!$summary->exists || $summary->status==='summary') {
                    $issuer=preg_replace('/\D/','',(string)($values['CNPJ']??$values['EmiCNPJ']??''));
                    $issued=$values['dhEmi']??$values['dEmi']??null;
                    $summary->fill([
                        'nsu'=>$this->nsu($row['nsu']),
                        'supplier_cnpj'=>preg_match('/^\d{14}$/',$issuer)?$issuer:null,
                        'supplier_name'=>mb_substr((string)($values['xNome']??$values['EmixNome']??''),0,190),
                        'issued_at'=>$this->date($issued),
                        'total'=>$this->money($values['vNF']??null),
                        'status'=>'summary',
                        'summary'=>['source'=>'sefaz','nsu'=>$this->nsu($row['nsu'])],
                        'received_at'=>now(),
                    ]);
                    $summary->save();
                    $imported++;
                }
            }
            $newCursor=$envelope['last_nsu'];
            $caughtUp=strcmp($newCursor,$envelope['max_nsu'])>=0;
            $state->update([
                'last_nsu'=>$newCursor,
                'max_nsu'=>$envelope['max_nsu'],
                'last_checked_at'=>now(),
                'last_status'=>$code,
                'last_error'=>null,
                'next_check_at'=>now()->addMinutes($caughtUp?65:5),
            ]);
            return ['status'=>'ok','imported'=>$imported,'next_check_at'=>$state->next_check_at];
        } catch(Throwable $error) {
            // Never expose the PFX password, detailed ACBr logs or remote XML in UI.
            $state->update([
                'last_checked_at'=>now(),
                'last_status'=>'error',
                'last_error'=>'Não foi possível concluir a busca. Confira o certificado e a conexão fiscal.',
                'next_check_at'=>now()->addMinutes(65),
            ]);
            report($error);
            return ['status'=>'error','imported'=>0];
        } finally {
            $lock->release();
        }
    }

    /** Import does NOT post stock, suppliers or invoices payable. */
    public function importXml(string $xml,?string $nsu=null): IncomingNfe
    {
        $detail=$this->parser->details($xml,$this->companyCnpj());
        $path='fiscal/incoming-nfe/'.$detail['access_key'].'.xml';
        $disk=Storage::disk('local');
        if(!$disk->put($path,$xml)) {
            throw new RuntimeException('Não foi possível guardar o XML da NF-e.');
        }
        return DB::transaction(function () use($detail,$path,$nsu) {
            $record=IncomingNfe::query()->where('access_key',$detail['access_key'])
                ->lockForUpdate()->first();
            if(!$record) $record=new IncomingNfe(['access_key'=>$detail['access_key']]);
            $record->fill([
                'supplier_cnpj'=>$detail['supplier_cnpj'],
                'supplier_name'=>$detail['supplier_name'],
                'issued_at'=>$this->date($detail['issued_at']),
                'total'=>$this->money($detail['total']),
                'item_count'=>$detail['item_count'],
                'items'=>$detail['items'],
                'summary'=>array_merge($detail['summary'],['source'=>'xml']),
                'status'=>'xml_received',
                'xml_path'=>$path,
                'received_at'=>now(),
            ]);
            if($nsu!==null) $record->nsu=$this->nsu($nsu);
            $record->save();
            return $record;
        },3);
    }

    private function nsu(?string $nsu): ?string
    {
        return $nsu!==null && preg_match('/^\d{1,15}$/',$nsu)
            ? str_pad($nsu,15,'0',STR_PAD_LEFT) : null;
    }

    private function money(mixed $value): ?string
    {
        if($value===null||$value==='') return null;
        $value=str_replace(',','.',(string)$value);
        return preg_match('/^\d{1,12}(?:\.\d{1,2})?$/',$value)?$value:null;
    }

    private function date(?string $date): ?string
    {
        if(!$date) return null;
        try {
            if(preg_match('/^\d{2}\/\d{2}\/\d{4}/',$date)) {
                $part=substr($date,0,19);
                $format=strlen($part)>10?'d/m/Y H:i:s':'d/m/Y';
                return Carbon::createFromFormat($format,$part)->format('Y-m-d H:i:s');
            }
            return Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch(Throwable) {
            return null;
        }
    }
}

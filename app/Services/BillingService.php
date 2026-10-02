<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FinancialEntry;
use Carbon\Carbon;

class BillingService
{
    public function enabled(): bool
    {
        return (bool)AppSetting::value('billing','enabled',false);
    }

    public function summary(FinancialEntry $entry): ?array
    {
        if(!$this->enabled() || $entry->type!=='receivable' || $entry->status==='cancelled') {
            return null;
        }

        $settings=AppSetting::groupValues('billing',[]);
        $balance=round((float)$entry->balance,2);
        if($balance<=0) return null;

        $daysLate=$entry->due_date && $entry->due_date->isBefore(today())
            ? $entry->due_date->diffInDays(today())
            : 0;

        $fineRate=max(0,(float)($settings['fine_percent'] ?? 0));
        $interestRate=max(0,(float)($settings['interest_monthly_percent'] ?? 0));

        $fine=$daysLate>0 ? round($balance*$fineRate/100,2) : 0.0;
        $interest=$daysLate>0 ? round($balance*($interestRate/100)*($daysLate/30),2) : 0.0;
        $charge=round($balance+$fine+$interest,2);

        $pixKey=trim((string)($settings['pix_key'] ?? ''));
        $pix=$pixKey!=='' ? $this->pixPayload($entry,$charge,$pixKey) : null;

        return [
            'balance'=>$balance,
            'days_late'=>$daysLate,
            'fine'=>$fine,
            'interest'=>$interest,
            'charge'=>$charge,
            'pix_key'=>$pixKey,
            'pix_payload'=>$pix,
            'instructions'=>$settings['instructions'] ?? null,
            'provider'=>$settings['provider'] ?? null,
            'default_financial_account_id'=>(int)($settings['default_financial_account_id'] ?? 0),
        ];
    }

    private function pixPayload(FinancialEntry $entry,float $amount,string $key): string
    {
        $company=CompanySetting::current();
        $name=$this->asciiUpper($company->trade_name ?: $company->legal_name ?: config('app.name','NEXTOR'),25);
        $city=$this->asciiUpper($company->city ?: 'SAO PAULO',15);
        $txid='NEXTOR'.str_pad((string)$entry->id,8,'0',STR_PAD_LEFT);

        $gui=$this->field('00','BR.GOV.BCB.PIX');
        $merchantAccount=$gui.$this->field('01',$key);

        $payload=
            $this->field('00','01').
            $this->field('26',$merchantAccount).
            $this->field('52','0000').
            $this->field('53','986').
            $this->field('54',number_format($amount,2,'.','')).
            $this->field('58','BR').
            $this->field('59',$name).
            $this->field('60',$city).
            $this->field('62',$this->field('05',$txid)).
            '6304';

        return $payload.$this->crc16($payload);
    }

    private function field(string $id,string $value): string
    {
        $value=(string)$value;
        return $id.str_pad((string)strlen($value),2,'0',STR_PAD_LEFT).$value;
    }

    private function asciiUpper(string $value,int $max): string
    {
        $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value) ?: $value;
        $value=strtoupper(preg_replace('/[^A-Z0-9 .-]/i','',trim($value)) ?? '');
        return mb_substr($value,0,$max);
    }

    private function crc16(string $payload): string
    {
        $crc=0xFFFF;
        $polynomial=0x1021;

        foreach(str_split($payload) as $char) {
            $crc ^= ord($char) << 8;
            for($i=0;$i<8;$i++) {
                $crc=(($crc & 0x8000)!==0)
                    ? (($crc << 1) ^ $polynomial)
                    : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc),4,'0',STR_PAD_LEFT));
    }
}

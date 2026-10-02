<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ProcessWebhookDeliveries extends Command
{
    protected $signature='nextor:webhooks {--limit=25}';
    protected $description='Envia webhooks pendentes do Nextor.';

    public function handle(): int
    {
        $limit=max(1,min(100,(int)$this->option('limit')));
        $secret=(string)AppSetting::value('integrations','webhook_secret','');

        $deliveries=WebhookDelivery::query()
            ->whereIn('status',['pending','retry'])
            ->where(function($q){
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach($deliveries as $delivery) {
            $delivery->increment('attempts');

            try {
                $body=[
                    'event'=>$delivery->event,
                    'delivery_id'=>$delivery->id,
                    'occurred_at'=>$delivery->created_at?->toIso8601String(),
                    'data'=>$delivery->payload,
                ];

                $request=Http::acceptJson()
                    ->asJson()
                    ->connectTimeout(2)
                    ->timeout(5)
                    ->withHeaders([
                        'X-Nextor-Event'=>$delivery->event,
                        'X-Nextor-Delivery'=>(string)$delivery->id,
                    ]);

                if($secret!=='') {
                    $json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    $request=$request->withHeaders([
                        'X-Nextor-Signature'=>'sha256='.hash_hmac('sha256',$json,$secret),
                    ]);
                }

                $response=$request->post($delivery->url,$body);

                if($response->successful()) {
                    $delivery->update([
                        'status'=>'delivered',
                        'response_code'=>$response->status(),
                        'last_error'=>null,
                        'delivered_at'=>now(),
                        'next_attempt_at'=>null,
                    ]);
                } else {
                    $this->retry($delivery,'HTTP '.$response->status(),$response->status());
                }
            } catch (\Throwable $e) {
                $this->retry($delivery,$e->getMessage(),null);
            }
        }

        return self::SUCCESS;
    }

    private function retry(WebhookDelivery $delivery,string $error,?int $status): void
    {
        $attempts=(int)$delivery->attempts;
        $failed=$attempts>=5;

        $delivery->update([
            'status'=>$failed ? 'failed' : 'retry',
            'response_code'=>$status,
            'last_error'=>mb_substr($error,0,2000),
            'next_attempt_at'=>$failed ? null : now()->addMinutes(min(60,2 ** $attempts)),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\WebhookDelivery;

class WebhookService
{
    public function queue(string $event,array $payload): void
    {
        if(!(bool)AppSetting::value('integrations','api_enabled',false)) return;

        $url=trim((string)AppSetting::value('integrations','webhook_url',''));
        if($url==='') return;

        WebhookDelivery::query()->create([
            'event'=>$event,
            'url'=>$url,
            'payload'=>$payload,
            'status'=>'pending',
            'attempts'=>0,
            'next_attempt_at'=>now(),
        ]);
    }
}

<?php
namespace App\Providers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        try {
            if(Schema::hasTable('company_settings')) {
                $company=CompanySetting::query()->first();
                if($company?->timezone) {
                    config(['app.timezone'=>$company->timezone]);
                    date_default_timezone_set($company->timezone);
                }
            }

            if(!Schema::hasTable('app_settings')) return;

            $smtp=AppSetting::groupValues('integrations',[
                'smtp_enabled'=>false,
                'smtp_host'=>null,
                'smtp_port'=>587,
                'smtp_encryption'=>'tls',
                'smtp_username'=>null,
                'smtp_password'=>null,
                'smtp_from_address'=>null,
                'smtp_from_name'=>null,
            ]);

            if((bool)$smtp['smtp_enabled'] && filled($smtp['smtp_host'])) {
                $scheme=$smtp['smtp_encryption']==='ssl' ? 'smtps' : 'smtp';

                config([
                    'mail.default'=>'smtp',
                    'mail.mailers.smtp.scheme'=>$scheme,
                    'mail.mailers.smtp.host'=>$smtp['smtp_host'],
                    'mail.mailers.smtp.port'=>(int)$smtp['smtp_port'],
                    'mail.mailers.smtp.username'=>$smtp['smtp_username'] ?: null,
                    'mail.mailers.smtp.password'=>$smtp['smtp_password'] ?: null,
                ]);

                if(filled($smtp['smtp_from_address'])) {
                    config(['mail.from.address'=>$smtp['smtp_from_address']]);
                }
                if(filled($smtp['smtp_from_name'])) {
                    config(['mail.from.name'=>$smtp['smtp_from_name']]);
                }
            }
        } catch (\Throwable $exception) {
            // O sistema precisa continuar inicializando durante deploy/migrations.
            report($exception);
        }
    }
}

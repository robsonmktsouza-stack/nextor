<?php

use App\Models\User;
use App\Models\ControlSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schedule;

Schedule::command('control:automations-run')->dailyAt(ControlSetting::schedule('automations_at','08:00'))->withoutOverlapping();
if (app()->environment('local') && config('managed_provisioning.local_enabled')) {
    Schedule::command('control:provision-local --all')->everyMinute()->withoutOverlapping();
}
Schedule::command('control:invoices-prepare')->dailyAt(ControlSetting::schedule('invoices_at','08:05'))->withoutOverlapping();
Schedule::command('control:client-email-send')->everyTenMinutes()
    ->between(ControlSetting::schedule('emails_start_at','08:10'),
        ControlSetting::schedule('emails_end_at','18:00'))->withoutOverlapping();

Artisan::command('control:admin {email} {--name=Administrador}', function (): int {
    $email = strtolower(trim((string) $this->argument('email')));
    $name = trim((string) $this->option('name'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
        $this->error('Informe email válido e nome.');
        return 1;
    }
    $password = (string) $this->secret('Senha de pelo menos 12 caracteres para o painel:');
    if (Str::length($password) < 12) {
        $this->error('Senha muito curta. Nenhuma conta foi criada.');
        return 1;
    }
    User::updateOrCreate(['email' => $email], [
        'name' => $name,
        'password' => Hash::make($password),
    ]);
    $this->info('Administrador criado/atualizado com sucesso. Guarde a senha com segurança.');
    return 0;
})->purpose('Cadastrar administrador do painel de licenças sem registro público');

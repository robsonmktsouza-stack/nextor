<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Trusted on-server provisioning only. No HTTP route, static password,
 * shared user database or printable invitation secrets.
 */
final class BootstrapManagedAccount extends Command
{
    protected $signature = 'erp:bootstrap-managed
        {--cnpj= : CNPJ already authorized by the Control}
        {--company-name= : Razão social}
        {--admin-name= : Nome do administrador}
        {--admin-email= : E-mail de primeiro acesso}
        {--admin-login=admin : Usuário inicial}
        {--local : Preparação exclusivamente para Laragon/ambiente local}';

    protected $description = 'Prepara identidade e convite de primeiro acesso em instalação recém-provisionada.';

    public function handle(): int
    {
        $data = [
            'cnpj' => trim((string) $this->option('cnpj')),
            'company_name' => trim((string) $this->option('company-name')),
            'name' => trim((string) $this->option('admin-name')),
            'email' => strtolower(trim((string) $this->option('admin-email'))),
            'username' => strtolower(trim((string) $this->option('admin-login'))),
        ];
        $validation = Validator::make($data, [
            'cnpj' => ['required', 'regex:/^[0-9]{14}$/'],
            'company_name' => ['required', 'string', 'min:3', 'max:220'],
            'name' => ['required', 'string', 'min:2', 'max:190'],
            'email' => ['required', 'email', 'max:255'],
            'username' => ['required','string','min:3','max:50',
                'regex:/^[a-z][a-z0-9._-]*$/'],
        ]);
        $expected = (string) config('instance.cnpj', '');
        $subdomain = (string) config('instance.subdomain', '');
        $domain = (string) config('instance.base_domain', '');
        $local = (bool) $this->option('local');
        $expectedUrl = $local ? 'http://lumeron-'.$subdomain.'.test'
            : 'https://'.$subdomain.'.'.$domain;
        if ($local && !app()->environment('local', 'testing')) {
            $this->error('Primeiro acesso local indisponível fora de desenvolvimento.');
            return self::FAILURE;
        }
        if ($validation->fails() || $data['cnpj'] !== $expected
            || !config('instance.id') || !\App\Support\LoginSubdomain::valid($subdomain)
            || rtrim((string) config('app.url'), '/') !== $expectedUrl) {
            $this->error('Identidade empresarial ou endereço da instalação inválido.');
            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($data): void {
                $setting = CompanySetting::query()->lockForUpdate()->first();
                if ($setting && $setting->document
                    && preg_replace('/\D/', '', $setting->document) !== $data['cnpj']) {
                    throw new \RuntimeException('CNPJ diferente do já configurado.');
                }
                $setting = $setting ?? new CompanySetting();
                $setting->fill([
                    'document' => $data['cnpj'],
                    'legal_name' => $data['company_name'],
                    'email' => $data['email'],
                ]);
                $setting->save();

                $existing = User::query()->lockForUpdate()->first();
                if ($existing) {
                    if (User::query()->count() !== 1
                        || $existing->email !== $data['email']
                        || $existing->username !== $data['username']) {
                        throw new \RuntimeException('Instalação com usuários anteriores.');
                    }
                } else {
                    User::query()->create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'username' => $data['username'],
                        'password' => Hash::make(Str::random(60)),
                        'role' => 'admin',
                        'is_active' => true,
                    ]);
                }
            });
            $user = User::query()->where('email', $data['email'])->firstOrFail();
            $token = Password::broker()->createToken($user);
            $invite = [
                'email' => $data['email'],
                // Explicitly use the installation's validated APP_URL.
                // CLI tests and reverse proxies must never leak the
                // control-server or localhost origin into the invitation.
                'url' => $expectedUrl.route('password.reset', [
                    'token' => $token, 'email' => $data['email'],
                ], false),
            ];
            $folder = storage_path('app/private');
            if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
                throw new \RuntimeException('Diretório privado indisponível.');
            }
            $destination = $folder.'/managed-first-access.json';
            if (is_link($destination)) {
                throw new \RuntimeException('Arquivo privado inseguro.');
            }
            $temp = tempnam($folder, '.managed-invite-');
            if ($temp === false) throw new \RuntimeException('Convite temporário indisponível.');
            try {
                chmod($temp, 0600);
                file_put_contents($temp, json_encode($invite, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
                if (!rename($temp, $destination)) {
                    throw new \RuntimeException('Falha ao preparar o convite privado.');
                }
                chmod($destination, 0600);
            } finally {
                if (is_file($temp)) @unlink($temp);
            }
            $this->info('Empresa e administrador preparados. Convite guardado em arquivo privado.');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Não foi possível preparar o primeiro acesso desta instalação.');
            return self::FAILURE;
        }
    }
}

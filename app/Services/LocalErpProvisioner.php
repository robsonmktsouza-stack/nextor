<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Laragon development only. Every CNPJ gets a fresh Laravel installation,
 * its own SQLite file, APP_KEY, storage and session cookie.
 * Never call from HTTP or production; the scheduled/CLI worker owns this.
 */
final class LocalErpProvisioner
{
    public function preview(Company $company): array
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('O provisionamento local não está disponível neste ambiente.');
        }
        $slug = $company->subdomain;
        if (!is_string($slug) || !preg_match('/^[a-z][a-z0-9-]{1,38}$/', $slug)
            || str_contains($slug, '--')
            || !preg_match('/^\d{14}$/', (string) $company->cnpj)) {
            throw new \RuntimeException('Identificação da empresa inválida.');
        }
        $login = strtolower(trim((string) ($company->admin_login ?: 'admin')));
        if (!preg_match('/^[a-z][a-z0-9._-]{2,49}$/', $login)) {
            throw new \RuntimeException('O login de referência precisa começar com letra.');
        }
        if (!filter_var($company->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A empresa precisa ter e-mail válido.');
        }

        $control = realpath(base_path());
        $root = $control ? dirname($control) : false;
        $source = realpath((string) (config('managed_provisioning.local_source')
            ?: ($root.DIRECTORY_SEPARATOR.'nextor')));
        if (!$control || !$root || !$source
            || $source === $control
            || dirname($source) !== $root
            || !is_file($source.'/artisan')
            || !is_file($source.'/composer.lock')
            || !is_file($source.'/app/Console/Commands/BootstrapManagedAccount.php')
            || !is_dir($source.'/.git')) {
            throw new \RuntimeException('Projeto ERP (develop) não encontrado ao lado do Control.');
        }

        $folder = 'lumeron-'.$slug;
        $destination = $root.DIRECTORY_SEPARATOR.$folder;
        if (is_link($destination) || str_contains($folder, '..')) {
            throw new \RuntimeException('Diretório da instalação inseguro.');
        }

        $url = 'http://'.$folder.'.test';
        $controlUrl = rtrim((string) (config('managed_provisioning.local_control_url')
            ?: config('app.url')), '/');
        $parts = parse_url($controlUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
            || !in_array(strtolower((string) ($parts['host'] ?? '')),
                ['localhost','127.0.0.1','::1'], true)) {
            throw new \RuntimeException('Defina o endereço local do Control (localhost/127.0.0.1).');
        }

        return compact('source', 'destination', 'url', 'controlUrl', 'login');
    }

    public function provision(Company $company): string
    {
        $paths = $this->preview($company);
        if ($company->currentStatus() !== 'active') {
            throw new \RuntimeException('A licença da empresa não está ativa.');
        }
        ['source'=>$source,'destination'=>$destination,
            'url'=>$url,'controlUrl'=>$controlUrl,'login'=>$login] = $paths;
        if (!is_dir($destination)) {
            $this->copyCleanTrackedRelease($source, $destination, $company);
        } else {
            $marker = $destination.'/.lumeron-local-identity.json';
            if (is_link($marker) || !is_file($marker)) {
                throw new \RuntimeException('Já existe um projeto neste endereço. Não será sobrescrito.');
            }
            $info = json_decode((string) file_get_contents($marker), true);
            if (!is_array($info) || $info['cnpj'] !== $company->cnpj
                || $info['instance_id'] !== $company->instance_id) {
                throw new \RuntimeException('A pasta pertence a outra empresa.');
            }
        }
        $this->execute(['composer', 'install', '--no-interaction',
            '--prefer-dist', '--no-progress'], $destination, 900);
        $this->execute([PHP_BINARY, 'artisan', 'migrate', '--force'], $destination, 600);
        $this->execute([PHP_BINARY, 'artisan', 'erp:bootstrap-managed',
            '--cnpj='.$company->cnpj, '--company-name='.$company->legal_name,
            '--admin-name='.($company->contact_name ?: $company->legal_name),
            '--admin-email='.$company->email, '--admin-login='.$login,
            '--local'], $destination, 90);

        // Existing HMAC binder derives the issuer CNPJ from the ERP's own DB.
        // It verifies Control connectivity and the signed license response.
        $this->execute([PHP_BINARY, 'artisan', 'control:bind-local',
            $destination, '--url='.$controlUrl], base_path(), 90);

        // Laragon auto virtual hosts serve a project's /public directory.
        // Never advertise access until the actual project responds.
        try {
            $result = Http::connectTimeout(2)->timeout(6)->get($url.'/up');
            if (!$result->successful()) {
                throw new \RuntimeException('ERP não respondeu corretamente.');
            }
            $sensitive = Http::connectTimeout(2)->timeout(5)->get($url.'/.env');
            if ($sensitive->successful()
                && str_contains((string) $sensitive->body(), 'APP_KEY=')) {
                throw new \RuntimeException('Virtual host não aponta para a pasta public.');
            }
        } catch (\Throwable) {
            throw new \RuntimeException(
                'Endereço local indisponível. Confira o Auto Virtual Hosts do Laragon e recarregue o Apache.'
            );
        }

        $invite = $destination.'/storage/app/private/managed-first-access.json';
        if (!is_file($invite) || is_link($invite)) {
            throw new \RuntimeException('Convite privado de primeiro acesso não encontrado.');
        }
        $data = json_decode((string) file_get_contents($invite), true);
        if (!is_array($data) || ($data['email'] ?? '') !== strtolower($company->email)
            || !str_starts_with((string) ($data['url'] ?? ''), $url.'/reset-password/')) {
            throw new \RuntimeException('Convite de acesso incompatível com a empresa.');
        }
        return $url;
    }

    private function copyCleanTrackedRelease(string $source, string $target, Company $company): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Habilite a extensão zip do PHP para copiar o ERP.');
        }
        $branch = $this->execute(['git', '-C', $source, 'branch',
            '--show-current'], $source, 25);
        if (trim($branch) !== 'develop') {
            throw new \RuntimeException('O ERP de origem precisa estar na branch develop.');
        }
        $zip = tempnam(sys_get_temp_dir(), 'lumeron-erp-');
        if (!$zip) throw new \RuntimeException('Não foi possível criar pacote de instalação.');
        try {
            $this->execute(['git', '-C', $source, 'archive', '--format=zip',
                '--output='.$zip, 'HEAD'], $source, 90);
            if (!mkdir($target, 0700, false) && !is_dir($target)) {
                throw new \RuntimeException('Não foi possível criar a pasta do cliente.');
            }
            $archive = new \ZipArchive();
            if ($archive->open($zip) !== true) {
                throw new \RuntimeException('Pacote do ERP inválido.');
            }
            try {
                for ($i = 0; $i < $archive->numFiles; $i++) {
                    $name = $archive->getNameIndex($i);
                    if (!is_string($name) || str_contains($name, '..')
                        || str_contains($name, '\\')
                        || str_starts_with($name, '/')
                        || preg_match('#(^|/)(\.env|database\.sqlite|\.git)(/|$)#', $name)) {
                        throw new \RuntimeException('Pacote contém arquivo proibido.');
                    }
                }
                if (!$archive->extractTo($target)) {
                    throw new \RuntimeException('Falha na extração do ERP.');
                }
            } finally {
                $archive->close();
            }
            foreach (['database', 'storage/app/private',
                'storage/framework/cache/data', 'storage/framework/sessions',
                'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
                if (!is_dir($target.'/'.$dir)
                    && !mkdir($target.'/'.$dir, 0700, true) && !is_dir($target.'/'.$dir)) {
                    throw new \RuntimeException('Falha ao preparar diretório privado.');
                }
            }
            $db = str_replace('\\', '/', $target.'/database/database.sqlite');
            if (file_put_contents($db, '', LOCK_EX) === false) {
                throw new \RuntimeException('Falha ao criar banco isolado.');
            }
            $env = [
                'APP_NAME' => 'Lumeron ERP',
                'APP_ENV' => 'local',
                'APP_DEBUG' => 'true',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_URL' => 'http://'.'lumeron-'.$company->subdomain.'.test',
                'APP_TIMEZONE' => 'America/Sao_Paulo',
                'APP_LOCALE' => 'pt_BR',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $db,
                'SESSION_DRIVER' => 'file',
                'SESSION_COOKIE' => 'lumeron_'.$company->id,
                'CACHE_STORE' => 'file',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'log',
                'LUMERON_INSTANCE_ID' => 'c_'.$company->id,
                'LUMERON_INSTANCE_CNPJ' => $company->cnpj,
                'LUMERON_INSTANCE_SUBDOMAIN' => $company->subdomain,
                'LUMERON_BASE_DOMAIN' => 'lumeron.com.br',
            ];
            $pairs = [];
            foreach ($env as $key => $value) {
                $pairs[] = $key.'="'.str_replace(
                    ['\\', '"', "\r", "\n"], ['\\\\','\\"','',''], (string) $value).'"';
            }
            file_put_contents($target.'/.env', implode("\n", $pairs)."\n", LOCK_EX);
            file_put_contents($target.'/.lumeron-local-identity.json',
                json_encode(['cnpj'=>$company->cnpj,
                    'instance_id'=>$company->instance_id], JSON_THROW_ON_ERROR), LOCK_EX);
        } finally {
            @unlink($zip);
        }
    }

    private function execute(array $args, string $directory, int $timeout): string
    {
        // Never let the child ERP inherit the Control's DB, APP_KEY or session
        // environment. The ERP must load ONLY its own .env and DB.
        $env = [];
        $vars = array_unique(array_merge(array_keys($_ENV), array_keys($_SERVER),
            array_keys(getenv() ?: [])));
        foreach ($vars as $key) {
            if (is_string($key)
                && preg_match('/^(APP_|DB_|DATABASE_URL$|CACHE_|SESSION_|QUEUE_|REDIS_|LUMERON_|NEXTOR_|LOG_|MAIL_|FILESYSTEM_|BROADCAST_|VITE_|ACBR_|LARAVEL_|JWT_|AWS_)/i', $key)) {
                $env[$key] = false;
            }
        }
        $process = new Process($args, $directory, $env);
        $process->setTimeout($timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Falha ao preparar a instalação ERP. Verifique o terminal do Laragon.');
        }
        return $process->getOutput();
    }
}

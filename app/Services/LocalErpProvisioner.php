<?php

namespace App\Services;

use App\Models\Company;
use App\Support\Subdomain;
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
        if (!is_string($slug) || !Subdomain::valid($slug)
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

    public function provision(Company $company, ?callable $progress = null): string
    {
        $paths = $this->preview($company);
        if ($company->currentStatus() !== 'active') {
            throw new \RuntimeException('A licença da empresa não está ativa.');
        }
        ['source'=>$source,'destination'=>$destination,
            'url'=>$url,'controlUrl'=>$controlUrl,'login'=>$login] = $paths;
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException(
                'O PHP do Laragon está sem pdo_sqlite. Ative pdo_sqlite e sqlite3 no menu PHP > Extensions do Laragon.'
            );
        }
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('Ative a extensão zip do PHP no Laragon.');
        }

        $progress && $progress('Conferindo pasta exclusiva da empresa...');
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
        $progress && $progress('Instalando dependências do ERP com Composer...');
        $composer = self::composerCommandForPlatform(PHP_OS_FAMILY);
        // On Windows Composer is normally composer.bat, not composer.exe.
        // It must be resolved by cmd.exe/PATHEXT rather than proc_open
        // attempting to execute a missing native composer executable.
        $this->execute($composer, $destination, 900, 'composer');

        $progress && $progress('Criando tabelas no banco exclusivo da empresa...');
        $this->execute([PHP_BINARY, 'artisan', 'migrate', '--force'],
            $destination, 600, 'migrations');

        $progress && $progress('Criando administrador e convite de primeiro acesso...');
        $this->execute([PHP_BINARY, 'artisan', 'erp:bootstrap-managed',
            '--cnpj='.$company->cnpj, '--company-name='.$company->legal_name,
            '--admin-name='.($company->contact_name ?: $company->legal_name),
            '--admin-email='.$company->email, '--admin-login='.$login,
            '--local'], $destination, 90, 'first-access');

        // Existing HMAC binder derives the issuer CNPJ from the ERP's own DB.
        // It verifies Control connectivity and the signed license response.
        $progress && $progress('Vinculando licença ao Lumeron Control...');
        $this->execute([PHP_BINARY, 'artisan', 'control:bind-local',
            $destination, '--url='.$controlUrl], base_path(), 90, 'license-binding');

        return $this->verifyPrepared($company, $progress);
    }

    /**
     * Resume only the last verification after Laragon's Reload, without
     * reinstalling Composer dependencies or rerunning the database setup.
     */
    public function verifyPrepared(Company $company, ?callable $progress = null): string
    {
        $paths = $this->preview($company);
        $destination = $paths['destination'];
        $url = $paths['url'];

        $marker = $destination.'/.lumeron-local-identity.json';
        if (is_link($destination) || is_link($marker) || !is_file($marker)) {
            throw new \RuntimeException('Esta empresa ainda não possui uma instalação local identificada.');
        }
        $identity = json_decode((string) file_get_contents($marker), true);
        if (!is_array($identity)
            || ($identity['cnpj'] ?? null) !== $company->cnpj
            || ($identity['instance_id'] ?? null) !== $company->instance_id) {
            throw new \RuntimeException('Esta pasta pertence a outra empresa. Nenhum dado foi alterado.');
        }
        if (!is_file($destination.'/database/database.sqlite')
            || !is_file($destination.'/.env')) {
            throw new \RuntimeException('O banco ou a configuração individual da empresa não foi criado.');
        }
        $bindingPath = $destination.'/storage/app/private/lumeron-control.json';
        if (is_link($bindingPath) || !is_file($bindingPath)) {
            throw new \RuntimeException('A licença da instalação ainda não foi vinculada ao Control.');
        }
        $binding = json_decode((string) file_get_contents($bindingPath), true);
        if (!is_array($binding)
            || ($binding['cnpj'] ?? null) !== $company->cnpj
            || ($binding['instance_id'] ?? null) !== $company->instance_id) {
            throw new \RuntimeException('Vínculo de licença incompatível com esta empresa.');
        }

        $invite = $destination.'/storage/app/private/managed-first-access.json';
        if (is_link($invite) || !is_file($invite)) {
            throw new \RuntimeException('O convite privado de primeiro acesso não foi preparado.');
        }
        $data = json_decode((string) file_get_contents($invite), true);
        if (!is_array($data) || ($data['email'] ?? '') !== strtolower($company->email)
            || !str_starts_with((string) ($data['url'] ?? ''), $url.'/reset-password/')) {
            throw new \RuntimeException('Convite de acesso incompatível com a empresa.');
        }

        $progress && $progress('Verificando o endereço local e a proteção dos arquivos...');
        try {
            $response = Http::connectTimeout(2)->timeout(7)->get($url.'/up');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host !== '' && gethostbyname($host) === $host) {
                throw new \RuntimeException(
                    'O Windows não reconhece '.$host.'. No Laragon, habilite Auto Virtual Hosts e clique Reload. Autorize a atualização do arquivo hosts do Windows.'
                );
            }
            throw new \RuntimeException(
                'O endereço '.$host.' foi encontrado, mas o servidor não respondeu. Confira Apache/Nginx, porta e Laragon Reload.'
            );
        }
        if (!$response->successful()) {
            throw new \RuntimeException(
                'O ERP respondeu HTTP '.$response->status().' em /up. Confira o Virtual Host do Laragon (raiz public) e o log do Laravel dessa instalação.'
            );
        }

        try {
            $sensitive = Http::connectTimeout(2)->timeout(7)->get($url.'/.env');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new \RuntimeException('Não foi possível confirmar que o arquivo .env está protegido no Virtual Host.');
        }
        if ($sensitive->successful()
            && str_contains((string) $sensitive->body(), 'APP_KEY=')) {
            throw new \RuntimeException(
                'Atenção: o endereço está expondo .env! Corrija a raiz do Virtual Host para a pasta public; acesso não será liberado.'
            );
        }

        $progress && $progress('Instalação validada.');
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

    public static function composerCommandForPlatform(string $family): array
    {
        $args = ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'];
        return $family === 'Windows'
            ? ['cmd.exe', '/d', '/c', ...$args]
            : $args;
    }

    private function execute(array $args, string $directory, int $timeout, string $stage = 'archive'): string
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

        try {
            $process = new Process($args, $directory, $env);
            $process->setTimeout($timeout);
            $process->run();
            if ($process->isSuccessful()) {
                return $process->getOutput();
            }
            $description = $this->failureHint(
                $stage, $process->getOutput()."\n".$process->getErrorOutput()
            );
            throw new \RuntimeException($description);
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
            throw new \RuntimeException(
                $this->failureHint($stage, $e->getMessage()), 0, $e
            );
        }
    }

    /**
     * Publish only a small curated diagnosis. Never print environment files,
     * raw subprocess stdout/stderr, connection strings or reset tokens.
     */
    public function failureHint(string $stage, string $output): string
    {
        $text = strtolower($output);
        if (str_contains($text, 'could not find driver')
            || str_contains($text, 'pdo_sqlite')
            || str_contains($text, 'sqlite3 extension')) {
            return 'Etapa banco de dados: habilite pdo_sqlite e sqlite3 no PHP do Laragon.';
        }
        if (str_contains($text, 'composer') && (
            str_contains($text, 'not recognized')
            || str_contains($text, 'não é reconhecido')
            || str_contains($text, 'cannot find the file')
            || str_contains($text, 'não pode encontrar o arquivo')
        )) {
            return 'Etapa Composer: o executável composer não foi encontrado no PATH do Laragon.';
        }
        if (preg_match('/(?:requires? (?:ext-|php extension )|missing ext-)([a-z0-9_]+)/i',
            $output, $matches)) {
            return 'Etapa Composer: habilite a extensão PHP '.$matches[1].' no Laragon.';
        }
        if (preg_match('/(?:requires php |your php version \\()(\\^?[0-9.]+)/i',
            $output, $matches)) {
            return 'Etapa Composer: versão de PHP incompatível. Verifique php -v no Laragon.';
        }
        if (str_contains($text, 'zip extension') || str_contains($text, 'ziparchive')) {
            return 'Etapa Composer: habilite a extensão zip do PHP no Laragon.';
        }
        if (str_contains($text, 'unable to download')
            || str_contains($text, 'curl error')) {
            return 'Etapa Composer: falha ao baixar dependências. Confira rede, certificados e conexão.';
        }
        if ($stage === 'license-binding') {
            if (str_contains($text, 'não está acessível')
                || str_contains($text, 'não respondeu')) {
                return 'Etapa vínculo: o Control não está acessível. Confira o php artisan serve e LUMERON_LOCAL_CONTROL_URL.';
            }
            if (str_contains($text, 'cnpj') || str_contains($text, 'identificar')) {
                return 'Etapa vínculo: CNPJ não pôde ser confirmado no banco exclusivo do ERP.';
            }
            return 'Etapa vínculo: a licença não foi confirmada. Confira se o Control está rodando na URL configurada.';
        }
        if ($stage === 'archive') {
            return 'Etapa cópia: git archive não conseguiu preparar os arquivos do ERP. Confira git, branch develop e permissões.';
        }
        if ($stage === 'composer') {
            return 'Etapa Composer: instalação das dependências falhou. Execute composer install na pasta exclusiva da empresa para ver o erro.';
        }
        if ($stage === 'migrations') {
            return 'Etapa banco: as migrations falharam no SQLite exclusivo. Execute php artisan migrate --force na pasta exclusiva para identificar a mensagem.';
        }
        if ($stage === 'first-access') {
            return 'Etapa primeiro acesso: o ERP não conseguiu criar o administrador/convite no banco exclusivo.';
        }
        return 'Etapa '.$stage.': preparação não concluída.';
    }
}

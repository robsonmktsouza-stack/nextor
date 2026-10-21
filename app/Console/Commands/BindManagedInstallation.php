<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Executed by the trusted deployment operator, not by customer ERP users.
 * Identifies an existing instance by reading only its company CNPJ and
 * installs a private HMAC binding without ever displaying the secret.
 */
final class BindManagedInstallation extends Command
{
    protected $signature = 'control:bind-local
        {erp-path : Diretório do Nextor já instalado}
        {--url= : Endereço interno do Lumeron Control acessível pela instalação}';

    protected $description = 'Vincula uma instalação ERP existente automaticamente, sem copiar licença ou alterar .env.';

    public function handle(): int
    {
        $folder = realpath((string) $this->argument('erp-path'));
        if (!$folder || !is_dir($folder)
            || !is_file($folder.DIRECTORY_SEPARATOR.'artisan')
            || !is_file($folder.'/config/central_license.php')
            || !is_file($folder.'/app/Console/Commands/CentralLicenseIdentity.php')
            || !is_file($folder.'/.env')) {
            $this->error('Instalação ERP inexistente ou desatualizada. Atualize primeiro a branch develop do Nextor.');
            return self::FAILURE;
        }

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https'
            && !($scheme === 'http' && app()->environment('local', 'testing')
                && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            $this->error('Use HTTPS para o painel remoto; HTTP somente em localhost durante testes.');
            return self::FAILURE;
        }

        $proc = new Process([PHP_BINARY, 'artisan', 'lumeron:license-identity'], $folder);
        $proc->setTimeout(30);
        $proc->run();
        $cnpj = trim($proc->getOutput());
        if (!$proc->isSuccessful() || !preg_match('/^[0-9]{14}$/', $cnpj)) {
            $this->error('Não foi possível identificar com segurança a empresa configurada no Nextor.');

            // Only forward diagnostics explicitly authored by our ERP CLI;
            // never dump arbitrary PHP errors or environment details.
            $output = $proc->getErrorOutput()."\n".$proc->getOutput();
            foreach ([
                'Cadastro da empresa indisponível neste banco.',
                'Migração de licenciamento ainda não aplicada neste banco',
                'Dados da empresa não foram cadastrados em Configurações',
                'O CNPJ da própria empresa está vazio',
                'O documento em Configurações',
                'O CNPJ cadastrado em Configurações',
            ] as $hint) {
                if (str_contains($output, $hint)) {
                    $this->warn(trim(preg_replace('/\\s+/', ' ', $output)));
                    break;
                }
            }
            $this->line('No terminal do Nextor, execute: php artisan lumeron:license-identity');
            return self::FAILURE;
        }

        $company = Company::query()->where('cnpj', $cnpj)->first();
        if (!$company) {
            $this->error('Esta empresa não está cadastrada no Lumeron Control. Cadastre-a primeiro no painel.');
            return self::FAILURE;
        }

        try {
            if (!Http::connectTimeout(2)->timeout(5)->get($url.'/up')->successful()) {
                throw new \RuntimeException('Health check retornou erro.');
            }
        } catch (\Throwable) {
            $this->error('O Lumeron Control não está acessível nesse endereço. Nenhuma licença foi vinculada.');
            return self::FAILURE;
        }

        $secret = $company->license_secret;
        $payload = [
            'instance_id' => $company->instance_id,
            'cnpj' => $company->cnpj,
            'secret' => $secret,
            'url' => $url,
            'grace_hours' => 72,
        ];

        $dir = $folder.'/storage/app/private';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->error('Não foi possível preparar armazenamento privado da instalação.');
            return self::FAILURE;
        }
        $target = $dir.'/lumeron-control.json';
        if (is_link($target)) {
            $this->error('Destino inseguro: o arquivo de licença não pode ser um symlink.');
            return self::FAILURE;
        }
        if (is_file($target)) {
            $existing = json_decode((string) file_get_contents($target), true);
            if (!is_array($existing)
                || ($existing['instance_id'] ?? '') !== $company->instance_id
                || ($existing['cnpj'] ?? '') !== $cnpj) {
                $this->error('Outra instalação já está vinculada a este diretório. Nenhuma chave foi substituída.');
                return self::FAILURE;
            }
        }

        $previous = is_file($target) ? file_get_contents($target) : null;
        $temp = tempnam($dir, '.lumeron-bind-');
        if ($temp === false) {
            $this->error('Não foi possível preparar vínculo seguro.');
            return self::FAILURE;
        }
        try {
            chmod($temp, 0600);
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temp, $json, LOCK_EX) === false
                || !rename($temp, $target)) {
                throw new \RuntimeException('Falha ao salvar o vínculo.');
            }
            chmod($target, 0600);
        } catch (\Throwable) {
            @unlink($temp);
            $this->error('Não foi possível salvar o vínculo da instalação.');
            return self::FAILURE;
        }

        // Verify a live, signed handshake before declaring this instance linked.
        // On failure, undo only the new private file, never touch fiscal data.
        $check = new Process([PHP_BINARY, 'artisan', 'lumeron:license-sync'], $folder);
        $check->setTimeout(30);
        $check->run();
        if (!$check->isSuccessful() || !str_contains($check->getOutput(), 'origem: online')) {
            if ($previous !== null) {
                file_put_contents($target, $previous, LOCK_EX);
                chmod($target, 0600);
            } else {
                @unlink($target);
            }
            $this->error('O ERP não confirmou a conexão assinada com o Control. O vínculo novo foi desfeito.');
            return self::FAILURE;
        }

        $company->record('managed_binding', 'Instalação vinculada pelo gerenciador, sem interação do cliente.');
        $this->info('Empresa '.$company->legal_name.' vinculada ao gerenciador.');
        $this->line('Acesso comercial: '.($company->currentStatus() === 'active' ? 'liberado' : 'restrito').'.');
        $this->line('A instalação consultará a licença automaticamente; nenhum segredo foi exibido.');
        return self::SUCCESS;
    }
}

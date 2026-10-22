<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Subdomain;
use App\Models\ManagedProvisioningJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

/**
 * Restricted VPS worker. ONLY this CLI may invoke the root-only ERP
 * installer; a customer registration never executes shell commands.
 */
final class ProvisionManagedAccounts extends Command
{
    protected $signature = 'control:provision-accounts {--limit=1}';
    protected $description = 'Prepara instalações isoladas dos clientes que aguardam acesso.';

    public function handle(): int
    {
        if (!$this->readyForProvisioning()) {
            $this->error('Provisionamento gerenciado não configurado no servidor.');
            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>10]]);
        if ($limit === false) return self::FAILURE;

        $ready = 0;
        $failed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $job = DB::transaction(function (): ?ManagedProvisioningJob {
                $job = ManagedProvisioningJob::query()->where('status','queued')
                    ->orderBy('id')->lockForUpdate()->first();
                if ($job) {
                    $job->forceFill(['status'=>'processing',
                        'started_at'=>now(), 'attempts'=>$job->attempts + 1])->save();
                }
                return $job;
            });
            if (!$job) break;

            try {
                $this->provision($job);
                $job->forceFill(['status'=>'ready','completed_at'=>now(),
                    'failure_code'=>null])->save();
                $job->company->record('managed_provisioned',
                    'Acesso ERP preparado e convite de primeiro acesso encaminhado.');
                $ready++;
            } catch (\Throwable) {
                // Do not retain raw terminal output, passwords, URLs with
                // tokens or infrastructure details in the customer database.
                $job->forceFill([
                    'status'=>'failed',
                    'failure_code'=>'requires_operator_review',
                    'completed_at'=>now(),
                ])->save();
                $failed++;
            }
        }
        $this->info('Contas prontas: '.$ready.'; precisam de revisão: '.$failed.'.');
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function readyForProvisioning(): bool
    {
        $c = config('managed_provisioning');
        if (!app()->environment('production') || !$c['enabled']
            || !$c['send_invite'] || PHP_OS_FAMILY !== 'Linux'
            || !function_exists('posix_geteuid') || posix_geteuid() !== 0
            || config('mail.default') !== 'smtp'
            || filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if (!preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}$/', $c['base_domain'])
            || !in_array($c['php_version'], ['8.3','8.4'], true)
            || !is_file($c['source'].'/deploy/instances/provision.sh')
            || !is_file($c['control_path'].'/artisan')
            || !is_file($c['mysql_admin_cnf'])
            || !preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $c['tls_cert_name'])
            || !str_starts_with($c['control_url'], 'https://')) {
            return false;
        }
        // A privileged worker must never execute application files
        // writable by the web or deployment user.
        foreach ([__FILE__, $c['source'].'/deploy/instances/provision.sh',
            $c['control_path'].'/artisan'] as $path) {
            $real = realpath($path);
            if ($real === false || !is_file($real)
                || fileowner($real) !== 0
                || (fileperms($real) & 0022) !== 0) {
                return false;
            }
        }
        return true;
    }

    private function provision(ManagedProvisioningJob $job): void
    {
        $c = config('managed_provisioning');
        $company = Company::query()->findOrFail($job->company_id);
        $slug = (string) $company->subdomain;
        $domain = $slug.'.'.$c['base_domain'];
        $email = strtolower(trim((string) $company->email));
        $login = strtolower(trim((string) ($company->admin_login ?: 'admin')));
        $adminName = trim((string) ($company->contact_name ?: $company->legal_name));
        if (!Subdomain::valid($slug)
            || !preg_match('/^[a-z][a-z0-9._-]{2,49}$/', $login)
            || !preg_match('/^\d{14}$/', (string) $company->cnpj)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $company->currentStatus() !== 'active'
            || strlen($adminName) < 2) {
            throw new \RuntimeException('Incomplete company identity.');
        }

        $id = 'c_'.(string) $company->id;
        $url = 'https://'.$domain;
        if ($company->installation_url && $company->installation_url !== $url) {
            throw new \RuntimeException('Conflicting installation address.');
        }

        $base = '/srv/lumeron/instances/'.$id;
        $invitePath = $base.'/shared/storage/app/private/managed-first-access.json';
        // A previously completed install whose invitation failed can be
        // recovered without recreating its bank, users, HMAC or webserver.
        if (!is_link($base.'/current')) {
            $args = [
                'bash', $c['source'].'/deploy/instances/provision.sh',
                '--id', $id, '--cnpj', $company->cnpj,
                '--domain', $domain, '--source', $c['source'],
                '--base-domain', $c['base_domain'],
                '--php-version', $c['php_version'],
                '--mysql-admin-cnf', $c['mysql_admin_cnf'],
                '--tls-cert-name', $c['tls_cert_name'],
                '--company-name', $company->legal_name,
                '--admin-name', $adminName, '--admin-email', $email,
                '--admin-login', $login,
                '--control-path', $c['control_path'],
                '--control-url', $c['control_url'],
                '--managed', '--apply',
            ];
            $process = new Process($args, $c['source']);
            $process->setTimeout(1800);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('The ERP installer failed.');
            }
        }

        if (!is_file($invitePath) || is_link($invitePath)) {
            throw new \RuntimeException('No private ERP invitation available.');
        }
        $data = json_decode((string) file_get_contents($invitePath), true);
        if (!is_array($data) || !is_string($data['url'] ?? null)
            || !is_string($data['email'] ?? null)
            || !hash_equals($email, $data['email'])
            || !str_starts_with($data['url'], $url.'/reset-password/')) {
            throw new \RuntimeException('Invalid ERP invitation.');
        }

        // The account is prepared. Verify its route before mailing.
        $health = Http::connectTimeout(3)->timeout(7)->get($url.'/up');
        if (!$health->successful()) {
            throw new \RuntimeException('ERP not responding.');
        }

        Mail::raw(
            "Sua empresa foi cadastrada no Lumeron ERP.\n\n".
            "Endereço: {$url}\n".
            "Usuário: {$login}\n\n".
            "Crie sua senha pessoal pelo link de primeiro acesso (válido por 60 minutos):\n".
            $data['url']."\n\n".
            "Se você não solicitou o acesso, entre em contato com o responsável pelo Lumeron.",
            function ($message) use ($email): void {
                $message->to($email)->subject('Lumeron ERP — primeiro acesso');
            }
        );

        // Never retain plaintext reset tokens in the Control database.
        @unlink($invitePath);
        $company->forceFill(['installation_url'=>$url])->save();
    }
}

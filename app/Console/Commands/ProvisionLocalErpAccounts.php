<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ManagedProvisioningJob;
use App\Services\LocalErpProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProvisionLocalErpAccounts extends Command
{
    protected $signature = 'control:provision-local
        {company? : ID da empresa já cadastrada no Control}
        {--all : Processar tarefas na fila, quando habilitado no Laragon}
        {--preview : Conferir caminhos sem criar arquivos ou bancos}';

    protected $description = 'Prepara instalações e bancos separados no Laragon, sem alterar o Nextor original.';

    public function handle(LocalErpProvisioner $service): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('Este comando só pode ser executado no Laragon.');
            return self::FAILURE;
        }
        $id = $this->argument('company');
        if ($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Informe um ID de empresa válido.');
            return self::FAILURE;
        }
        if ($id === null && !$this->option('all')) {
            $this->error('Informe o ID da empresa ou --all.');
            return self::FAILURE;
        }
        if ($this->option('all') && !config('managed_provisioning.local_enabled')) {
            $this->error('Ative LUMERON_LOCAL_PROVISIONING=true para processamento automático.');
            return self::FAILURE;
        }

        $query = ManagedProvisioningJob::query()->orderBy('id');
        if ($id !== null) {
            $query->where('company_id', (int) $id);
        } else {
            $query->where('status', 'queued')->limit(5);
        }
        $jobs = $query->get();
        if ($jobs->isEmpty()) {
            $this->warn('Nenhuma empresa aguardando preparação foi encontrada.');
            return self::SUCCESS;
        }
        $failures = 0;
        foreach ($jobs as $job) {
            $company = Company::query()->findOrFail($job->company_id);
            if ($job->status === 'ready') {
                $this->info('Empresa #'.$company->id.' já está preparada.');
                continue;
            }
            try {
                $preview = $service->preview($company);
                if ($this->option('preview')) {
                    $this->line('Empresa #'.$company->id.': '.$preview['destination']);
                    $this->line('Endereço: '.$preview['url']);
                    continue;
                }
                $claimed = DB::transaction(function () use ($job, $id): bool {
                    $current = ManagedProvisioningJob::query()
                        ->lockForUpdate()->findOrFail($job->id);
                    if ($id === null && $current->status !== 'queued') return false;
                    if ($current->status === 'ready') return false;
                    if ($current->status === 'processing'
                        && $current->started_at?->gt(now()->subMinutes(30))) return false;
                    $current->forceFill(['status'=>'processing',
                        'attempts'=>$current->attempts+1,
                        'started_at'=>now(), 'completed_at'=>null,
                        'failure_code'=>null])->save();
                    return true;
                });
                if (!$claimed) {
                    $this->warn('Empresa #'.$company->id.' já está em processamento.');
                    continue;
                }

                $url = $service->provision($company);
                $job->forceFill(['status'=>'ready',
                    'completed_at'=>now(), 'failure_code'=>null])->save();
                $company->forceFill(['installation_url'=>$url])->save();
                $company->record('managed_provisioned',
                    'Instalação local isolada preparada para o primeiro acesso.');
                $this->info('Empresa #'.$company->id.' pronta: '.$url);
                $this->line('Abra a ficha no Control e use "Primeiro acesso ao ERP".');
            } catch (\Throwable $e) {
                if (!$this->option('preview')) {
                    $job->refresh();
                    if ($job->status === 'processing') {
                        $job->forceFill(['status'=>'failed',
                            'failure_code'=>'laragon_needs_review',
                            'completed_at'=>now()])->save();
                    }
                }
                $this->error('Empresa #'.$company->id.': '.$e->getMessage());
                $failures++;
            }
        }
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}

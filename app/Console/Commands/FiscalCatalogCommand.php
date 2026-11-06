<?php

namespace App\Console\Commands;

use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalCatalogTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FiscalCatalogCommand extends Command
{
    protected $signature = 'fiscal:catalog
        {--install : Instala a referência oficial incluída nesta versão do ERP}
        {--templates : Instala também os modelos inativos de naturezas e grupos}
        {--import= : Caminho de um catálogo JSON revisado pelo responsável fiscal}
        {--sha256= : SHA-256 aprovado do arquivo importado}
        {--json : Exibe o resumo em JSON}';

    protected $description = 'Consulta ou instala versões de referência fiscal sem habilitar regras de tributação';

    public function handle(FiscalCatalogService $catalog, FiscalCatalogTemplateService $templates): int
    {
        if ($this->option('install') && $this->option('import')) {
            $this->error('Use --install ou --import, separadamente.');
            return self::INVALID;
        }
        if ($this->option('import') && !$this->option('sha256')) {
            $this->error('A importação exige --sha256 com o hash do arquivo revisado.');
            return self::INVALID;
        }
        if ($this->option('templates') && !$this->option('install')) {
            $this->error('Use --install --templates para instalar os modelos distribuídos pelo ERP.');
            return self::INVALID;
        }
        try {
            [$release, $templateResult] = DB::transaction(function () use ($catalog, $templates): array {
                $release = $this->option('install') ? $catalog->installBundled()
                    : ($this->option('import') ? $catalog->importFile((string) $this->option('import'), (string) $this->option('sha256')) : $catalog->current());
                return [$release, $this->option('templates') ? $templates->install($release->version) : null];
            });
            $summary = $release ? [
                'version' => $release->version, 'sha256' => $release->sha256,
                'consulted_at' => $release->consulted_at->format('Y-m-d'),
                'entry_count' => $release->entry_count, 'coverage' => $release->coverage,
                'templates' => $templateResult, 'automatic_tax_rules_enabled' => false,
            ] : ['version' => null, 'automatic_tax_rules_enabled' => false];
            if ($this->option('json')) {
                $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } elseif (!$release) {
                $this->line('Nenhum catálogo fiscal instalado. Use --install para instalar as referências.');
            } else {
                $this->line('Catálogo '.$release->version.' | '.$release->entry_count.' registros | Consulta '.$release->consulted_at->format('d/m/Y'));
                $this->line('SHA-256: '.$release->sha256);
                if ($templateResult) $this->line('Modelos instalados; personalizações preservadas. Nenhuma regra foi habilitada.');
            }
            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) foreach ($messages as $message) $this->error($message);
            return self::FAILURE;
        }
    }
}

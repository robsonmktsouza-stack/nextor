<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use DateTimeImmutable;
use Illuminate\Container\Container;
use JsonException;
use RuntimeException;

/** Official SVRS snapshot. Classification of the actual operation remains an explicit input. */
final class Nfe55RtcClassificationCatalog
{
    private const CST_FLAGS = ['IndExigeTrib', 'IndMonofasica', 'IndReducaoAliq', 'IndDiferimento',
        'IndReducaoBc', 'IndTransferenciaCred', 'IndCredPresIbsZfm', 'IndAjusteCompet'];
    private const CLASS_FLAGS = ['IndTribRegular', 'IndPermiteCredPres', 'IndMonoRetem', 'IndMonoRet',
        'IndMonoDif', 'IndMonoVal', 'IndEstornoCred', 'IndPbioDiferenca'];

    private static ?array $defaultDataset = null;
    private readonly array $dataset;
    private readonly bool $explicitDataset;
    private ?string $installedKey = null;
    private ?array $installedDataset = null;

    public function __construct(?array $dataset = null)
    {
        $this->explicitDataset = $dataset !== null;
        $this->dataset = $dataset ?? (self::$defaultDataset ??= $this->loadDataset());
        if (!is_array($this->dataset['metadata'] ?? null)
            || !is_array($this->dataset['csts'] ?? null)
            || !is_array($this->dataset['classifications'] ?? null)) {
            throw new RuntimeException('RTC: catálogo oficial inválido ou incompleto.');
        }
    }

    public function metadata(): array
    {
        return $this->activeDataset()['metadata'];
    }

    /** Returns raw official group flags and reductions; it never supplies tax rates or NCM rules. */
    public function validate(string $cst, string $classification, string $issueDate): array
    {
        if (!preg_match('/^\d{3}$/D', $cst) || !preg_match('/^\d{6}$/D', $classification)) {
            throw new RuntimeException('RTC: informe CST com três dígitos e classificação com seis dígitos.');
        }
        $this->assertDate($issueDate, 'data de emissão');
        $dataset = $this->activeDataset();
        $parent = $this->record($dataset['csts'][$cst] ?? null, $issueDate, 'CST', (bool) ($dataset['versioned_records'] ?? false));
        $record = $this->record($dataset['classifications'][$classification] ?? null, $issueDate, 'classificação', (bool) ($dataset['versioned_records'] ?? false));
        if (!is_array($parent) || !is_array($record) || ($record['cst'] ?? null) !== $cst) {
            throw new RuntimeException('RTC: par CST/classificação inexistente no catálogo oficial.');
        }
        $this->assertValidity($parent, $issueDate, 'CST');
        $this->assertValidity($record, $issueDate, 'classificação');
        if (($record['ind_nfe'] ?? null) !== true) {
            throw new RuntimeException('RTC: classificação não permitida para NF-e na tabela oficial.');
        }
        $this->assertFlags($parent['flags'] ?? null, self::CST_FLAGS);
        $this->assertFlags($record['flags'] ?? null, self::CLASS_FLAGS);
        foreach (['ibs_reduction', 'cbs_reduction'] as $key) {
            NfeTaxMath::rate($record[$key] ?? null, 'RTC redução oficial');
        }
        if (!in_array($record['rate_type'] ?? null, [1, 2, 3, 4, 5], true)) {
            throw new RuntimeException('RTC: tipo de alíquota ausente ou inválido no catálogo oficial.');
        }

        return $record + ['classification' => $classification, 'cst_flags' => $parent['flags'],
            'catalog_version' => $dataset['metadata']['catalog_version'] ?? null,
            'catalog_sha256' => $dataset['metadata']['catalog_sha256'] ?? null];
    }

    /** Explicit fixtures remain independent from Laravel and the installation. */
    private function activeDataset(): array
    {
        if ($this->explicitDataset) return $this->dataset;
        $container = Container::getInstance();
        if (!$container->bound('db')) return $this->dataset;
        $connection = $container->make('db')->connection();
        if (!$connection->getSchemaBuilder()->hasTable('fiscal_catalog_releases')
            || !$connection->getSchemaBuilder()->hasTable('fiscal_catalog_entries')) return $this->dataset;
        $release = FiscalCatalogRelease::query()->orderByDesc('consulted_at')->orderByDesc('id')->first();
        if (!$release) return $this->dataset;
        $provided = [];
        foreach (['ibs_cbs_cst', 'ibs_cbs_class'] as $kind) {
            $coverage = $release->coverage[$kind] ?? 'not_bundled';
            $status = is_array($coverage) ? ($coverage['status'] ?? null) : $coverage;
            $provided[$kind] = in_array($status, ['partial', 'complete'], true);
        }
        // An NCM-only release must not replace the existing RTC snapshot.
        if (!$provided['ibs_cbs_cst'] && !$provided['ibs_cbs_class']) return $this->dataset;
        if (!$provided['ibs_cbs_cst'] || !$provided['ibs_cbs_class']) {
            throw new RuntimeException('RTC: catálogo instalado incompleto; CST e classificações devem ser fornecidos juntos.');
        }
        // This cache belongs only to this object and this database connection.
        // A long-running worker observes a new release on its next validation.
        $key = spl_object_id($connection).'|'.$connection->getDatabaseName().'|'.$release->id.'|'.$release->sha256;
        if ($this->installedKey !== $key) {
            $this->installedDataset = $this->installedDataset($release);
            $this->installedKey = $key;
        }
        return $this->installedDataset;
    }

    private function installedDataset(FiscalCatalogRelease $release): array
    {
        $csts = []; $classifications = [];
        $entries = $release->entries()->whereIn('kind', ['ibs_cbs_cst', 'ibs_cbs_class'])
            ->where('status', 'reference')->whereNull('uf')->whereNull('regime')->orderBy('id')->get();
        foreach ($entries as $entry) {
            $parameters = $entry->parameters;
            if (!is_array($parameters)) throw new RuntimeException('RTC: parâmetros do catálogo instalado inválidos.');
            $entry->setRelation('release', $release);
            $isCst = $entry->kind === 'ibs_cbs_cst';
            $record = [
                'valid_from' => $this->installedStart($entry),
                'valid_until' => $this->installedEnd($entry),
                'published_at' => $parameters['published_at'] ?? null,
                'flags' => array_intersect_key($parameters, array_fill_keys($isCst ? self::CST_FLAGS : self::CLASS_FLAGS, true)),
                'catalog_reference' => $entry->trace(),
            ];
            if ($isCst) {
                $csts[$entry->code][] = $record;
            } else {
                $reference = $parameters['legal_reference'] ?? null;
                $record += [
                    'cst' => $parameters['cst'] ?? null,
                    'description' => $entry->description,
                    'ind_nfe' => $parameters['IndNfe'] ?? null,
                    'ibs_reduction' => $parameters['ibs_reduction'] ?? null,
                    'cbs_reduction' => $parameters['cbs_reduction'] ?? null,
                    'rate_type' => $parameters['rate_type'] ?? null,
                    'legal_reference' => is_array($reference) ? $reference : ['url' => $reference],
                ];
                $classifications[$entry->code][] = $record;
            }
        }
        if (!$csts || !$classifications) throw new RuntimeException('RTC: catálogo instalado sem CST ou classificações válidas.');
        $metadata = [
            'catalog_version' => $release->version, 'catalog_sha256' => $release->sha256,
            'retrieved_on' => $release->consulted_at->format('Y-m-d'), 'source_version' => null,
            'sources' => $release->sources, 'cst_count' => count($csts),
            'classification_count' => count($classifications),
            'validity_policy' => 'Início inclusivo; fim exclusivo conservador, sem prolongar a data final original.',
        ];
        return ['metadata' => $metadata, 'csts' => $csts, 'classifications' => $classifications, 'versioned_records' => true];
    }

    private function installedStart(FiscalCatalogEntry $entry): ?string
    {
        return $entry->valid_from === null ? null : $entry->valid_from->format('Y-m-d').'T00:00:00';
    }

    private function installedEnd(FiscalCatalogEntry $entry): ?string
    {
        $sourceEnd = $entry->parameters['source_valid_until'] ?? null;
        if ($sourceEnd !== null) $this->sourceDate($sourceEnd);
        // Generic reference entries have an inclusive final day; the original
        // RTC source uses an exclusive timestamp. Compare equivalent bounds so
        // importing the same source does not exclude its last valid day twice.
        $entryEnd = $entry->valid_until === null ? null
            : (new DateTimeImmutable($entry->valid_until->format('Y-m-d')))->modify('+1 day')->format('Y-m-d').'T00:00:00';
        if ($entryEnd !== null && ($sourceEnd === null || $entryEnd < $sourceEnd)) {
            return $entryEnd;
        }
        return $sourceEnd;
    }

    private function record(mixed $record, string $date, string $label, bool $versioned): ?array
    {
        if (!is_array($record)) return null;
        if (!$versioned) return $record;
        $applicable = [];
        foreach ($record as $candidate) {
            try {
                $this->assertValidity($candidate, $date, $label);
                $applicable[] = $candidate;
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'fora da vigência')) throw $error;
            }
        }
        if (!$applicable) throw new RuntimeException('RTC: '.$label.' fora da vigência oficial na data de emissão.');
        if (count($applicable) !== 1) throw new RuntimeException('RTC: versões conflitantes para '.$label.' no catálogo instalado.');
        return $applicable[0];
    }

    private function assertValidity(array $record, string $date, string $label): void
    {
        if (!array_key_exists('valid_from', $record) || !array_key_exists('valid_until', $record)) {
            throw new RuntimeException('RTC: vigência de '.$label.' ausente no catálogo oficial.');
        }
        $start = $this->sourceDate($record['valid_from']);
        $end = $record['valid_until'] === null ? null : $this->sourceDate($record['valid_until']);
        // An ended record is never prolonged. The ambiguous end day is conservatively excluded.
        if ($date < $start || ($end !== null && $date >= $end)) {
            throw new RuntimeException('RTC: '.$label.' fora da vigência oficial na data de emissão.');
        }
    }

    private function sourceDate(mixed $timestamp): string
    {
        if (!is_string($timestamp)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/D', $timestamp)) {
            throw new RuntimeException('RTC: data de vigência inválida no catálogo oficial.');
        }
        $date = substr($timestamp, 0, 10);
        $this->assertDate($date, 'vigência oficial');
        return $date;
    }

    private function assertDate(string $date, string $label): void
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('RTC: '.$label.' inválida.');
        }
    }

    private function assertFlags(mixed $flags, array $required): void
    {
        if (!is_array($flags)) throw new RuntimeException('RTC: indicadores oficiais ausentes no catálogo.');
        foreach ($required as $flag) {
            if (!array_key_exists($flag, $flags) || !is_bool($flags[$flag])) {
                throw new RuntimeException('RTC: indicador '.$flag.' ausente ou inválido no catálogo oficial.');
            }
        }
    }

    private function loadDataset(): array
    {
        $json = @file_get_contents(dirname(__DIR__, 3).'/resources/fiscal/rtc-classifications.json');
        if ($json === false) throw new RuntimeException('RTC: tabela oficial de classificações indisponível.');
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('RTC: tabela oficial de classificações inválida.', 0, $error);
        }
        if (!is_array($data)) throw new RuntimeException('RTC: tabela oficial de classificações inválida.');
        return $data;
    }
}

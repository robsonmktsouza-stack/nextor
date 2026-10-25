<?php

namespace App\Services\Fiscal;

use DateTimeImmutable;
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

    public function __construct(?array $dataset = null)
    {
        $this->dataset = $dataset ?? (self::$defaultDataset ??= $this->loadDataset());
        if (!is_array($this->dataset['metadata'] ?? null)
            || !is_array($this->dataset['csts'] ?? null)
            || !is_array($this->dataset['classifications'] ?? null)) {
            throw new RuntimeException('RTC: catálogo oficial inválido ou incompleto.');
        }
    }

    public function metadata(): array
    {
        return $this->dataset['metadata'];
    }

    /** Returns raw official group flags and reductions; it never supplies tax rates or NCM rules. */
    public function validate(string $cst, string $classification, string $issueDate): array
    {
        if (!preg_match('/^\d{3}$/D', $cst) || !preg_match('/^\d{6}$/D', $classification)) {
            throw new RuntimeException('RTC: informe CST com três dígitos e classificação com seis dígitos.');
        }
        $this->assertDate($issueDate, 'data de emissão');
        $parent = $this->dataset['csts'][$cst] ?? null;
        $record = $this->dataset['classifications'][$classification] ?? null;
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

        return $record + ['classification' => $classification, 'cst_flags' => $parent['flags']];
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

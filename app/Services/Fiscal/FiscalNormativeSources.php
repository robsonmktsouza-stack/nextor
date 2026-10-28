<?php

namespace App\Services\Fiscal;

/**
 * Official sources for fiscal rule maintenance.
 *
 * This registry documents normative provenance; it does not assert that a
 * document alone determines the tax treatment of an operation.
 * Updates must be reviewed and versioned before changing tax calculations.
 */
final class FiscalNormativeSources
{
    public const NFE_TECHNICAL_NOTES = 'https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=6WfrpZYE4Ik=';
    public const NFE_TECHNICAL_REPORTS = 'https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=B/6oigHgyAw=';
    public const NFE_TABLES = 'https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=/NJarYc9nus=';
    public const RECEITA_FEDERAL = 'https://www.gov.br/receitafederal/pt-br';
    public const CONFAZ = 'https://www.confaz.fazenda.gov.br/';
    public const RTC_CALCULATOR = 'https://consumo.tributos.gov.br/servico/calcular-tributos-consumo';

    /** @return array<string, array{url:string,scope:list<string>}> */
    public static function catalog(): array
    {
        return [
            'nfe_technical_notes' => [
                'url' => self::NFE_TECHNICAL_NOTES,
                'scope' => ['layout', 'schemas', 'validation', 'rtc'],
            ],
            'nfe_technical_reports' => [
                'url' => self::NFE_TECHNICAL_REPORTS,
                'scope' => ['cfop', 'ncm', 'cclasstrib', 'payment_methods'],
            ],
            'nfe_tables' => [
                'url' => self::NFE_TABLES,
                'scope' => ['cfop', 'ncm', 'cclasstrib', 'cst', 'cbs_rates', 'fcp'],
            ],
            'receita_federal' => [
                'url' => self::RECEITA_FEDERAL,
                'scope' => ['ipi', 'pis', 'cofins', 'rtc', 'federal_legislation'],
            ],
            'confaz' => [
                'url' => self::CONFAZ,
                'scope' => ['icms', 'icms_st', 'protocols', 'agreements'],
            ],
            'rtc_calculator' => [
                'url' => self::RTC_CALCULATOR,
                'scope' => ['ibs', 'cbs', 'is'],
            ],
        ];
    }

    /** @return list<string> */
    public static function sourcesFor(string $subject): array
    {
        $sources = [];
        foreach (self::catalog() as $entry) {
            if (in_array(strtolower(trim($subject)), $entry['scope'], true)) {
                $sources[] = $entry['url'];
            }
        }
        return array_values(array_unique($sources));
    }
}

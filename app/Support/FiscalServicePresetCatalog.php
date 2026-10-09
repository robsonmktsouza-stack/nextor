<?php

namespace App\Support;

/**
 * Subitens reais da lista nacional de serviços (LC 116/2003).
 * Fonte: https://www.gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs
 *
 * O código nacional e o item da lista podem ser padronizados. O ISS do
 * município e o enquadramento no Simples não podem ser adivinhados.
 */
final class FiscalServicePresetCatalog
{
    /** @return array<string, array{label:string,national_code:string}> */
    public static function all(): array
    {
        return [
            'accounting' => ['label'=>'Contabilidade', 'national_code'=>'171901'],
            'consulting' => ['label'=>'Consultoria empresarial', 'national_code'=>'170101'],
            'auditing' => ['label'=>'Auditoria', 'national_code'=>'171601'],
            'legal' => ['label'=>'Advocacia', 'national_code'=>'171401'],
            'development' => ['label'=>'Desenvolvimento de sistemas', 'national_code'=>'010101'],
            'programming' => ['label'=>'Programação', 'national_code'=>'010201'],
            'software_licensing' => ['label'=>'Licenciamento de software', 'national_code'=>'010501'],
            'it_support' => ['label'=>'Suporte em informática', 'national_code'=>'010701'],
            'website' => ['label'=>'Desenvolvimento e manutenção de sites', 'national_code'=>'010801'],
            'hosting' => ['label'=>'Hospedagem de dados', 'national_code'=>'010302'],
            'gym' => ['label'=>'Academias e atividades físicas', 'national_code'=>'060401'],
            'beauty' => ['label'=>'Salões de beleza e barbearias', 'national_code'=>'060101'],
            'education' => ['label'=>'Cursos e treinamentos', 'national_code'=>'080201'],
            'vehicle_repair' => ['label'=>'Mecânica e manutenção de veículos', 'national_code'=>'140101'],
            'technical_assistance' => ['label'=>'Assistência técnica', 'national_code'=>'140201'],
            'civil_works' => ['label'=>'Construção civil por empreitada', 'national_code'=>'070202'],
            'building_repair' => ['label'=>'Reformas e reparos em edifícios', 'national_code'=>'070501'],
            'advertising' => ['label'=>'Publicidade e propaganda', 'national_code'=>'170601'],
            'photography' => ['label'=>'Fotografia', 'national_code'=>'130301'],
            'security' => ['label'=>'Segurança e monitoramento', 'national_code'=>'110201'],
            'transport' => ['label'=>'Transporte municipal', 'national_code'=>'160201'],
            'hotel' => ['label'=>'Hospedagem em hotéis', 'national_code'=>'090101'],
            'electrotechnical' => ['label'=>'Serviços técnicos em eletrotécnica', 'national_code'=>'310102'],
            'carpentry' => ['label'=>'Carpintaria', 'national_code'=>'141301'],
        ];
    }

    public static function item(string $nationalCode): string
    {
        return substr($nationalCode, 0, 2).'.'.substr($nationalCode, 2, 2);
    }

    /** @return array<string, string> */
    public static function groupFields(string $nationalCode): array
    {
        return [
            'service_list_item' => self::item($nationalCode),
            'national_tax_code' => $nationalCode,
            'iss_exigibility' => '1',
            'iss_calculation' => 'municipal_or_simples',
        ];
    }
}

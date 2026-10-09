<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use InvalidArgumentException;

/**
 * Configuração única de documentos fiscais.
 *
 * Não liga um emissor ainda inexistente e não substitui validação do XML,
 * legislação e confirmação da SEFAZ. Centraliza apenas preferências salvas
 * no próprio NEXTOR (sem flags de produção fixadas no código ou no .env).
 */
final class FiscalDocumentSettings
{
    private const DOCUMENTS = [
        'nfe'  => ['group'=>'nfe', 'enabled'=>'enabled', 'environment'=>'environment'],
        'nfce' => ['group'=>'nfce', 'enabled'=>'enabled', 'environment'=>'environment'],
        'nfse' => ['group'=>'nfse', 'enabled'=>'enabled', 'environment'=>'environment'],
        'cte'  => ['group'=>'cte', 'enabled'=>'cte_enabled', 'environment'=>'cte_environment'],
        'mdfe' => ['group'=>'cte', 'enabled'=>'mdfe_enabled', 'environment'=>'mdfe_environment'],
    ];

    /** @return list<string> */
    public static function documentTypes(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    /** @return array{group:string,enabled:string,environment:string} */
    private function options(string $documentType): array
    {
        return self::DOCUMENTS[$documentType]
            ?? throw new InvalidArgumentException('Modelo de documento fiscal desconhecido.');
    }

    public function enabled(string $documentType): bool
    {
        $options=$this->options($documentType);
        return (bool) AppSetting::value($options['group'], $options['enabled'], false)
            && (bool) AppSetting::value('fiscal', 'enabled', false);
    }

    public function environment(string $documentType): string
    {
        $options=$this->options($documentType);
        $configured=(string) AppSetting::value(
            $options['group'],
            $options['environment'],
            AppSetting::value('fiscal','default_environment','homologation')
        );
        return in_array($configured,['homologation','production'],true)
            ? $configured : 'homologation';
    }

    public function productionAuthorized(string $documentType): bool
    {
        $options=$this->options($documentType);
        $key=$documentType==='cte' ? 'cte_production_enabled'
            : ($documentType==='mdfe' ? 'mdfe_production_enabled' : 'production_enabled');
        return (bool) AppSetting::value($options['group'],$key,false);
    }

    public function transmissionIssue(string $documentType, string $environment): ?string
    {
        if (!$this->enabled($documentType)) {
            return 'Emissão fiscal desativada nas configurações.';
        }
        if (!in_array($environment,['homologation','production'],true)) {
            return 'Ambiente fiscal inválido.';
        }
        if ($environment === 'production' && !$this->productionAuthorized($documentType)) {
            return 'Ative a emissão em produção nas configurações do documento.';
        }
        return null;
    }
}

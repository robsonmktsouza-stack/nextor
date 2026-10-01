<?php

namespace App\Fiscal\Schema;

final class SchemaCatalog
{
    public const AUDITED_AT = '2026-10-01';
    public const NFE_LAYOUT_VERSION = '4.00';

    /**
     * Pacotes listados como oficiais/em uso pelo Portal Nacional na auditoria.
     * O importador de schemas será implementado em fase posterior; não editar XSD manualmente.
     */
    public const CORE_PACKAGE = '010e_v.1.02';
    public const CNPJ_ALPHANUMERIC_PACKAGE = '010d_v.1.03';
    public const RTC_EVENT_PACKAGE = 'NT2025.002_v1.40_events';
    public const RTC_VALIDATION_NOTE = 'NT2025.002_v1.51';
    public const SALES_OPERATION_VALIDATION_NOTE = 'NT2026.002_v1.10';

    private function __construct()
    {
    }
}

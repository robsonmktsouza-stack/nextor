<?php

namespace App\Fiscal\Schema;

final class SchemaCatalog
{
    public const AUDITED_AT = '2026-10-01';
    public const NFE_LAYOUT_VERSION = '4.00';

    /**
     * Em 01/10/2026 a listagem oficial do Portal Nacional já havia substituído
     * o pacote 010e_v1.02 pelo PL_010f_v1.04, publicado em 31/08/2026.
     */
    public const CORE_PACKAGE = 'PL_010f_v1.04';
    public const PREVIOUS_CORE_PACKAGE = '010e_v1.02';
    public const CNPJ_ALPHANUMERIC_PACKAGE = '010d_v1.03';

    /**
     * Notas publicadas após o pacote 010f são rastreadas aqui sem presumir
     * que exista um novo XSD até sua efetiva publicação no Portal Nacional.
     */
    public const RTC_VALIDATION_NOTE = 'NT2025.002_v1.52';
    public const SALES_OPERATION_VALIDATION_NOTE = 'NT2026.002_v1.11';
    public const IBS_CBS_CONTRIBUTOR_NOTE = 'NT2026.007_v1.10';
    public const NET_PRODUCT_VALUE_NOTE = 'NT2026.008_v1.00';
    public const CNPJ_ALPHANUMERIC_NOTE = 'NT2026.004_v1.01';

    private function __construct()
    {
    }
}

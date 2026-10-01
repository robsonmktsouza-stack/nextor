<?php

namespace App\Fiscal\Enums;

enum FiscalEnvironment: string
{
    case PRODUCTION = '1';
    case HOMOLOGATION = '2';
}

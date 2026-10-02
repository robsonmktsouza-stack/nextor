<?php

namespace App\Fiscal\Tax\Enums;

enum PaymentIntegrationType: string
{
    case INTEGRATED = '1';
    case NOT_INTEGRATED = '2';
}

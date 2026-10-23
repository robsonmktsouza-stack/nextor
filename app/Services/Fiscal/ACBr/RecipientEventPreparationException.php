<?php

namespace App\Services\Fiscal\ACBr;

use RuntimeException;

/** Falha local antes de NFE_EnviarEvento: nenhum evento foi transmitido. */
final class RecipientEventPreparationException extends RuntimeException
{
}

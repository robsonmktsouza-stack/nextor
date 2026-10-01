<?php

namespace App\Fiscal\Enums;

enum FiscalDocumentState: string
{
    case DRAFT = 'draft';
    case GENERATED = 'generated';
    case VALIDATED = 'validated';
    case SIGNED = 'signed';
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case REJECTED = 'rejected';
    case CONTINGENCY = 'contingency';
    case CANCELLED = 'cancelled';
    case ERROR = 'error';
}

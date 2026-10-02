<?php

namespace App\Fiscal\Sefaz\Enums;

enum SefazTransportStatus: string
{
    case STARTED = 'started';
    case SUCCESS = 'success';
    case HTTP_ERROR = 'http_error';
    case TLS_ERROR = 'tls_error';
    case TRANSPORT_ERROR = 'transport_error';
    case SOAP_ERROR = 'soap_error';
    case RESPONSE_ERROR = 'response_error';
}

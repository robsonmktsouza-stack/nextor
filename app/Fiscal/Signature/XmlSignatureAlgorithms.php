<?php

namespace App\Fiscal\Signature;

final class XmlSignatureAlgorithms
{
    public const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
    public const C14N_10 = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    public const RSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    public const SHA1 = 'http://www.w3.org/2000/09/xmldsig#sha1';
    public const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private function __construct()
    {
    }
}

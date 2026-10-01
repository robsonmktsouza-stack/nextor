<?php

namespace App\Fiscal\Certificate;

use App\Fiscal\Models\FiscalCertificate;
use App\Fiscal\Models\FiscalCompany;

final class A1CertificateVault
{
    public function __construct(private readonly A1CertificateReader $reader)
    {
    }

    public function store(FiscalCompany $company, string $pfxBytes, string $password): FiscalCertificate
    {
        $result = $this->reader->read($pfxBytes, $password);
        $info = $result['info'];

        return FiscalCertificate::query()->updateOrCreate(
            ['fiscal_company_id' => $company->id],
            [
                'pfx_payload' => base64_encode($pfxBytes),
                'pfx_password' => $password,
                'subject' => $info->subject,
                'issuer' => $info->issuer,
                'serial_number' => $info->serialNumber,
                'fingerprint_sha256' => $info->fingerprintSha256,
                'subject_document' => $info->subjectDocument,
                'valid_from' => $info->validFrom,
                'valid_to' => $info->validTo,
                'is_active' => true,
            ],
        );
    }
}

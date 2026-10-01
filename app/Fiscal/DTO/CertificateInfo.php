<?php

namespace App\Fiscal\DTO;

use DateTimeImmutable;

final readonly class CertificateInfo
{
    public function __construct(
        public ?string $subject,
        public ?string $issuer,
        public ?string $serialNumber,
        public ?string $fingerprintSha256,
        public ?string $subjectDocument,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
    ) {
    }

    public function isExpired(?DateTimeImmutable $at = null): bool
    {
        if ($this->validTo === null) {
            return false;
        }

        return $this->validTo < ($at ?? new DateTimeImmutable());
    }
}

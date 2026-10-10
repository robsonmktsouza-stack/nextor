<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * An installation belongs to exactly one legal entity. This binding is set by
 * the VPS operator, outside the database, and cannot be changed in the UI.
 */
final class InstanceIdentity
{
    public static function cnpj(): ?string
    {
        $value=preg_replace('/\D/', '', (string)config('instance.cnpj', ''));
        return $value !== '' ? $value : null;
    }

    public static function matches(?string $document): bool
    {
        $expected=self::cnpj();
        if ($expected===null) return true; // local development/legacy deployments
        return hash_equals($expected, preg_replace('/\D/', '', (string)$document));
    }

    public static function assertCompanyDocument(?string $document): void
    {
        if (!self::matches($document)) {
            throw ValidationException::withMessages([
                'document'=>'Esta instalação pertence a um CNPJ diferente. Configure o CNPJ contratado nesta instalação.',
            ]);
        }
    }
}

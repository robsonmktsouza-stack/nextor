<?php

namespace App\Fiscal\Schema;

final readonly class SchemaValidationResult
{
    /** @param list<SchemaValidationError> $errors */
    public function __construct(
        public bool $valid,
        public array $errors,
    ) {
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => array_map(
                static fn (SchemaValidationError $error) => $error->toArray(),
                $this->errors,
            ),
        ];
    }
}

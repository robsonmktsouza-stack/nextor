<?php

namespace App\Fiscal\Schema;

final class SchemaResolver
{
    public function __construct(private readonly SchemaRegistry $registry)
    {
    }

    public function resolve(string $document, string $version): ResolvedSchema
    {
        return $this->registry->resolve($document, $version);
    }
}

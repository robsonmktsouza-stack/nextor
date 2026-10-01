<?php

namespace App\Fiscal\Schema;

final readonly class ResolvedSchema
{
    public function __construct(
        public SchemaManifest $manifest,
        public string $rootPath,
    ) {
    }
}

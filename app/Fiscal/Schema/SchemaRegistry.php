<?php

namespace App\Fiscal\Schema;

use App\Fiscal\Exceptions\SchemaException;

final class SchemaRegistry
{
    public function __construct(private readonly ?string $rootPath = null)
    {
    }

    /**
     * Resolve um único pacote ativo e verifica a integridade de todos os
     * arquivos declarados no manifesto antes de liberá-lo.
     */
    public function resolve(string $document, string $version): ResolvedSchema
    {
        $matches = array_values(array_filter(
            $this->manifests(),
            static fn (SchemaManifest $manifest) =>
                $manifest->active
                && strcasecmp($manifest->document, $document) === 0
                && $manifest->layoutVersion === $version,
        ));

        if ($matches === []) {
            throw new SchemaException("Nenhum schema ativo para {$document} versão {$version}.");
        }

        if (count($matches) !== 1) {
            throw new SchemaException("Mais de um schema ativo para {$document} versão {$version}.");
        }

        $manifest = $matches[0];
        $directory = $this->safePath($this->root().DIRECTORY_SEPARATOR.$manifest->directory);

        foreach ($manifest->files as $file) {
            $path = $this->safePath($directory.DIRECTORY_SEPARATOR.$file['path']);

            if (!is_file($path)) {
                throw new SchemaException("Arquivo XSD ausente: {$file['path']}.");
            }

            $actual = hash_file('sha256', $path);
            if (!hash_equals($file['sha256'], strtolower($actual))) {
                throw new SchemaException("Integridade inválida para o XSD {$file['path']}.");
            }
        }

        $rootSchema = $this->safePath($directory.DIRECTORY_SEPARATOR.$manifest->rootSchema);

        if (!is_file($rootSchema) || $manifest->expectedHash($manifest->rootSchema) === null) {
            throw new SchemaException('Schema raiz não está declarado corretamente no manifesto.');
        }

        return new ResolvedSchema($manifest, $rootSchema);
    }

    /** @return list<SchemaManifest> */
    public function manifests(): array
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.'manifests';

        if (!is_dir($directory)) {
            return [];
        }

        $paths = glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($paths, SORT_STRING);

        return array_map(
            static fn (string $path) => SchemaManifest::fromJson(
                (string) file_get_contents($path),
                basename($path),
            ),
            $paths,
        );
    }

    private function root(): string
    {
        return rtrim(
            $this->rootPath ?? resource_path('fiscal/schemas'),
            DIRECTORY_SEPARATOR,
        );
    }

    private function safePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $root = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->root());

        if (!str_starts_with($normalized, $root.DIRECTORY_SEPARATOR) && $normalized !== $root) {
            throw new SchemaException('Caminho de schema fora da raiz fiscal.');
        }

        return $normalized;
    }
}

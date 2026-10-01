<?php

namespace App\Fiscal\Schema;

use App\Fiscal\Exceptions\SchemaException;

final readonly class SchemaManifest
{
    /**
     * @param list<string> $technicalNotes
     * @param list<array{path:string,sha256:string}> $files
     */
    public function __construct(
        public string $package,
        public string $document,
        public string $layoutVersion,
        public string $officialOrigin,
        public string $officialListing,
        public string $publicationDate,
        public string $downloadDate,
        public string $directory,
        public string $rootSchema,
        public ?string $zipSha256,
        public array $technicalNotes,
        public bool $active,
        public array $files,
    ) {
    }

    public static function fromJson(string $json, string $source = 'manifest'): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SchemaException("Manifesto de schema inválido em {$source}: {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data)) {
            throw new SchemaException("Manifesto de schema inválido em {$source}.");
        }

        return self::fromArray($data, $source);
    }

    public static function fromArray(array $data, string $source = 'manifest'): self
    {
        $required = [
            'package', 'document', 'layout_version', 'official_origin', 'official_listing',
            'publication_date', 'download_date', 'directory', 'root_schema',
            'technical_notes', 'active', 'files',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new SchemaException("Campo obrigatório '{$key}' ausente em {$source}.");
            }
        }

        if (!is_array($data['technical_notes']) || !is_array($data['files']) || $data['files'] === []) {
            throw new SchemaException("Listas de notas técnicas/arquivos inválidas em {$source}.");
        }

        $directory = trim((string) $data['directory'], '/');
        $rootSchema = (string) $data['root_schema'];

        foreach ([$directory, $rootSchema] as $path) {
            if (
                $path === ''
                || str_contains($path, '..')
                || str_starts_with($path, '/')
                || str_contains($path, '\\')
            ) {
                throw new SchemaException("Caminho de schema inseguro em {$source}.");
            }
        }

        if (!preg_match('/\.xsd$/i', $rootSchema)) {
            throw new SchemaException("Schema raiz inválido em {$source}.");
        }

        $files = [];
        foreach ($data['files'] as $file) {
            if (
                !is_array($file)
                || !isset($file['path'], $file['sha256'])
                || !is_string($file['path'])
                || !is_string($file['sha256'])
                || !preg_match('/^[a-f0-9]{64}$/i', $file['sha256'])
            ) {
                throw new SchemaException("Entrada de arquivo inválida em {$source}.");
            }

            if (
                $file['path'] === ''
                || str_contains($file['path'], '..')
                || str_starts_with($file['path'], '/')
                || str_contains($file['path'], '\\')
            ) {
                throw new SchemaException("Caminho de arquivo inseguro em {$source}.");
            }

            $files[] = [
                'path' => $file['path'],
                'sha256' => strtolower($file['sha256']),
            ];
        }

        return new self(
            package: (string) $data['package'],
            document: (string) $data['document'],
            layoutVersion: (string) $data['layout_version'],
            officialOrigin: (string) $data['official_origin'],
            officialListing: (string) $data['official_listing'],
            publicationDate: (string) $data['publication_date'],
            downloadDate: (string) $data['download_date'],
            directory: $directory,
            rootSchema: $rootSchema,
            zipSha256: isset($data['zip_sha256']) ? strtolower((string) $data['zip_sha256']) : null,
            technicalNotes: array_values(array_map('strval', $data['technical_notes'])),
            active: (bool) $data['active'],
            files: $files,
        );
    }

    public function expectedHash(string $file): ?string
    {
        foreach ($this->files as $entry) {
            if ($entry['path'] === $file) {
                return $entry['sha256'];
            }
        }

        return null;
    }
}

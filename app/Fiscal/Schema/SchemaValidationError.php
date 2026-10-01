<?php

namespace App\Fiscal\Schema;

final readonly class SchemaValidationError
{
    public function __construct(
        public string $level,
        public int $code,
        public int $line,
        public int $column,
        public string $message,
        public ?string $file,
    ) {
    }

    public static function fromLibxml(\LibXMLError $error): self
    {
        return new self(
            level: match ($error->level) {
                LIBXML_ERR_WARNING => 'warning',
                LIBXML_ERR_ERROR => 'error',
                LIBXML_ERR_FATAL => 'fatal',
                default => 'unknown',
            },
            code: (int) $error->code,
            line: (int) $error->line,
            column: (int) $error->column,
            message: trim($error->message),
            file: $error->file !== '' ? $error->file : null,
        );
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'line' => $this->line,
            'column' => $this->column,
            'message' => $this->message,
            'file' => $this->file,
        ];
    }
}

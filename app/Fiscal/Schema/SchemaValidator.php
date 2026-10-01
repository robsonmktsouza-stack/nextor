<?php

namespace App\Fiscal\Schema;

use DOMDocument;

final class SchemaValidator
{
    public function __construct(private readonly SchemaResolver $resolver)
    {
    }

    public function validate(string $xml, string $document = 'NFe', string $version = '4.00'): SchemaValidationResult
    {
        $schema = $this->resolver->resolve($document, $version);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;

            $loaded = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);

            if (!$loaded) {
                return new SchemaValidationResult(false, $this->consumeErrors());
            }

            libxml_clear_errors();
            $valid = @$dom->schemaValidate($schema->rootPath);
            $errors = $this->consumeErrors();

            return new SchemaValidationResult($valid && $errors === [], $errors);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<SchemaValidationError> */
    private function consumeErrors(): array
    {
        $errors = array_map(
            static fn (\LibXMLError $error) => SchemaValidationError::fromLibxml($error),
            libxml_get_errors(),
        );

        libxml_clear_errors();

        return $errors;
    }
}

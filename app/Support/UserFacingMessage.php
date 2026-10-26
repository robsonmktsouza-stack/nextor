<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Presentation only: never changes the exception or its diagnostic evidence. */
final class UserFacingMessage
{
    public static function fromThrowable(Throwable $error, string $fallback): string
    {
        if ($error instanceof ValidationException) {
            $message = collect($error->errors())->flatten()->first();
            return self::fromText(is_string($message) ? $message : '', $fallback);
        }
        $controlled = (($error instanceof \RuntimeException || $error instanceof \InvalidArgumentException)
                && !$error instanceof \PDOException)
            || $error instanceof HttpExceptionInterface;
        $message = $controlled ? self::fromText($error->getMessage(), $fallback) : $fallback;
        if ($message !== $error->getMessage()) report($error);
        return $message;
    }

    public static function fromText(string $message, string $fallback = 'Não foi possível concluir a operação. Tente novamente.'): string
    {
        $message = trim($message);
        if ($message === '' || self::isTechnical($message)) return $fallback;
        $translated = match ($message) {
            'Unauthenticated.' => 'Entre novamente para continuar.',
            'Unauthorized', 'This action is unauthorized.', 'Forbidden' => 'Você não possui permissão para esta operação.',
            'Not Found', 'Not found.' => 'O registro ou a página não foi encontrado.',
            'Too Many Requests' => 'Muitas tentativas em pouco tempo. Aguarde antes de tentar novamente.',
            'Service Unavailable', 'Server Error', 'Internal Server Error' => $fallback,
            'The given data was invalid.' => 'Confira os dados informados.',
            default => $message,
        };
        return class_exists(FiscalUserMessage::class) ? FiscalUserMessage::present($translated) : $translated;
    }

    private static function isTechnical(string $message): bool
    {
        return preg_match('~(?:SQLSTATE|PDOException|QueryException|JsonException|Illuminate\\\\|Symfony\\\\|Stack trace|Traceback|Call to undefined|Undefined (?:array key|variable|index)|Trying to access array offset|syntax error|fatal error|Allowed memory size|Maximum execution time|Connection (?:refused|reset)|cURL error\s*\d+|getaddrinfo|stream_socket|failed to open stream|could not find driver|no such (?:table|column)|No query results for model|proc_open|PKCS.?12|OpenSSL|TypeError|ReferenceError|SyntaxError|Failed to fetch|NetworkError|Unexpected (?:token|end)|Cannot read properties|is not a function|Maximum call stack|(?:[A-Z]:\\\\|/(?:var|srv|workspace|home|app|opt|tmp)/)|^\s*(?:<!doctype|<html|\{\s*"|\[\s*\{))~iu', $message) === 1;
    }
}

<?php

namespace App\Support;

/**
 * Safe, user-facing categorization of SMTP failures. Never expose the raw
 * transport exception: it may contain an account name, server transcript,
 * headers, or authentication material.
 */
final class EmailConnectionFeedback
{
    public static function category(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());

        // Order matters: SSL and hostname errors can also say 'connection failed'.
        if (self::has($message, [
            'certificate verify failed', 'certificate verification failed',
            'unable to get local issuer certificate', 'self-signed certificate',
            'self signed certificate', 'ssl operation failed',
            'certificate has expired', 'peer certificate',
        ])) {
            return 'certificate';
        }
        if (self::has($message, [
            'getaddrinfo failed', 'php_network_getaddresses', 'name or service not known',
            'no such host is known', 'nodename nor servname provided',
        ])) {
            return 'dns';
        }
        if (self::has($message, [
            'failed to authenticate', 'authentication failed', 'authentication unsuccessful',
            'invalid credentials', 'incorrect password', 'invalid password',
            'username and password not accepted', '535 ', '534 ',
        ])) {
            return 'authentication';
        }
        if (self::has($message, [
            'connection timed out', 'timed out', 'connection refused',
            'could not be established', 'network is unreachable',
            'failed to connect', 'connection reset',
        ])) {
            return 'connection';
        }
        if (self::has($message, [
            'sender address rejected', 'sender verify failed',
            'relay access denied', 'relay denied', '550 ', '553 ', '554 ',
            'domain not verified',
        ])) {
            return 'rejected';
        }
        if (self::has($message, [
            'unsupported scheme', 'scheme is not supported',
            'the scheme', 'unsupported transport',
        ])) {
            return 'protocol';
        }

        return 'unknown';
    }

    public static function message(\Throwable $error): string
    {
        return match (self::category($error)) {
            'certificate' => 'Falha na verificação do certificado SSL. Verifique os certificados do PHP/Laragon e a data do Windows.',
            'dns' => 'Não foi possível localizar o servidor SMTP. Verifique o endereço informado e a conexão com a internet.',
            'authentication' => 'O servidor rejeitou a autenticação. Confira o e-mail completo e a senha da conta Titan.',
            'connection' => 'Não foi possível conectar ao servidor SMTP. Verifique a porta e possíveis bloqueios de rede ou firewall.',
            'rejected' => 'O servidor recusou o envio. Confira o remetente e se o domínio de e-mail está ativo e configurado no Titan.',
            'protocol' => 'O sistema não conseguiu iniciar o transporte SMTP seguro. Verifique as opções de segurança da conexão.',
            default => 'O teste encontrou uma falha não identificada. Confira os dados da conta e a configuração do serviço de e-mail.',
        };
    }

    private static function has(string $message, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($message, $word)) return true;
        }
        return false;
    }
}

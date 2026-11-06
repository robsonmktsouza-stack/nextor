<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission'=>\App\Http\Middleware\RequirePermission::class,
            'company.configured'=>\App\Http\Middleware\RequireCompanyConfigured::class,
            'nextor.api'=>\App\Http\Middleware\RequireApiToken::class,
            'control.license'=>\App\Http\Middleware\RequireCentralLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never retain operator/supervisor credentials or PINs in old form
        // input after a validation error.
        $exceptions->dontFlash([
            'credential','supervisor_credential','discount_credential',
            'incoming_credential','outgoing_credential',
            'current_password','pin','pin_confirmation',
            'certificate','certificate_password','csc_token','api_token',
            'smtp_password','mail_password','secret','client_secret',
        ]);
        $exceptions->render(function (\Illuminate\Validation\ValidationException $error, \Illuminate\Http\Request $request) {
            $bag = $error->validator->errors();
            foreach ($bag->getMessages() as $field => $messages) {
                $bag->forget($field);
                foreach ($messages as $message) {
                    $bag->add($field, \App\Support\UserFacingMessage::fromText($message, 'Confira o campo indicado e tente novamente.'));
                }
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $bag->first() ?: 'Confira os dados informados.',
                    'errors' => $bag->messages()], $error->status);
            }
            return null;
        });
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $error, \Illuminate\Http\Request $request) {
            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => 'Entre novamente para continuar.'], 401) : null;
        });
        $exceptions->render(function (\Throwable $error, \Illuminate\Http\Request $request) {
            if ($error instanceof \Illuminate\Validation\ValidationException
                || $error instanceof \Illuminate\Auth\AuthenticationException
                || $error instanceof \Illuminate\Http\Exceptions\HttpResponseException) return null;
            $http = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
            $status = $http ? $error->getStatusCode() : 500;
            $fallback = match ($status) {
                401 => 'Entre novamente para continuar.',
                403 => 'Você não possui permissão para esta operação.',
                404 => 'O registro ou a página não foi encontrado.',
                405 => 'Esta operação não está disponível neste endereço.',
                419 => 'Sua sessão expirou. Entre novamente e repita a operação.',
                429 => 'Muitas tentativas em pouco tempo. Aguarde antes de tentar novamente.',
                503 => 'O serviço está indisponível no momento. Tente novamente mais tarde.',
                default => $status >= 500
                    ? 'Não foi possível concluir a operação. Tente novamente. Se o problema continuar, solicite suporte.'
                    : 'Não foi possível concluir a operação. Confira os dados e tente novamente.',
            };
            // Unexpected failures remain in the private exception log, including
            // during development. Debug payloads never become operational UI.
            $message = $http ? \App\Support\UserFacingMessage::fromText($error->getMessage(), $fallback) : $fallback;
            $headers = $http ? $error->getHeaders() : [];
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $message], $status, $headers);
            }
            return response()->view('errors.message', compact('message', 'status'), $status, $headers);
        });
    })->create();

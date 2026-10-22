<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class PdvOperatorAuthorization
{
    /**
     * Login password works before a user configures a personal PIN.
     * PIN is stored as a one-way hash; attempts are throttled per operator/IP.
     */
    public function verify(User $user, string $credential, string $ip): void
    {
        $key='pdv:operator-credential:'.$user->id.':'.hash('sha256',$ip);
        if (RateLimiter::tooManyAttempts($key,5)) {
            throw ValidationException::withMessages([
                'credential'=>'Muitas tentativas. Aguarde antes de tentar novamente.',
            ]);
        }
        $valid=($user->is_active && Hash::check($credential,(string)$user->password))
            || ($user->is_active && (bool)$user->pdv_pin_hash
                && preg_match('/^\d{6}$/D',$credential)===1
                && Hash::check($credential,$user->pdv_pin_hash));
        if (!$valid) {
            RateLimiter::hit($key,120);
            throw ValidationException::withMessages([
                'credential'=>'Senha ou PIN inválido para este operador.',
            ]);
        }
        RateLimiter::clear($key);
    }

    public function setPin(User $user, string $password, string $pin, string $ip): void
    {
        $key='pdv:pin-setup:'.$user->id.':'.hash('sha256',$ip);
        if (RateLimiter::tooManyAttempts($key,5)) {
            throw ValidationException::withMessages([
                'current_password'=>'Muitas tentativas. Tente novamente em instantes.',
            ]);
        }
        if (!Hash::check($password,(string)$user->password)) {
            RateLimiter::hit($key,120);
            throw ValidationException::withMessages([
                'current_password'=>'Senha atual incorreta.',
            ]);
        }
        RateLimiter::clear($key);
        if (!preg_match('/^\d{6}$/D',$pin) || preg_match('/^(\d)\1{5}$/D',$pin)
            || in_array($pin,['123456','654321','012345','111111','000000'],true)) {
            throw ValidationException::withMessages([
                'pin'=>'Crie um PIN de 6 números que não seja uma sequência simples.',
            ]);
        }
        $user->forceFill(['pdv_pin_hash'=>Hash::make($pin)])->save();
    }
}

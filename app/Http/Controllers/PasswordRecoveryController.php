<?php

namespace App\Http\Controllers;

use App\Support\LoginSubdomain;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordRecoveryController extends Controller
{
    public function requestForm()
    {
        abort_if(LoginSubdomain::isPortal(),404);
        return view('auth.password-request');
    }

    public function sendLink(Request $request)
    {
        abort_if(LoginSubdomain::isPortal(),404);
        $data=$request->validate(['email'=>['required','email','max:255']]);
        // Do not expose whether this email exists in the installation.
        Password::sendResetLink(['email'=>strtolower(trim($data['email']))]);
        return back()->with('status','Se o e-mail estiver cadastrado, você receberá instruções para criar uma nova senha.');
    }

    public function resetForm(string $token, Request $request)
    {
        abort_if(LoginSubdomain::isPortal(),404);
        return view('auth.password-reset',['token'=>$token,'email'=>(string)$request->query('email','')]);
    }

    public function reset(Request $request)
    {
        abort_if(LoginSubdomain::isPortal(),404);
        $data=$request->validate([
            'token'=>['required','string'],
            'email'=>['required','email','max:255'],
            'password'=>['required','string','min:8','confirmed'],
        ]);
        $result=Password::reset($data,function ($user,string $password) {
            $user->forceFill([
                'password'=>Hash::make($password),
                'remember_token'=>Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });
        if ($result===Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status','Senha redefinida. Entre com seu usuário.');
        }
        return back()->withErrors(['email'=>'O link expirou ou é inválido. Solicite um novo link.']);
    }
}

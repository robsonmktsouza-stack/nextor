<?php

namespace App\Http\Controllers;

use App\Support\LoginSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function loginForm()
    {
        return view('auth.login', [
            'loginSubdomain'=>LoginSubdomain::current(),
            'loginPortal'=>LoginSubdomain::isPortal(),
            'loginBaseDomain'=>(string)config('instance.base_domain', 'lumeron.com.br'),
        ]);
    }

    /** The central login page never handles passwords: they go to the target CNPJ. */
    public function loginBootstrap(Request $request): JsonResponse
    {
        abort_unless(LoginSubdomain::fromTrustedPortal($request),403);
        return response()->json(['csrf_token'=>csrf_token()],200,LoginSubdomain::corsHeaders());
    }

    public function loginOptions(Request $request)
    {
        abort_unless(LoginSubdomain::fromTrustedPortal($request),403);
        return response('',204,LoginSubdomain::corsHeaders());
    }

    public function login(Request $request)
    {
        abort_if(LoginSubdomain::isPortal(),403);

        $data=$request->validate([
            'subdomain'=>['required','string','regex:/^[a-z][a-z0-9-]{1,38}$/'],
            'username'=>['required','string','max:255'],
            'password'=>['required','string'],
        ]);
        $subdomain=strtolower((string)$data['subdomain']);
        $expected=LoginSubdomain::current();
        if ($subdomain!==$expected) {
            throw ValidationException::withMessages([
                'subdomain'=>'Este endereço pertence a outra empresa. Confira o subdomínio informado.',
            ]);
        }

        $username=trim((string)$data['username']);
        $field=filter_var($username,FILTER_VALIDATE_EMAIL)?'email':'username';
        $credentials=[$field=>strtolower($username),'password'=>$data['password']];
        if (!Auth::attempt($credentials,$request->boolean('remember'))) {
            throw ValidationException::withMessages(['username'=>'Usuário ou senha incorretos.']);
        }
        if (!Auth::user()?->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['username'=>'Este usuário está desativado.']);
        }

        $request->session()->regenerate();
        $redirect=route($request->user()->homeRouteName());
        if (LoginSubdomain::fromTrustedPortal($request)) {
            return response()->json(['redirect'=>$redirect],200,LoginSubdomain::corsHeaders());
        }
        return redirect()->intended($redirect);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}

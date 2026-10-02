<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
    public function loginForm() { return view('auth.login'); }
    public function login(Request $request) {
        $data = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
        if (!Auth::attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        if(!Auth::user()?->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['email'=>'Este usuário está desativado.']);
        }
        $request->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }
    public function logout(Request $request) {
        Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();
        return redirect()->route('login');
    }
}

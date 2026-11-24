<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credenciais = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Bloqueia tentativas em excesso: 5 por minuto por e-mail + IP
        $chave = Str::lower($credenciais['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($chave).' segundos.',
            ]);
        }

        if (! Auth::attempt($credenciais, $request->boolean('lembrar'))) {
            RateLimiter::hit($chave);

            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha incorretos.',
            ]);
        }

        RateLimiter::clear($chave);

        $user = Auth::user();

        if (! $user->ativo) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Seu acesso está bloqueado. Fale com o suporte.',
            ]);
        }

        $request->session()->regenerate();

        $user->forceFill(['ultimo_acesso_em' => now()])->save();
        Auditoria::registrar('login', $user);

        return redirect()->intended(route($user->perfil->rotaPainel()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

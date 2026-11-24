<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegraSenha;
use Illuminate\View\View;

/** "Esqueci minha senha" e redefinição pelo link enviado por e-mail. */
class SenhaController extends Controller
{
    public function solicitar(): View
    {
        return view('auth.esqueci-senha');
    }

    public function enviarLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Mesma resposta exista ou não o e-mail, para não revelar quem é cliente
        return back()->with('sucesso', 'Se este e-mail estiver cadastrado, você receberá um link para criar uma nova senha.');
    }

    public function redefinir(Request $request, string $token): View
    {
        return view('auth.redefinir-senha', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', RegraSenha::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $senha) {
                $user->forceFill([
                    'password' => $senha,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('sucesso', 'Senha alterada. Entre com a nova senha.');
    }
}

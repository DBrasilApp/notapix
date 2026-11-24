<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Nomes curtos gravados nas colunas *_type (carteira, alvo da auditoria)
        Relation::enforceMorphMap([
            'empresa' => Empresa::class,
            'escritorio' => Escritorio::class,
            'nota' => Nota::class,
            'nfse' => \App\Models\Nfse::class,
            'user' => User::class,
        ]);

        // Em desenvolvimento, avisa sobre consultas N+1 e atributos inexistentes
        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // E-mail de "esqueci a senha" em português
        ResetPassword::toMailUsing(function (User $user, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

            return (new MailMessage)
                ->subject('Redefinição de senha - '.config('app.name'))
                ->greeting('Olá, '.$user->name.'!')
                ->line('Recebemos um pedido para redefinir a senha da sua conta.')
                ->action('Criar nova senha', $url)
                ->line('O link vale por 60 minutos. Se você não fez esse pedido, ignore este e-mail.')
                ->salutation('Equipe '.config('app.name'));
        });
    }
}

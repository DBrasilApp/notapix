<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define em qual empresa o usuário está trabalhando na área "empresa":
 *  - usuário de empresa: a própria;
 *  - usuário de escritório: a empresa-cliente escolhida em "Acessar" (vínculo ativo).
 * Depois disso, $request->user()->empresa é sempre a empresa em uso.
 */
class EmpresaAtual
{
    public const SESSAO = 'escritorio.empresa_acessada';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->isEmpresa()) {
            abort_unless($user->empresa, 403, 'Seu usuário não está ligado a nenhuma empresa.');

            return $next($request);
        }

        if ($user->isEscritorio()) {
            $id = $request->session()->get(self::SESSAO);
            $empresa = $id ? $user->empresasAcessiveis()->whereKey($id)->first() : null;

            if (! $empresa) {
                $request->session()->forget(self::SESSAO);

                return redirect()->route('escritorio.painel')
                    ->with('erro', 'Escolha a empresa-cliente em que vai trabalhar (botão "Acessar").');
            }

            $user->setRelation('empresa', $empresa);
            View::share('acessoEscritorio', $user->escritorio);

            return $next($request);
        }

        abort(403, 'Você não tem permissão para acessar esta área.');
    }
}

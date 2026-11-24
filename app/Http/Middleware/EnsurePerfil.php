<?php

namespace App\Http\Middleware;

use App\Enums\Perfil;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Libera a rota só para os perfis informados.
 * Uso: ->middleware('perfil:admin') ou ->middleware('perfil:admin,escritorio')
 */
class EnsurePerfil
{
    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $user = $request->user();

        $permitidos = array_map(fn (string $p) => Perfil::from($p), $perfis);

        if (! $user || ! $user->temPerfil(...$permitidos)) {
            abort(403, 'Você não tem permissão para acessar esta área.');
        }

        return $next($request);
    }
}

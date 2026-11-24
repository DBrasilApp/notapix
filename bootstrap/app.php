<?php

use App\Http\Middleware\EnsurePerfil;
use App\Http\Middleware\EnsureUsuarioAtivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'perfil' => EnsurePerfil::class,
            'ativo' => EnsureUsuarioAtivo::class,
            'empresa.atual' => \App\Http\Middleware\EmpresaAtual::class,
        ]);

        // Visitante tentando abrir área logada vai para o login;
        // usuário logado tentando abrir login/cadastro vai para o painel.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('painel'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

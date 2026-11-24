<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Cfop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta à tabela CFOP. Só devolve CFOPs de saída que podem ser usados em NF-e. */
class CfopController extends Controller
{
    public function buscar(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q'));

        $consulta = Cfop::query()->saidasNfe();

        if ($termo === '') {
            // Pesquisa aberta sem nada digitado: mostra os mais usados, nessa ordem
            $resultados = $consulta->whereIn('codigo', Cfop::MAIS_USADOS)->get()
                ->sortBy(fn (Cfop $c) => array_search($c->codigo, Cfop::MAIS_USADOS))
                ->values();
        } else {
            $resultados = $consulta->pesquisar($termo)->orderBy('codigo')->limit(40)->get();
        }

        return response()->json($resultados->map->paraJson());
    }

    public function mostrar(string $codigo): JsonResponse
    {
        $cfop = Cfop::query()->saidasNfe()->find(preg_replace('/\D/', '', $codigo));

        return $cfop
            ? response()->json($cfop->paraJson())
            : response()->json(['mensagem' => 'CFOP não encontrado ou não é de saída permitido em NF-e.'], 404);
    }
}

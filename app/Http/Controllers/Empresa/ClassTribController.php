<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\ClassTrib;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta à tabela de cClassTrib (IBS/CBS). Só devolve as em vigor e permitidas na NF-e. */
class ClassTribController extends Controller
{
    public function buscar(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q'));
        $consulta = ClassTrib::query()->paraNfe();

        if ($termo === '') {
            $resultados = $consulta->whereIn('codigo', ClassTrib::MAIS_USADOS)->get()
                ->sortBy(fn (ClassTrib $c) => array_search($c->codigo, ClassTrib::MAIS_USADOS))
                ->values();
        } else {
            $resultados = $consulta->pesquisar($termo)->orderBy('codigo')->limit(40)->get();
        }

        return response()->json($resultados->map->paraJson());
    }

    public function mostrar(string $codigo): JsonResponse
    {
        $item = ClassTrib::query()->paraNfe()->find(preg_replace('/\D/', '', $codigo));

        return $item
            ? response()->json($item->paraJson())
            : response()->json(['mensagem' => 'Classificação não encontrada, fora de vigência ou não permitida na NF-e.'], 404);
    }
}

<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Ncm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consulta à tabela NCM usada pela tela de produtos. Só devolve códigos de 8 dígitos em vigor. */
class NcmController extends Controller
{
    public function buscar(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q'));

        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        $resultados = Ncm::query()
            ->vigentes()
            ->pesquisar($termo)
            ->orderBy('codigo')
            ->limit(30)
            ->get()
            ->map->paraJson();

        return response()->json($resultados);
    }

    public function mostrar(string $codigo): JsonResponse
    {
        $ncm = Ncm::query()->vigentes()->find(preg_replace('/\D/', '', $codigo));

        return $ncm
            ? response()->json($ncm->paraJson())
            : response()->json(['mensagem' => 'NCM não encontrado ou fora de vigência.'], 404);
    }
}

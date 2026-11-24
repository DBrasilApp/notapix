<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Rules\Cnpj;
use App\Services\ConsultaCnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dados públicos de um CNPJ (Receita + IE na SEFAZ), para preencher o cadastro de pessoas. */
class CnpjController extends Controller
{
    public function mostrar(Request $request, string $cnpj, ConsultaCnpj $consulta): JsonResponse
    {
        if (! Cnpj::valido($cnpj)) {
            return response()->json(['mensagem' => 'CNPJ inválido. Confira os números.'], 422);
        }

        try {
            $dados = $consulta->consultar($cnpj, $request->user()->empresa);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['mensagem' => 'Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.'], 503);
        }

        if ($dados) {
            $dados['crt'] = ($dados['mei'] ?? false) ? \App\Models\Empresa::CRT_MEI
                : (($dados['simples'] ?? false) ? \App\Models\Empresa::CRT_SIMPLES : null);
        }

        return $dados
            ? response()->json($dados)
            : response()->json(['mensagem' => 'CNPJ não encontrado na Receita Federal.'], 404);
    }
}

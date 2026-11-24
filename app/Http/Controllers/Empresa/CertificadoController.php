<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\Certificado;
use App\Models\Empresa;
use App\Services\Certificado\CertificadoInvalidoException;
use App\Services\Certificado\LeitorCertificado;
use App\Support\Formata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CertificadoController extends Controller
{
    public function show(Request $request): View
    {
        $empresa = $request->user()->empresa;

        return view('empresa.certificado', [
            'empresa' => $empresa,
            'certificado' => $empresa->certificadoAtivo,
        ]);
    }

    public function store(Request $request, LeitorCertificado $leitor): RedirectResponse
    {
        // Se o PHP recusou o envio, mostra o motivo real em vez de uma mensagem genérica
        $enviado = $request->file('arquivo');
        if ($enviado && ! $enviado->isValid()) {
            report(new \RuntimeException('Falha no upload do certificado: código '.$enviado->getError().' - '.$enviado->getErrorMessage()));

            throw ValidationException::withMessages(['arquivo' => $this->motivoFalhaUpload($enviado->getError())]);
        }

        $request->validate([
            'arquivo' => ['required', 'file', 'max:'.config('nfeexpress.certificado_tamanho_max_kb')],
            'senha' => ['required', 'string', 'max:100'],
        ], [], ['arquivo' => 'arquivo do certificado', 'senha' => 'senha do certificado']);

        $arquivo = $request->file('arquivo');

        if (! in_array(strtolower($arquivo->getClientOriginalExtension()), ['pfx', 'p12'], true)) {
            throw ValidationException::withMessages(['arquivo' => 'Envie o arquivo do certificado A1, com extensão .pfx ou .p12.']);
        }

        /** @var Empresa $empresa */
        $empresa = $request->user()->empresa;

        try {
            $lido = $leitor->ler($arquivo->get(), $request->input('senha'));
        } catch (CertificadoInvalidoException $e) {
            throw ValidationException::withMessages(['arquivo' => $e->getMessage()]);
        }

        $this->conferir($lido, $empresa);

        DB::transaction(function () use ($empresa, $lido, $request) {
            // Só um certificado por empresa: o anterior é apagado, não fica guardado à toa
            $empresa->certificados()->delete();

            Certificado::create([
                'empresa_id' => $empresa->id,
                'arquivo_pfx' => base64_encode($lido['pfx']),
                'senha' => $request->input('senha'),
                'cnpj_certificado' => $lido['cnpj'],
                'titular' => $lido['titular'],
                'valido_de' => $lido['valido_de'],
                'valido_ate' => $lido['valido_ate'],
                'ativo' => true,
            ]);
        });

        Auditoria::registrar('certificado_enviado', $empresa, [
            'titular' => $lido['titular'],
            'valido_ate' => $lido['valido_ate']->toDateString(),
            'convertido' => $lido['convertido'],
        ]);

        $mensagem = 'Certificado enviado. Válido até '.$lido['valido_ate']->format('d/m/Y').'.';
        if ($lido['convertido']) {
            $mensagem .= ' Ele estava em um formato antigo e foi convertido automaticamente.';
        }

        return redirect()->route('empresa.certificado')->with('sucesso', $mensagem);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        $empresa->certificados()->delete();

        Auditoria::registrar('certificado_removido', $empresa);

        return redirect()->route('empresa.certificado')->with('sucesso', 'Certificado removido.');
    }

    /** Traduz os códigos de erro de upload do PHP em algo que dê para resolver. */
    private function motivoFalhaUpload(int $codigo): string
    {
        $pasta = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();

        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'O arquivo é maior que o limite do PHP (upload_max_filesize = '.ini_get('upload_max_filesize').').',
            UPLOAD_ERR_PARTIAL =>
                'O envio foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_TMP_DIR =>
                'O PHP não tem uma pasta temporária para receber arquivos (upload_tmp_dir). Ajuste o php.ini do servidor.',
            UPLOAD_ERR_CANT_WRITE =>
                "O PHP não conseguiu gravar o arquivo na pasta temporária ({$pasta}). Verifique se a pasta existe e tem permissão de escrita.",
            UPLOAD_ERR_EXTENSION =>
                'Uma extensão do PHP bloqueou o envio do arquivo.',
            default =>
                "Não foi possível receber o arquivo (código {$codigo}). Pasta temporária do PHP: {$pasta}.",
        };
    }

    /** Regras da SEFAZ: certificado dentro da validade e da mesma raiz de CNPJ do emitente. */
    private function conferir(array $lido, Empresa $empresa): void
    {
        if (! $lido['cnpj']) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não encontramos um CNPJ neste certificado. Envie um certificado e-CNPJ do tipo A1.',
            ]);
        }

        // A SEFAZ compara os 8 primeiros dígitos (raiz): o certificado da matriz serve para as filiais
        if (substr($lido['cnpj'], 0, 8) !== substr($empresa->cnpj, 0, 8)) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este certificado é do CNPJ '.Formata::cnpj($lido['cnpj']).
                    ', diferente do CNPJ da empresa ('.Formata::cnpj($empresa->cnpj).').'.
                    ($empresa->podeAlterarCnpj() ? ' Se o CNPJ da empresa estiver errado, corrija em Dados da empresa.' : ''),
            ]);
        }

        if ($lido['valido_ate']->isPast()) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este certificado venceu em '.$lido['valido_ate']->format('d/m/Y').'. Envie um certificado dentro da validade.',
            ]);
        }

        if ($lido['valido_de']->isFuture()) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este certificado só passa a valer em '.$lido['valido_de']->format('d/m/Y').'.',
            ]);
        }
    }
}

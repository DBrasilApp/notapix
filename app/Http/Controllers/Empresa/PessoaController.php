<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Empresa\AtualizarDadosRequest;
use App\Http\Requests\Empresa\PessoaRequest;
use App\Models\Destinatario;
use App\Models\Empresa;
use App\Services\LocalizacaoIbge;
use App\Support\Formata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Cadastro de pessoas (destinatários das notas), físicas e jurídicas na mesma tela. */
class PessoaController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));
        $numeros = preg_replace('/\D/', '', $busca);

        $pessoas = $this->empresa($request)->destinatarios()
            ->when($busca !== '', function ($query) use ($busca, $numeros) {
                $query->where(function ($q) use ($busca, $numeros) {
                    $q->whereRaw('LOWER(razao_social) LIKE ?', ['%'.mb_strtolower($busca).'%'])
                        ->orWhereRaw('LOWER(nome_fantasia) LIKE ?', ['%'.mb_strtolower($busca).'%']);
                    if ($numeros !== '') {
                        $q->orWhere('cpf_cnpj', 'like', $numeros.'%');
                    }
                });
            })
            ->orderBy('razao_social')
            ->paginate(20)
            ->withQueryString();

        return view('empresa.pessoas.index', compact('pessoas', 'busca'));
    }

    public function create(): View
    {
        return $this->formulario(new Destinatario([
            'tipo_pessoa' => Destinatario::JURIDICA,
            'ind_ie_dest' => Destinatario::IE_CONTRIBUINTE,
            'uf' => config('nfeexpress.uf_padrao'),
        ]));
    }

    public function store(PessoaRequest $request, LocalizacaoIbge $ibge): RedirectResponse
    {
        $empresa = $this->empresa($request);
        $dados = $this->completarMunicipio($request->validated(), $ibge);

        $this->garantirDocumentoUnico($empresa, $dados['cpf_cnpj']);

        // Se a pessoa já foi excluída antes, reaproveita o cadastro em vez de duplicar
        $pessoa = $empresa->destinatarios()->onlyTrashed()->where('cpf_cnpj', $dados['cpf_cnpj'])->first();

        if ($pessoa) {
            $pessoa->restore();
            $pessoa->update($dados);
        } else {
            $empresa->destinatarios()->create($dados);
        }

        return redirect()->route('empresa.pessoas.index')->with('sucesso', 'Pessoa cadastrada.');
    }

    public function edit(Request $request, int $pessoa): View
    {
        return $this->formulario($this->buscar($request, $pessoa));
    }

    public function update(PessoaRequest $request, LocalizacaoIbge $ibge, int $pessoa): RedirectResponse
    {
        $registro = $this->buscar($request, $pessoa);
        $dados = $this->completarMunicipio($request->validated(), $ibge);

        $this->garantirDocumentoUnico($this->empresa($request), $dados['cpf_cnpj'], $registro->id);

        $registro->update($dados);

        return redirect()->route('empresa.pessoas.index')->with('sucesso', 'Cadastro atualizado.');
    }

    public function destroy(Request $request, int $pessoa): RedirectResponse
    {
        // Exclusão lógica: as notas já emitidas guardam uma cópia dos dados e não são afetadas
        $this->buscar($request, $pessoa)->delete();

        return redirect()->route('empresa.pessoas.index')->with('sucesso', 'Pessoa excluída.');
    }

    private function formulario(Destinatario $pessoa): View
    {
        return view('empresa.pessoas.form', [
            'pessoa' => $pessoa,
            'ufs' => AtualizarDadosRequest::UFS,
        ]);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    /** Só encontra pessoas da própria empresa. */
    private function buscar(Request $request, int $id): Destinatario
    {
        return $this->empresa($request)->destinatarios()->findOrFail($id);
    }

    private function garantirDocumentoUnico(Empresa $empresa, string $documento, ?int $ignorarId = null): void
    {
        $existente = $empresa->destinatarios()
            ->where('cpf_cnpj', $documento)
            ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
            ->first();

        if ($existente) {
            $doc = strlen($documento) === 11 ? Formata::cpf($documento) : Formata::cnpj($documento);

            throw ValidationException::withMessages([
                'cpf_cnpj' => "Já existe uma pessoa cadastrada com o documento {$doc}: {$existente->razao_social}.",
            ]);
        }
    }

    private function completarMunicipio(array $dados, LocalizacaoIbge $ibge): array
    {
        if (empty($dados['codigo_municipio'])) {
            $dados['codigo_municipio'] = $ibge->codigoMunicipio($dados['uf'], $dados['municipio']);

            if (! $dados['codigo_municipio']) {
                throw ValidationException::withMessages([
                    'municipio' => 'Não encontramos esta cidade na lista do IBGE. Confira o nome e a UF.',
                ]);
            }
        }

        return $dados;
    }
}

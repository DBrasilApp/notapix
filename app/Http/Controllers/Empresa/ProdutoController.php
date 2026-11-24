<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Empresa\ProdutoRequest;
use App\Models\Cfop;
use App\Models\Empresa;
use App\Models\Ncm;
use App\Models\Produto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProdutoController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));
        $numeros = preg_replace('/\D/', '', $busca);

        $produtos = $this->empresa($request)->produtos()
            ->when($busca !== '', function ($query) use ($busca, $numeros) {
                $query->where(function ($q) use ($busca, $numeros) {
                    $q->whereRaw('LOWER(descricao) LIKE ?', ['%'.mb_strtolower($busca).'%'])
                        ->orWhere('codigo', 'like', $busca.'%')
                        ->orWhere('gtin', $busca);
                    if ($numeros !== '') {
                        $q->orWhere('ncm', 'like', $numeros.'%');
                    }
                });
            })
            ->orderBy('descricao')
            ->paginate(20)
            ->withQueryString();

        return view('empresa.produtos.index', compact('produtos', 'busca'));
    }

    public function create(): View
    {
        return $this->formulario(new Produto(Produto::PADROES_SIMPLES));
    }

    public function store(ProdutoRequest $request): RedirectResponse
    {
        $empresa = $this->empresa($request);
        $dados = $request->validated();

        // O código é sempre o próximo número livre. Se dois cadastros simultâneos
        // pegarem o mesmo número, o banco recusa o segundo e ele tenta o seguinte.
        for ($tentativa = 1; ; $tentativa++) {
            try {
                $produto = $empresa->produtos()->create($dados + ['codigo' => $this->proximoCodigo($empresa)]);
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($tentativa >= 3) {
                    throw $e;
                }
            }
        }

        return redirect()->route('empresa.produtos.index')->with('sucesso', "Produto {$produto->codigo} cadastrado.");
    }

    public function edit(Request $request, int $produto): View
    {
        return $this->formulario($this->buscar($request, $produto));
    }

    public function update(ProdutoRequest $request, int $produto): RedirectResponse
    {
        // O código nunca muda depois de criado
        $this->buscar($request, $produto)->update($request->validated());

        return redirect()->route('empresa.produtos.index')->with('sucesso', 'Produto atualizado.');
    }

    public function destroy(Request $request, int $produto): RedirectResponse
    {
        $this->buscar($request, $produto)->delete();

        return redirect()->route('empresa.produtos.index')->with('sucesso', 'Produto excluído.');
    }

    private function formulario(Produto $produto): View
    {
        return view('empresa.produtos.form', [
            'produto' => $produto,
            'proximoCodigo' => $produto->exists ? null : $this->proximoCodigo(request()->user()->empresa),
            'ncmAtual' => $produto->ncm ? Ncm::find($produto->ncm) : null,
            'cfopAtual' => $produto->cfop_padrao ? Cfop::find($produto->cfop_padrao) : null,
            'tabelaNcm' => Ncm::tabelaImportada(),
        ]);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    private function buscar(Request $request, int $id): Produto
    {
        return $this->empresa($request)->produtos()->findOrFail($id);
    }

    /** Próximo código numérico livre (1, 2, 3...), contando também os excluídos. */
    private function proximoCodigo(Empresa $empresa): string
    {
        $maior = $empresa->produtos()->withTrashed()->pluck('codigo')
            ->filter(fn ($c) => ctype_digit((string) $c))
            ->map(fn ($c) => (int) $c)
            ->max();

        return (string) (($maior ?? 0) + 1);
    }
}

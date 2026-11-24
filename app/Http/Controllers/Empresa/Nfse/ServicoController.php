<?php

namespace App\Http\Controllers\Empresa\Nfse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Empresa\ServicoRequest;
use App\Models\Empresa;
use App\Models\Servico;
use App\Support\TabelaServicosNacional;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Cadastro dos serviços prestados (usados na NFS-e). */
class ServicoController extends Controller
{
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('busca'));

        $servicos = $this->empresa($request)->servicos()
            ->when($busca !== '', fn ($q) => $q->where(function ($q) use ($busca) {
                $q->whereRaw('LOWER(nome) LIKE ?', ['%'.mb_strtolower($busca).'%'])
                    ->orWhere('codigo', $busca)
                    ->orWhere('ctribnac', 'like', TabelaServicosNacional::limpar($busca).'%');
            }))
            ->orderBy('nome')
            ->paginate(20)
            ->withQueryString();

        return view('empresa.servicos.index', compact('servicos', 'busca'));
    }

    public function create(): View
    {
        return view('empresa.servicos.form', ['servico' => new Servico()]);
    }

    public function store(ServicoRequest $request): RedirectResponse
    {
        $empresa = $this->empresa($request);

        for ($tentativa = 1; ; $tentativa++) {
            try {
                $servico = $empresa->servicos()->create($request->validated() + ['codigo' => $this->proximoCodigo($empresa)]);
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($tentativa >= 3) {
                    throw $e;
                }
            }
        }

        return redirect()->route($request->input('voltar') === 'emitir' ? 'empresa.nfse.create' : 'empresa.servicos.index',
            $request->input('voltar') === 'emitir' ? ['servico' => $servico->id] : [])
            ->with('sucesso', "Serviço \"{$servico->nome}\" cadastrado.");
    }

    public function edit(Request $request, int $servico): View
    {
        return view('empresa.servicos.form', ['servico' => $this->buscar($request, $servico)]);
    }

    public function update(ServicoRequest $request, int $servico): RedirectResponse
    {
        $this->buscar($request, $servico)->update($request->validated());

        return redirect()->route('empresa.servicos.index')->with('sucesso', 'Serviço atualizado.');
    }

    public function destroy(Request $request, int $servico): RedirectResponse
    {
        $registro = $this->buscar($request, $servico);

        if ($this->empresa($request)->nfseContratos()->where('servico_id', $registro->id)->where('ativo', true)->exists()) {
            return back()->with('erro', 'Este serviço está em uso em clientes mensais. Troque o serviço deles ou desative-os antes de excluir.');
        }

        $registro->delete();

        return redirect()->route('empresa.servicos.index')->with('sucesso', 'Serviço excluído.');
    }

    /** Pesquisa na lista nacional (campo de pesquisa do formulário). */
    public function buscarCodigo(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q'));

        return response()->json(mb_strlen($termo) < 2 ? [] : TabelaServicosNacional::pesquisar($termo));
    }

    public function mostrarCodigo(string $codigo): JsonResponse
    {
        $codigo = TabelaServicosNacional::limpar($codigo);

        return TabelaServicosNacional::existe($codigo)
            ? response()->json(TabelaServicosNacional::paraJson($codigo))
            : response()->json(['mensagem' => 'Código não encontrado na lista nacional de serviços.'], 404);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    private function buscar(Request $request, int $id): Servico
    {
        return $this->empresa($request)->servicos()->findOrFail($id);
    }

    private function proximoCodigo(Empresa $empresa): string
    {
        $maior = $empresa->servicos()->withTrashed()->pluck('codigo')
            ->filter(fn ($c) => ctype_digit((string) $c))->map(fn ($c) => (int) $c)->max();

        return (string) (($maior ?? 0) + 1);
    }
}

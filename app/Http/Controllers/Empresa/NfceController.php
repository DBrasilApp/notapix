<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Nota;
use App\Services\Nfe\EmissorNfe;
use App\Services\Nfe\EventosNfe;
use App\Services\Nfe\SalvarNfce;
use App\Services\Nfe\ValidadorEmissao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** NFC-e (cupom fiscal): venda rápida no caixa, cupom na tela e cancelamento em até 30 minutos. */
class NfceController extends Controller
{
    public function index(Request $request): View
    {
        return view('empresa.nfce.index', [
            'notas' => $this->empresa($request)->notas()->where('modelo', 65)
                ->orderByRaw('numero IS NULL DESC')->orderByDesc('numero')->orderByDesc('id')
                ->paginate(30),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->caixa($request, new Nota(['modelo' => 65]));
    }

    public function edit(Request $request, int $nota): View|RedirectResponse
    {
        $registro = $this->buscar($request, $nota)->load('itens');
        if (! $registro->podeEditar()) {
            return redirect()->route('empresa.nfce.show', $registro->id);
        }

        return $this->caixa($request, $registro);
    }

    public function store(Request $request, SalvarNfce $salvar, ValidadorEmissao $validador, EmissorNfe $emissor): RedirectResponse
    {
        $nota = $salvar->salvar($this->empresa($request), $request->user(), $this->validar($request));

        return $this->emitir($request, $nota, $validador, $emissor, novo: true);
    }

    public function update(Request $request, SalvarNfce $salvar, ValidadorEmissao $validador, EmissorNfe $emissor, int $nota): RedirectResponse
    {
        $registro = $this->buscar($request, $nota);
        if (! $registro->podeEditar()) {
            return redirect()->route('empresa.nfce.show', $registro->id);
        }

        $registro = $salvar->salvar($this->empresa($request), $request->user(), $this->validar($request), $registro);

        return $this->emitir($request, $registro, $validador, $emissor, novo: false);
    }

    /** O cupom na tela (ou a situação, se não foi autorizado). */
    public function show(Request $request, int $nota): View
    {
        $registro = $this->buscar($request, $nota)->load(['itens', 'eventos' => fn ($q) => $q->latest('id')]);

        return view('empresa.nfce.show', [
            'nota' => $registro,
            'linkConsulta' => $this->linkConsulta($registro),
            'impedimentoCancelamento' => EventosNfe::impedimentoCancelamento($registro),
            'limiteCancelamento' => EventosNfe::limiteCancelamento($registro),
        ]);
    }

    /**
     * Link de consulta do cupom: o mesmo endereço do QR Code (abre a nota direto no site da SEFAZ).
     */
    private function linkConsulta(Nota $nota): ?string
    {
        if (! $nota->xml_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($nota->xml_path)) {
            return null;
        }

        $xml = (string) \Illuminate\Support\Facades\Storage::disk('local')->get($nota->xml_path);
        if (! preg_match('/<qrCode>(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?<\/qrCode>/s', $xml, $m)) {
            return null;
        }

        $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function emitir(Request $request, Nota $nota, ValidadorEmissao $validador, EmissorNfe $emissor, bool $novo): RedirectResponse
    {
        $problemas = $validador->problemas($nota->load('itens'));

        if ($problemas) {
            // Venda nova que nem pode ser enviada: não deixa rascunho para trás
            if ($novo && $nota->numero === null) {
                $nota->itens()->delete();
                $nota->delete();
            }

            return back()->withInput()->withErrors(['emissao' => $problemas]);
        }

        try {
            $nota = $emissor->preparar($nota);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['emissao' => [$e->getMessage()]]);
        }

        // No caixa o cliente está esperando: envia na hora, sem fila
        $emissor->transmitir($nota);

        return redirect()->route('empresa.nfce.show', $nota->id);
    }

    private function validar(Request $request): array
    {
        $numero = fn ($v) => is_string($v) && str_contains($v, ',') ? str_replace(',', '.', str_replace('.', '', $v)) : $v;
        $request->merge([
            'itens' => array_map(fn ($i) => is_array($i) ? ['quantidade' => $numero($i['quantidade'] ?? null), 'desconto' => $numero($i['desconto'] ?? null)] + $i : $i, (array) $request->input('itens', [])),
            'pagamentos' => array_map(fn ($p) => is_array($p) ? ['valor' => $numero($p['valor'] ?? null)] + $p : $p, (array) $request->input('pagamentos', [])),
        ]);

        return $request->validate([
            'itens' => ['required', 'array', 'min:1', 'max:990'],
            'itens.*.produto_id' => ['required', 'integer'],
            'itens.*.quantidade' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'itens.*.desconto' => ['nullable', 'numeric', 'min:0'],
            'pagamentos' => ['required', 'array', 'min:1', 'max:10'],
            'pagamentos.*.forma' => ['required', Rule::in(array_keys(SalvarNfce::PAGAMENTOS))],
            'pagamentos.*.valor' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'cpf_cnpj' => ['nullable', 'string', 'max:18'],
            'nome' => ['nullable', 'string', 'max:60'],
        ], [
            'itens.required' => 'Adicione pelo menos um produto.',
            'itens.min' => 'Adicione pelo menos um produto.',
            'pagamentos.required' => 'Informe como o cliente vai pagar.',
        ]);
    }

    private function caixa(Request $request, Nota $nota): View
    {
        $empresa = $this->empresa($request);

        return view('empresa.nfce.caixa', [
            'nota' => $nota,
            'empresa' => $empresa,
            'pendencias' => $empresa->pendenciasParaEmitir(),
            'produtos' => $empresa->produtos()->orderBy('descricao')->get(['id', 'codigo', 'gtin', 'descricao', 'unidade', 'valor_unitario'])
                ->map(fn ($p) => [
                    'id' => $p->id, 'codigo' => (string) $p->codigo, 'gtin' => $p->gtin === 'SEM GTIN' ? '' : (string) $p->gtin,
                    'descricao' => $p->descricao, 'unidade' => $p->unidade, 'preco' => (float) $p->valor_unitario,
                ])->values(),
            'itens' => old('itens', $nota->exists ? $nota->itens->map(fn ($i) => [
                'produto_id' => $i->produto_id, 'quantidade' => (float) $i->quantidade, 'desconto' => (float) $i->valor_desconto,
            ])->all() : []),
            'pagamentos' => old('pagamentos', $nota->pagamentos ?? []),
        ]);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->user()->empresa;
    }

    private function buscar(Request $request, int $id): Nota
    {
        return $this->empresa($request)->notas()->where('modelo', 65)->findOrFail($id);
    }
}

<?php

namespace App\Http\Controllers\Escritorio;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EmpresaAtual;
use App\Http\Requests\Empresa\AtualizarDadosRequest;
use App\Models\Auditoria;
use App\Models\CreditoMovimento;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\User;
use App\Rules\Cnpj;
use App\Services\ArquivosDoMes;
use App\Services\ConsultaCnpj;
use App\Services\LocalizacaoIbge;
use App\Support\RegrasEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** O escritório contábil e suas empresas-clientes. */
class EmpresaController extends Controller
{
    public function create(Request $request): View
    {
        return view('escritorio.empresas.create', [
            'empresa' => new Empresa(['uf' => $this->escritorio($request)->uf ?: config('nfeexpress.uf_padrao')]),
            'ufs' => AtualizarDadosRequest::UFS,
        ]);
    }

    /** Dados públicos do CNPJ para o cadastro do cliente. */
    public function cnpj(Request $request, string $cnpj, ConsultaCnpj $consulta): JsonResponse
    {
        if (! Cnpj::valido($cnpj)) {
            return response()->json(['mensagem' => 'CNPJ inválido. Confira os números.'], 422);
        }

        $existente = Empresa::where('cnpj', $cnpj)->first();
        if ($existente) {
            $nosso = $this->escritorio($request)->empresasAtivas()->whereKey($existente->id)->exists();

            return response()->json(['mensagem' => $nosso
                ? 'Esta empresa já é sua cliente no NotaPix.'
                : 'Esta empresa já usa o NotaPix. Peça para ela autorizar o seu escritório em "Contador", no menu dela.'], 409);
        }

        try {
            $d = $consulta->receita($cnpj);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['mensagem' => 'Não foi possível consultar o CNPJ agora. Preencha os dados manualmente.'], 503);
        }

        if (! $d) {
            return response()->json(['mensagem' => 'CNPJ não encontrado na Receita Federal.'], 404);
        }

        $d = ConsultaCnpj::comIePublica($d + $consulta->inscricaoEstadual($cnpj, $d['uf'], null));
        $d['crt'] = ($d['mei'] ?? false) ? Empresa::CRT_MEI : (($d['simples'] ?? false) ? Empresa::CRT_SIMPLES : null);

        return response()->json($d);
    }

    /** O escritório cadastra a empresa-cliente (já vinculada) e, se quiser, cria o acesso do cliente. */
    public function store(Request $request): RedirectResponse
    {
        $escritorio = $this->escritorio($request);
        $request->merge(['cnpj' => Cnpj::somenteNumeros($request->input('cnpj'))]);
        RegrasEmpresa::preparar($request);

        $criarAcesso = $request->boolean('criar_acesso');
        $dados = $request->validate(RegrasEmpresa::regras() + [
            'cnpj' => ['required', 'digits:14', new Cnpj, Rule::unique('empresas', 'cnpj')],
            'email' => ['required', 'email', 'max:150'],
            'responsavel' => [$criarAcesso ? 'required' : 'nullable', 'string', 'max:150'],
            'email_acesso' => $criarAcesso ? ['required', 'email', 'max:150', Rule::unique('users', 'email')] : ['nullable'],
        ], RegrasEmpresa::mensagens() + [
            'cnpj.unique' => 'Esta empresa já usa o NotaPix. Peça para ela autorizar o seu escritório em "Contador", no menu dela.',
            'email_acesso.unique' => 'Já existe um usuário com este e-mail.',
        ], RegrasEmpresa::atributos() + ['email' => 'e-mail da empresa', 'responsavel' => 'nome do responsável', 'email_acesso' => 'e-mail de acesso do cliente']);

        if (empty($dados['codigo_municipio'])) {
            $dados['codigo_municipio'] = app(LocalizacaoIbge::class)->codigoMunicipio($dados['uf'], $dados['municipio']);
            if (! $dados['codigo_municipio']) {
                return back()->withInput()->withErrors(['municipio' => 'Não encontramos esta cidade na lista do IBGE. Confira o nome e a UF.']);
            }
        }

        $cliente = DB::transaction(function () use ($dados, $escritorio, $criarAcesso) {
            $empresa = Empresa::create(collect($dados)->only(RegrasEmpresa::CAMPOS)->all() + ['ambiente' => Empresa::AMBIENTE_HOMOLOGACAO]);
            $escritorio->empresas()->attach($empresa->id, [
                'status' => 'ativo', 'solicitado_por' => 'escritorio', 'autorizado_em' => now(),
            ]);

            $usuario = $criarAcesso ? User::create([
                'name' => $dados['responsavel'],
                'email' => mb_strtolower($dados['email_acesso']),
                'password' => Str::random(40), // o cliente define a senha pelo link do convite
                'perfil' => Perfil::Empresa,
                'empresa_id' => $empresa->id,
            ]) : null;

            return [$empresa, $usuario];
        });
        [$empresa, $usuario] = $cliente;

        Auditoria::registrar('empresa_cadastrada_pelo_escritorio', $empresa, ['escritorio_id' => $escritorio->id]);

        $mensagem = "{$empresa->razao_social} cadastrada. Agora acesse a empresa para enviar o certificado digital.";
        if ($usuario) {
            // Convite: link para o cliente criar a senha (o mesmo de "esqueci a senha")
            try {
                Password::sendResetLink(['email' => $usuario->email]);
                $mensagem .= " Enviamos para {$usuario->email} um link para o cliente criar a senha.";
            } catch (\Throwable $e) {
                report($e);
                $mensagem .= ' Não foi possível enviar o e-mail de convite agora: o cliente pode usar "Esqueci a senha" na tela de entrada.';
            }
        }

        return redirect()->route('escritorio.painel')->with('sucesso', $mensagem);
    }

    /** Passa a trabalhar dentro da empresa-cliente. */
    public function acessar(Request $request, int $empresa): RedirectResponse
    {
        $cliente = $this->cliente($request, $empresa);
        $request->session()->put(EmpresaAtual::SESSAO, $cliente->id);

        return redirect()->route('empresa.painel');
    }

    public function sair(Request $request): RedirectResponse
    {
        $request->session()->forget(EmpresaAtual::SESSAO);

        return redirect()->route('escritorio.painel');
    }

    /** Aceita o pedido de uma empresa que autorizou o escritório. */
    public function aceitar(Request $request, int $empresa): RedirectResponse
    {
        $escritorio = $this->escritorio($request);
        $pedido = $escritorio->empresas()->wherePivot('status', 'pendente')->whereKey($empresa)->firstOrFail();

        $escritorio->empresas()->updateExistingPivot($pedido->id, ['status' => 'ativo', 'autorizado_em' => now()]);
        Auditoria::registrar('vinculo_aceito', $pedido, ['escritorio_id' => $escritorio->id]);

        return back()->with('sucesso', "{$pedido->razao_social} agora é sua cliente.");
    }

    /** Recusa um pedido ou deixa de atender a empresa. */
    public function desvincular(Request $request, int $empresa): RedirectResponse
    {
        $escritorio = $this->escritorio($request);
        $cliente = $escritorio->empresas()->wherePivotIn('status', ['ativo', 'pendente'])->whereKey($empresa)->firstOrFail();

        $escritorio->empresas()->updateExistingPivot($cliente->id, [
            'status' => 'revogado', 'revogado_em' => now(), 'usar_saldo_escritorio' => false,
        ]);
        if ((int) $request->session()->get(EmpresaAtual::SESSAO) === $cliente->id) {
            $request->session()->forget(EmpresaAtual::SESSAO);
        }
        Auditoria::registrar('vinculo_encerrado', $cliente, ['escritorio_id' => $escritorio->id]);

        return back()->with('sucesso', "O escritório não atende mais {$cliente->razao_social}.");
    }

    /** Repassa créditos do escritório para a empresa. */
    public function transferir(Request $request, int $empresa): RedirectResponse
    {
        $dados = $request->validate(
            ['quantidade' => ['required', 'integer', 'min:1', 'max:100000']],
            ['quantidade.min' => 'Informe quantos créditos repassar.'],
        );
        $cliente = $this->cliente($request, $empresa);
        $quantidade = (int) $dados['quantidade'];

        $ok = DB::transaction(function () use ($request, $cliente, $quantidade) {
            // Trava o escritório: dois repasses ao mesmo tempo não passam do saldo
            $escritorio = Escritorio::query()->lockForUpdate()->findOrFail($this->escritorio($request)->id);
            if ($escritorio->saldoCreditos() < $quantidade) {
                return false;
            }

            $comum = ['tipo' => CreditoMovimento::TRANSFERENCIA, 'user_id' => $request->user()->id];
            CreditoMovimento::create($comum + [
                'carteira_type' => $escritorio->getMorphClass(), 'carteira_id' => $escritorio->id,
                'quantidade' => -$quantidade, 'observacao' => "Repasse para {$cliente->razao_social}",
            ]);
            CreditoMovimento::create($comum + [
                'carteira_type' => $cliente->getMorphClass(), 'carteira_id' => $cliente->id,
                'quantidade' => $quantidade, 'observacao' => "Repasse do escritório {$escritorio->razao_social}",
            ]);

            return true;
        });

        return $ok
            ? back()->with('sucesso', "{$quantidade} crédito(s) repassado(s) para {$cliente->razao_social}.")
            : back()->with('erro', 'O escritório não tem créditos suficientes para este repasse.');
    }

    /** Liga/desliga: a empresa usa os créditos do escritório quando os dela acabarem. */
    public function saldoCompartilhado(Request $request, int $empresa): RedirectResponse
    {
        $cliente = $this->cliente($request, $empresa);
        $ligar = $request->boolean('ligar');
        $this->escritorio($request)->empresas()->updateExistingPivot($cliente->id, ['usar_saldo_escritorio' => $ligar]);

        return back()->with('sucesso', $ligar
            ? "Quando os créditos de {$cliente->razao_social} acabarem, as notas usarão os créditos do escritório."
            : "{$cliente->razao_social} passa a usar só os próprios créditos.");
    }

    /** .zip com os XMLs do mês de uma empresa ou de todas. */
    public function arquivosDoMes(Request $request, ArquivosDoMes $arquivos): View|Response|RedirectResponse
    {
        $escritorio = $this->escritorio($request);
        $empresas = $escritorio->empresasAtivas()->orderBy('razao_social')->get();

        if (! $request->has('mes')) {
            return view('escritorio.arquivos', ['empresas' => $empresas]);
        }

        $mes = ArquivosDoMes::mes($request->query('mes'));
        $escolhidas = $request->query('empresa') ? $empresas->where('id', (int) $request->query('empresa'))->values() : $empresas;
        abort_if($escolhidas->isEmpty(), 404);

        $zip = $arquivos->gerar($escolhidas, $mes);
        if (! $zip) {
            return back()->with('erro', 'Nenhuma nota autorizada, cancelada ou denegada em '.$mes->format('m/Y').'.');
        }

        $nome = $escolhidas->count() === 1 ? $escolhidas->first()->cnpj : 'todas-as-empresas';

        return response()->download($zip, "NFe-{$nome}-{$mes->format('Y-m')}.zip")->deleteFileAfterSend();
    }

    private function escritorio(Request $request): Escritorio
    {
        $escritorio = $request->user()->escritorio;
        abort_unless($escritorio, 403, 'Seu usuário não está ligado a um escritório.');

        return $escritorio;
    }

    /** Empresa-cliente com vínculo ativo (outra empresa dá 404). */
    private function cliente(Request $request, int $empresa): Empresa
    {
        return $this->escritorio($request)->empresasAtivas()->whereKey($empresa)->firstOrFail();
    }
}

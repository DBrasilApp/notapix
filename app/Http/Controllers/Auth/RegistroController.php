<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\AceiteTermo;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\User;
use App\Http\Requests\Empresa\AtualizarDadosRequest;
use App\Rules\Cnpj;
use App\Support\RegrasEmpresa;
use App\Rules\InscricaoEstadual;
use App\Services\ConsultaCnpj;
use App\Services\LocalizacaoIbge;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Cadastro público: uma Empresa (emitente) ou um Escritório Contábil.
 * O Administrador não se cadastra por aqui: é criado pelo seeder.
 */
class RegistroController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.registro', [
            'tipo' => $request->query('tipo') === 'escritorio' ? 'escritorio' : 'empresa',
            'empresa' => new Empresa(['uf' => config('nfeexpress.uf_padrao')]),
            'ufs' => AtualizarDadosRequest::UFS,
        ]);
    }

    /** Dados públicos do CNPJ (Receita) para preencher o cadastro da empresa. */
    public function cnpj(string $cnpj, ConsultaCnpj $consulta): JsonResponse
    {
        if (! Cnpj::valido($cnpj)) {
            return response()->json(['mensagem' => 'CNPJ inválido. Confira os números.'], 422);
        }
        if (Empresa::where('cnpj', $cnpj)->exists()) {
            return response()->json(['mensagem' => 'Este CNPJ já tem conta no NotaPix. Use "Entrar" ou "Esqueci minha senha".'], 409);
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

        // Regime: MEI, Simples ou nenhum dos dois (ainda não atendido)
        $d['crt'] = ($d['mei'] ?? false) ? Empresa::CRT_MEI : (($d['simples'] ?? false) ? Empresa::CRT_SIMPLES : null);

        return response()->json($d);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['cnpj' => Cnpj::somenteNumeros($request->input('cnpj'))]);

        $tabela = $request->input('tipo') === 'escritorio' ? 'escritorios' : 'empresas';
        $ehEmpresa = $request->input('tipo') !== 'escritorio';

        if ($ehEmpresa) {
            RegrasEmpresa::preparar($request);
        }
        $regrasEmpresa = $ehEmpresa ? RegrasEmpresa::regras() : [];

        $dados = $request->validate($regrasEmpresa + [
            'tipo' => ['required', Rule::in(['empresa', 'escritorio'])],
            'cnpj' => ['required', 'digits:14', new Cnpj, Rule::unique($tabela, 'cnpj')],
            'razao_social' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:150', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'aceite' => ['accepted'],
        ], [
            'aceite.accepted' => 'É preciso aceitar os Termos de Uso e a Política de Privacidade.',
        ] + RegrasEmpresa::mensagens(), RegrasEmpresa::atributos());

        // Código IBGE da cidade (exigido na NF-e): vem do CEP; se não veio, procura pelo nome
        if ($ehEmpresa && empty($dados['codigo_municipio'])) {
            $dados['codigo_municipio'] = app(LocalizacaoIbge::class)->codigoMunicipio($dados['uf'], $dados['municipio']);
            if (! $dados['codigo_municipio']) {
                return back()->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors(['municipio' => 'Não encontramos esta cidade na lista do IBGE. Confira o nome e a UF.']);
            }
        }

        $user = DB::transaction(function () use ($dados, $request) {
            if ($dados['tipo'] === 'escritorio') {
                $escritorio = Escritorio::create([
                    'razao_social' => $dados['razao_social'],
                    'cnpj' => $dados['cnpj'],
                    'email' => $dados['email'],
                ]);
                $vinculo = ['perfil' => Perfil::Escritorio, 'escritorio_id' => $escritorio->id];
            } else {
                $empresa = Empresa::create(collect($dados)->only(RegrasEmpresa::CAMPOS)->all() + ['ambiente' => Empresa::AMBIENTE_HOMOLOGACAO]);
                $vinculo = ['perfil' => Perfil::Empresa, 'empresa_id' => $empresa->id];
            }

            $user = User::create([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'password' => $dados['password'],
                ...$vinculo,
            ]);

            foreach (['termos_uso' => 'termos_versao', 'privacidade' => 'privacidade_versao'] as $documento => $config) {
                AceiteTermo::create([
                    'user_id' => $user->id,
                    'documento' => $documento,
                    'versao' => config("nfeexpress.$config"),
                    'ip' => $request->ip(),
                    'aceito_em' => now(),
                ]);
            }

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        Auditoria::registrar('cadastro', $user, ['perfil' => $user->perfil->value]);

        // Empresa: segue direto para o próximo passo, o certificado digital
        if ($user->perfil === Perfil::Empresa) {
            return redirect()->route('empresa.certificado')
                ->with('sucesso', 'Conta criada! Falta só um passo para emitir: envie o certificado digital A1 da empresa.');
        }

        return redirect()->route($user->perfil->rotaPainel())
            ->with('sucesso', 'Cadastro concluído. Bem-vindo!');
    }
}

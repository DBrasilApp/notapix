<?php

namespace App\Services;

use App\Models\Destinatario;
use App\Models\Empresa;
use App\Services\Nfe\CadastroSefaz;
use App\Services\Nfe\FabricaClienteSefaz;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Rules\InscricaoEstadual;
use Illuminate\Support\Facades\Log;

/**
 * Busca os dados de um CNPJ para preencher cadastros automaticamente:
 *  - Receita Federal (via BrasilAPI): razão social, endereço, contato, situação;
 *  - SEFAZ (consulta cadastro, com o certificado da empresa): inscrição estadual.
 * Tudo é "melhor esforço": o que não vier, o usuário preenche.
 */
class ConsultaCnpj
{
    public function __construct(private FabricaClienteSefaz $fabrica)
    {
    }

    /** @return array<string, mixed>|null null = CNPJ não encontrado na Receita */
    public function consultar(string $cnpj, ?Empresa $empresa = null): ?array
    {
        $receita = $this->receita($cnpj);

        if ($receita === null) {
            return null;
        }

        return self::comIePublica($receita + $this->inscricaoEstadual($cnpj, $receita['uf'], $empresa));
    }

    /**
     * Dados públicos da Receita. Tenta mais de um serviço gratuito, porque cada um às vezes
     * fica fora do ar ou não tem empresas recentes: BrasilAPI, CNPJ.ws e ReceitaWS.
     *
     * @return array<string, mixed>|null null = nenhum serviço encontrou o CNPJ
     */
    public function receita(string $cnpj): ?array
    {
        $chave = "cnpj.receita.$cnpj";
        if (Cache::has($chave)) {
            return Cache::get($chave);
        }

        $falhas = 0;
        foreach (['brasilApi', 'cnpjWs', 'receitaWs'] as $servico) {
            try {
                $dados = $this->{$servico}($cnpj);
            } catch (\Throwable $e) {
                Log::info("Consulta de CNPJ ($servico) falhou: ".$e->getMessage());
                $falhas++;

                continue;
            }

            if ($dados && $dados['razao_social'] !== '') {
                Cache::put($chave, $dados, now()->addDay());

                return $dados;
            }
        }

        // Algum serviço fora do ar: não dá para afirmar que o CNPJ não existe
        if ($falhas > 0) {
            throw new \RuntimeException('Serviço de consulta de CNPJ indisponível.');
        }

        return null;
    }

    private function brasilApi(string $cnpj): ?array
    {
        $d = $this->buscar(config('nfeexpress.cnpj_url').$cnpj);
        if ($d === null) {
            return null;
        }

        return $this->montar(
            razao: $d['razao_social'] ?? '', fantasia: $d['nome_fantasia'] ?? '',
            tipoRua: $d['descricao_tipo_de_logradouro'] ?? '', rua: $d['logradouro'] ?? '', numero: $d['numero'] ?? '',
            complemento: $d['complemento'] ?? '', bairro: $d['bairro'] ?? '', municipio: $d['municipio'] ?? '',
            uf: $d['uf'] ?? '', cep: $d['cep'] ?? '', ibge: $d['codigo_municipio_ibge'] ?? null,
            email: $d['email'] ?? '', telefone: $d['ddd_telefone_1'] ?? '', situacao: $d['descricao_situacao_cadastral'] ?? '',
            mei: (bool) ($d['opcao_pelo_mei'] ?? false), simples: (bool) ($d['opcao_pelo_simples'] ?? false),
            cnae: $d['cnae_fiscal'] ?? '',
        );
    }

    private function cnpjWs(string $cnpj): ?array
    {
        $d = $this->buscar(config('nfeexpress.cnpj_ws_url').$cnpj);
        if ($d === null) {
            return null;
        }
        $e = $d['estabelecimento'] ?? [];
        $sim = fn ($v) => in_array(mb_strtolower((string) $v), ['sim', 's', 'true', '1'], true);
        $uf = strtoupper((string) ($e['estado']['sigla'] ?? ''));
        // A CNPJ.ws também traz as inscrições estaduais (útil quando não há certificado para consultar a SEFAZ)
        $ie = collect($e['inscricoes_estaduais'] ?? [])
            ->first(fn ($i) => ($i['ativo'] ?? false) && strtoupper((string) ($i['estado']['sigla'] ?? '')) === $uf);

        return ['ie_publica' => $ie ? InscricaoEstadual::normalizar($ie['inscricao_estadual'] ?? '', $uf) : null] + $this->montar(
            razao: $d['razao_social'] ?? '', fantasia: $e['nome_fantasia'] ?? '',
            tipoRua: $e['tipo_logradouro'] ?? '', rua: $e['logradouro'] ?? '', numero: $e['numero'] ?? '',
            complemento: $e['complemento'] ?? '', bairro: $e['bairro'] ?? '', municipio: $e['cidade']['nome'] ?? '',
            uf: $e['estado']['sigla'] ?? '', cep: $e['cep'] ?? '', ibge: $e['cidade']['ibge_id'] ?? null,
            email: $e['email'] ?? '', telefone: ($e['ddd1'] ?? '').($e['telefone1'] ?? ''), situacao: $e['situacao_cadastral'] ?? '',
            mei: $sim($d['simples']['mei'] ?? null), simples: $sim($d['simples']['simples'] ?? null),
            cnae: $e['atividade_principal']['subclasse'] ?? ($e['atividade_principal']['id'] ?? ''),
        );
    }

    private function receitaWs(string $cnpj): ?array
    {
        $d = $this->buscar(config('nfeexpress.receitaws_url').$cnpj);
        if ($d === null || ($d['status'] ?? 'OK') === 'ERROR') {
            return null;
        }

        return $this->montar(
            razao: $d['nome'] ?? '', fantasia: $d['fantasia'] ?? '',
            tipoRua: '', rua: $d['logradouro'] ?? '', numero: $d['numero'] ?? '',
            complemento: $d['complemento'] ?? '', bairro: $d['bairro'] ?? '', municipio: $d['municipio'] ?? '',
            uf: $d['uf'] ?? '', cep: $d['cep'] ?? '', ibge: null,
            email: $d['email'] ?? '', telefone: explode('/', (string) ($d['telefone'] ?? ''))[0], situacao: $d['situacao'] ?? '',
            mei: (bool) ($d['simei']['optante'] ?? false), simples: (bool) ($d['simples']['optante'] ?? false),
            cnae: $d['atividade_principal'][0]['code'] ?? '',
        );
    }

    /** GET com JSON. null = não encontrado; exceção = serviço com problema. */
    private function buscar(string $url): ?array
    {
        $resposta = Http::timeout(10)->acceptJson()->withHeaders(['User-Agent' => 'NotaPix'])->get($url);

        if (in_array($resposta->status(), [400, 404], true)) {
            return null;
        }
        if (! $resposta->successful() || ! is_array($resposta->json())) {
            throw new \RuntimeException('HTTP '.$resposta->status());
        }

        return $resposta->json();
    }

    private function montar(
        string $razao, ?string $fantasia, ?string $tipoRua, ?string $rua, ?string $numero, ?string $complemento,
        ?string $bairro, ?string $municipio, ?string $uf, ?string $cep, mixed $ibge, ?string $email, ?string $telefone,
        ?string $situacao, bool $mei, bool $simples, mixed $cnae,
    ): array {
        $limpar = fn ($v) => trim(preg_replace('/\s+/', ' ', (string) $v));
        $tipoRua = $limpar($tipoRua);
        $rua = $limpar($rua);
        if ($tipoRua !== '' && ! str_starts_with(mb_strtoupper($rua), mb_strtoupper($tipoRua).' ')) {
            $rua = trim("$tipoRua $rua");
        }
        $situacao = $limpar($situacao);
        $telefone = preg_replace('/\D/', '', (string) $telefone);
        $cnae = preg_replace('/\D/', '', (string) $cnae);

        return [
            'razao_social' => mb_substr($limpar($razao), 0, 60),
            'nome_fantasia' => mb_substr($limpar($fantasia), 0, 60),
            'cep' => preg_replace('/\D/', '', (string) $cep),
            'logradouro' => mb_substr($rua, 0, 60),
            'numero' => mb_substr($limpar($numero) ?: 'S/N', 0, 60),
            'complemento' => mb_substr($limpar($complemento), 0, 60),
            'bairro' => mb_substr($limpar($bairro), 0, 60),
            'municipio' => $limpar($municipio),
            'uf' => strtoupper($limpar($uf)),
            'codigo_municipio' => preg_match('/^\d{7}$/', (string) $ibge) ? (string) $ibge : null,
            'email' => mb_strtolower($limpar($email)) ?: null,
            'telefone' => strlen($telefone) >= 10 ? substr($telefone, 0, 11) : null,
            'situacao' => $situacao,
            'ativa' => mb_strtoupper($situacao) === 'ATIVA',
            'mei' => $mei,
            'simples' => $simples || $mei,
            'cnae' => strlen($cnae) === 7 ? $cnae : null,
        ];
    }

    /**
     * Inscrição estadual pela SEFAZ da UF do CNPJ. A consulta exige um certificado ICP-Brasil:
     * usa o da empresa e, se ela ainda não tiver (ex.: no cadastro inicial), o da empresa
     * configurada em NOTAPIX_CNPJ_CONSULTA (normalmente a do próprio dono do NotaPix).
     *
     * @return array{ie: ?string, ind_ie_dest: ?int, ie_mensagem: string}
     */
    public function inscricaoEstadual(string $cnpj, string $uf, ?Empresa $empresa): array
    {
        $semResposta = ['ie' => null, 'ind_ie_dest' => null, 'ie_mensagem' => 'Confira a inscrição estadual com o cliente.'];

        if (! $empresa?->temCertificadoValido()) {
            $empresa = $this->empresaDeConsulta();
        }
        if (! $empresa || strlen($uf) !== 2) {
            return $semResposta;
        }

        $chave = "cnpj.ie.v2.$uf.$cnpj";
        if (Cache::has($chave)) {
            return Cache::get($chave);
        }

        try {
            $r = CadastroSefaz::interpretar($this->fabrica->para($empresa)->consultarCadastro($uf, $cnpj));
        } catch (\Throwable $e) {
            // Falha não fica guardada: na próxima vez tenta de novo
            Log::info("Consulta cadastro SEFAZ $uf indisponível: ".$e->getMessage());

            return $semResposta;
        }

        if (! in_array($r['codigo'], [111, 112, 259], true)) {
            return $semResposta;
        }

        $resultado = (function () use ($r, $uf, $semResposta) {

            // 259: o CNPJ não é contribuinte do ICMS nessa UF
            if ($r['codigo'] === 259) {
                return ['ie' => null, 'ind_ie_dest' => Destinatario::IE_NAO_CONTRIBUINTE,
                    'ie_mensagem' => 'A SEFAZ informa que este CNPJ não tem inscrição estadual em '.$uf.'.'];
            }

            $habilitada = collect($r['contribuintes'])->first(fn ($c) => $c['situacao'] === '1' && $c['ie']);
            if ($habilitada) {
                return ['ie' => \App\Rules\InscricaoEstadual::normalizar($habilitada['ie'], $uf), 'ind_ie_dest' => Destinatario::IE_CONTRIBUINTE,
                    'ie_mensagem' => 'Inscrição estadual encontrada na SEFAZ.'];
            }

            if ($r['contribuintes']) {
                return ['ie' => null, 'ind_ie_dest' => null,
                    'ie_mensagem' => 'A inscrição estadual deste CNPJ não está ativa na SEFAZ. Confira com o cliente.'];
            }

            return $semResposta;
        })();

        Cache::put($chave, $resultado, now()->addDay());

        return $resultado;
    }

    /** Empresa cujo certificado é usado nas consultas quando o usuário ainda não tem o seu. */
    private function empresaDeConsulta(): ?Empresa
    {
        $cnpj = preg_replace('/\D/', '', (string) config('nfeexpress.cnpj_consulta'));
        if ($cnpj === '') {
            return null;
        }

        $empresa = Empresa::where('cnpj', $cnpj)->first();

        return $empresa?->temCertificadoValido() ? $empresa : null;
    }

    /** Se a SEFAZ não informou a IE, usa a do cadastro público (quando houver). */
    public static function comIePublica(array $dados): array
    {
        if (empty($dados['ie']) && ! empty($dados['ie_publica']) && ($dados['ind_ie_dest'] ?? null) !== Destinatario::IE_NAO_CONTRIBUINTE) {
            $dados['ie'] = $dados['ie_publica'];
            $dados['ind_ie_dest'] = Destinatario::IE_CONTRIBUINTE;
            $dados['ie_mensagem'] = 'Inscrição estadual encontrada no cadastro público. Confira.';
        }

        return $dados;
    }
}

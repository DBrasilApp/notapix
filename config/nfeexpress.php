<?php

return [

    // Versões vigentes dos documentos legais. Ao alterar o texto de um deles,
    // aumente a versão: o sistema registra quem aceitou qual versão.
    'termos_versao' => env('TERMOS_VERSAO', '1.0'),
    'privacidade_versao' => env('PRIVACIDADE_VERSAO', '1.0'),

    // UF atendida no lançamento
    'uf_padrao' => 'ES',

    // Programa openssl usado para converter certificados A1 em formato antigo.
    // No Linux normalmente basta "openssl". No Windows, aponte para o openssl.exe
    // (o Git para Windows traz um em C:\Program Files\Git\usr\bin\openssl.exe).
    'openssl_bin' => env('OPENSSL_BIN', 'openssl'),

    // Tabela oficial NCM (Siscomex), usada por: php artisan ncm:importar
    // Consulta de CNPJ (Receita Federal) para preencher cadastros
    // Empresa (já cadastrada, com certificado) usada para consultar a IE na SEFAZ
    // quando o usuário ainda não enviou o próprio certificado (ex.: cadastro de nova conta)
    'cnpj_consulta' => env('NOTAPIX_CNPJ_CONSULTA'),

    'cnpj_url' => env('CNPJ_URL', 'https://brasilapi.com.br/api/cnpj/v1/'),
    // Reservas, usadas quando a BrasilAPI não encontra ou está fora do ar
    'cnpj_ws_url' => env('CNPJ_WS_URL', 'https://publica.cnpj.ws/cnpj/'),
    'receitaws_url' => env('RECEITAWS_URL', 'https://receitaws.com.br/v1/cnpj/'),

    // Tabela oficial de cClassTrib (IBS/CBS) - exige certificado ICP-Brasil
    'classtrib_url' => env('CLASSTRIB_URL', 'https://cff.svrs.rs.gov.br/api/v1/consultas/classTrib'),

    'ncm_url' => env('NCM_URL', 'https://portalunico.siscomex.gov.br/classif/api/publico/nomenclatura/download/json?perfil=PUBLICO'),

    // Tamanho máximo do arquivo do certificado, em KB
    'certificado_tamanho_max_kb' => 200,

    'nfe' => [
        // Pasta de schemas (XSD) da NFePHP usada para gerar e validar o XML.
        // PL_010 = leiaute com os grupos da reforma tributária (NT 2025.002-RTC).
        'schemes' => env('NFE_SCHEMES', 'PL_010_V1.30'),

        // Responsável técnico pelo sistema (grupo infRespTec da NF-e).
        // Exigido por várias SEFAZ: preencha com os dados de quem desenvolve/mantém o NotaPix.
        'resp_tec' => [
            'cnpj' => env('NFE_RESP_TEC_CNPJ'),
            'contato' => env('NFE_RESP_TEC_CONTATO'),
            'email' => env('NFE_RESP_TEC_EMAIL'),
            'fone' => env('NFE_RESP_TEC_FONE'),
        ],

        // Texto obrigatório nas notas de empresas do Simples Nacional (LC 123/2006)
        'texto_simples' => 'DOCUMENTO EMITIDO POR ME OU EPP OPTANTE PELO SIMPLES NACIONAL. NAO GERA DIREITO A CREDITO FISCAL DE IPI.',

        // Rodapé do DANFE
        'danfe_creditos' => env('APP_NAME', 'NotaPix'),
        // Prazo para cancelar após a autorização (regra nacional: 24 horas)
        'prazo_cancelamento_horas' => (int) env('NFE_PRAZO_CANCELAMENTO_HORAS', 24),
    ],

    // NFC-e (modelo 65, cupom fiscal)
    'nfce' => [
        // Prazo para cancelar após a autorização (ES: 30 minutos)
        'prazo_cancelamento_minutos' => (int) env('NFCE_PRAZO_CANCELAMENTO_MINUTOS', 30),
        // Acima deste valor a identificação do consumidor (CPF/CNPJ) é obrigatória
        'valor_identificacao_obrigatoria' => (float) env('NFCE_VALOR_IDENTIFICACAO', 10000),
        // Por enquanto a NFC-e usa o mesmo crédito da NF-e (no futuro: crédito próprio, mais barato)
        'credito_proprio' => false,
    ],

    // NFS-e Nacional (notas de serviço)
    'nfse' => [
        // API da SEFIN Nacional (emissão). Atenção: na produção restrita o endereço tem /API; na produção, não.
        'url_producao' => env('NFSE_URL_PRODUCAO', 'https://sefin.nfse.gov.br/SefinNacional'),
        'url_homologacao' => env('NFSE_URL_HOMOLOGACAO', 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional'),
        // Leiaute da DPS: auto (pela data), nt009 ou anterior. Veja App\Services\Nfse\LeiauteDps
        'leiaute_producao' => env('NFSE_LEIAUTE_PRODUCAO', 'auto'),
        'leiaute_homologacao' => env('NFSE_LEIAUTE_HOMOLOGACAO', 'auto'),
        'virada_nt009_producao' => env('NFSE_VIRADA_NT009', '2026-12-01'),
        // Conferir o certificado do servidor do governo. Se o PHP do Windows reclamar de
        // "unable to get local issuer certificate", aponte NFSE_CA_BUNDLE para um cacert.pem.
        'verificar_ssl' => env('NFSE_CA_BUNDLE', (bool) env('NFSE_VERIFICAR_SSL', true)),
        'timeout' => (int) env('NFSE_TIMEOUT', 60),
        // tls12 (TLS 1.2 + HTTP/1.1, necessário para o servidor pedir o certificado) ou padrao
        'modo_tls' => env('NFSE_MODO_TLS', 'tls12'),
        // Por enquanto a NFS-e usa o mesmo crédito da NF-e
        'credito_proprio' => false,
    ],

];

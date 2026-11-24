<?php

namespace App\Services\Nfe;

use DOMDocument;

/** Leitura da resposta da Consulta Cadastro (CadConsultaCadastro4). */
final class CadastroSefaz
{
    public const CREDENCIAMENTO = [
        '0' => 'NÃO credenciado para NF-e',
        '1' => 'Credenciado para NF-e',
        '2' => 'Credenciado (obrigado a emitir NF-e em todas as operações)',
        '3' => 'Credenciado (obrigado a emitir NF-e em parte das operações)',
        '4' => 'A SEFAZ não informa o credenciamento',
    ];

    public const SITUACAO = [
        '0' => 'NÃO habilitado',
        '1' => 'Habilitado',
    ];

    /**
     * @return array{codigo: int, motivo: string, contribuintes: list<array<string, ?string>>}
     */
    public static function interpretar(string $xml): array
    {
        $dom = new DOMDocument;
        if (trim($xml) === '' || ! @$dom->loadXML($xml)) {
            return ['codigo' => 0, 'motivo' => 'Resposta ilegível ou vazia.', 'contribuintes' => []];
        }

        $texto = fn (\DOMElement $no, string $tag) => $no->getElementsByTagName($tag)->length
            ? trim($no->getElementsByTagName($tag)->item(0)->nodeValue)
            : null;

        $info = $dom->getElementsByTagName('infCons')->item(0) ?? $dom->documentElement;

        $contribuintes = [];
        foreach ($dom->getElementsByTagName('infCad') as $cad) {
            $contribuintes[] = [
                'ie' => $texto($cad, 'IE'),
                'cnpj' => $texto($cad, 'CNPJ') ?? $texto($cad, 'CPF'),
                'nome' => $texto($cad, 'xNome'),
                'fantasia' => $texto($cad, 'xFant'),
                'situacao' => $texto($cad, 'cSit'),
                'cred_nfe' => $texto($cad, 'indCredNFe'),
                'regime' => $texto($cad, 'xRegApur'),
                'cnae' => $texto($cad, 'CNAE'),
                'inicio' => $texto($cad, 'dIniAtiv'),
                'municipio' => $texto($cad, 'xMun'),
            ];
        }

        return [
            'codigo' => (int) $texto($info, 'cStat'),
            'motivo' => (string) $texto($info, 'xMotivo'),
            'contribuintes' => $contribuintes,
        ];
    }
}

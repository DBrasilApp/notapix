<?php

namespace Tests\Concerns;

/** Monta um JSON no formato do Siscomex, pequeno, para os testes. */
trait TabelaNcmFalsa
{
    protected function jsonNcm(): string
    {
        $itens = [
            ['Codigo' => '61', 'Descricao' => 'Vestuário e seus acessórios, de malha.', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '61.09', 'Descricao' => 'Camisetas ("T-shirts") e camisetas interiores, de malha.', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '6109.10.00', 'Descricao' => '- De algodão', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '6109.90.00', 'Descricao' => '- De outras matérias têxteis', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '09', 'Descricao' => 'Café, chá, mate e especiarias.', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '09.01', 'Descricao' => 'Café, mesmo torrado ou descafeinado.', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '0901.2', 'Descricao' => '- Café torrado:', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            ['Codigo' => '0901.21.00', 'Descricao' => '-- Não descafeinado', 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'],
            // Código que saiu de vigência
            ['Codigo' => '0901.99.99', 'Descricao' => '-- Antigo', 'Data_Inicio' => '01/01/2017', 'Data_Fim' => '31/03/2022'],
        ];

        // Enche a tabela até passar do mínimo de segurança do importador
        for ($i = 0; $i < 120; $i++) {
            $itens[] = ['Codigo' => sprintf('8471.%02d.%02d', intdiv($i, 100) + 10, $i % 100), 'Descricao' => "-- Item de teste {$i}", 'Data_Inicio' => '01/04/2022', 'Data_Fim' => '31/12/9999'];
        }

        return json_encode(['Data_Ultima_Atualizacao_NCM' => 'Vigente em 01/10/2026', 'Nomenclaturas' => $itens], JSON_UNESCAPED_UNICODE);
    }

    protected function importarNcmFalsa(): void
    {
        app(\App\Services\ImportadorNcm::class)->importar($this->jsonNcm());
    }
}

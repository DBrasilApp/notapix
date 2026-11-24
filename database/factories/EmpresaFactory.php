<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Rules\Cnpj;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'razao_social' => fake()->company().' LTDA',
            'cnpj' => Cnpj::gerar(),
            'inscricao_estadual' => (string) fake()->numerify('#########'),
            'crt' => 1,
            'municipio' => 'Vitória',
            'codigo_municipio' => '3205309',
            'uf' => 'ES',
            'email' => fake()->companyEmail(),
            'ambiente' => Empresa::AMBIENTE_HOMOLOGACAO,
        ];
    }
}

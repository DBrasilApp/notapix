<?php

namespace Database\Factories;

use App\Models\Escritorio;
use App\Rules\Cnpj;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Escritorio>
 */
class EscritorioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'razao_social' => 'Contabilidade '.fake()->lastName(),
            'cnpj' => Cnpj::gerar(),
            'crc' => 'ES-'.fake()->numerify('######'),
            'email' => fake()->companyEmail(),
            'municipio' => 'Vitória',
            'uf' => 'ES',
        ];
    }
}

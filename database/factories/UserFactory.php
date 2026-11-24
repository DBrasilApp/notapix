<?php

namespace Database\Factories;

use App\Enums\Perfil;
use App\Models\Empresa;
use App\Models\Escritorio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** Por padrão cria um usuário de Empresa, já com a empresa. */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('senha1234'),
            'remember_token' => Str::random(10),
            'perfil' => Perfil::Empresa,
            'empresa_id' => Empresa::factory(),
            'ativo' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'perfil' => Perfil::Admin,
            'empresa_id' => null,
            'escritorio_id' => null,
        ]);
    }

    public function escritorio(): static
    {
        return $this->state(fn () => [
            'perfil' => Perfil::Escritorio,
            'empresa_id' => null,
            'escritorio_id' => Escritorio::factory(),
        ]);
    }

    public function bloqueado(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}

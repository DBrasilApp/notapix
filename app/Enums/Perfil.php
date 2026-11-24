<?php

namespace App\Enums;

enum Perfil: string
{
    case Admin = 'admin';
    case Escritorio = 'escritorio';
    case Empresa = 'empresa';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Escritorio => 'Escritório Contábil',
            self::Empresa => 'Empresa',
        };
    }

    /** Nome da rota do painel inicial de cada perfil. */
    public function rotaPainel(): string
    {
        return match ($this) {
            self::Admin => 'admin.painel',
            self::Escritorio => 'escritorio.painel',
            self::Empresa => 'empresa.painel',
        };
    }
}

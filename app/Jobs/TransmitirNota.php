<?php

namespace App\Jobs;

use App\Models\Nota;
use App\Services\Nfe\EmissorNfe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envia a nota à SEFAZ fora da requisição do usuário. */
class TransmitirNota implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Não repete sozinho: um reenvio às cegas poderia duplicar. A consulta resolve as dúvidas. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Nota $nota)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->nota->id;
    }

    public function handle(EmissorNfe $emissor): void
    {
        $emissor->transmitir($this->nota->fresh());
    }
}

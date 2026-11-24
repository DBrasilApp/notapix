<?php

namespace App\Jobs;

use App\Models\Nfse;
use App\Services\Nfse\EmissorNfse;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envia uma NFS-e da emissão em lote fora da requisição do usuário. */
class TransmitirNfse implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Não repete sozinho: a consulta pela DPS resolve as dúvidas sem risco de nota em dobro. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Nfse $nfse)
    {
    }

    public function uniqueId(): string
    {
        return 'nfse-'.$this->nfse->id;
    }

    public function handle(EmissorNfse $emissor): void
    {
        $nfse = $this->nfse->fresh();
        if ($nfse && $nfse->status === Nfse::PROCESSANDO && $nfse->chave === null) {
            $emissor->transmitir($nfse);
        }
    }
}

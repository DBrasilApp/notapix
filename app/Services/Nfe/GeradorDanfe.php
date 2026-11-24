<?php

namespace App\Services\Nfe;

use NFePHP\DA\NFe\Danfce;
use NFePHP\DA\NFe\Danfe;

/** Gera o PDF do DANFE (A4, NF-e) ou do cupom (bobina, NFC-e) a partir do XML autorizado. */
class GeradorDanfe
{
    public function gerar(string $procNFe, bool $cancelada = false, bool $nfce = false, int $bobina = 80): string
    {
        $nivel = error_reporting();

        try {
            if ($nfce) {
                $danfe = new Danfce($procNFe);
                // 72 = impressora de 80 mm com área útil de 72 mm (comum nas importadas)
                $danfe->setPaperWidth(in_array($bobina, [58, 72, 80], true) ? $bobina : 80);
                if ($cancelada) {
                    $danfe->setAsCanceled();
                }
            } else {
                $danfe = new Danfe($procNFe);
                if ($cancelada) {
                    $danfe->setCancelFlag(true); // tarja "NF-e CANCELADA"
                }
            }
            $danfe->debugMode(false);
            $danfe->creditsIntegratorFooter('Emitido com '.config('nfeexpress.nfe.danfe_creditos'), false);

            return $danfe->render();
        } finally {
            error_reporting($nivel); // a biblioteca desliga os avisos do PHP
        }
    }
}

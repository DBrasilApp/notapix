<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\Nota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Junta num .zip os XMLs (e DANFEs) das notas de um mês: autorizadas, canceladas e denegadas,
 * com os eventos (cancelamento e cartas de correção). É o que o contador precisa para escriturar.
 * Organização: <CNPJ - Razão social>/<autorizadas|canceladas|denegadas>/<chave>-procNFe.xml
 */
class ArquivosDoMes
{
    /** @param Collection<int, Empresa> $empresas */
    public function gerar(Collection $empresas, CarbonImmutable $mes, bool $comDanfe = true): ?string
    {
        $inicio = $mes->startOfMonth();
        $fim = $mes->endOfMonth();

        $notas = Nota::query()
            ->whereIn('empresa_id', $empresas->pluck('id'))
            ->whereIn('status', [Nota::AUTORIZADA, Nota::CANCELADA, Nota::DENEGADA])
            ->whereNotNull('xml_path')
            ->whereBetween('autorizada_em', [$inicio, $fim])
            ->with(['eventos' => fn ($q) => $q->where('status', 'registrado')->whereNotNull('xml_path')])
            ->orderBy('numero')
            ->get();

        if ($notas->isEmpty()) {
            return null;
        }

        $pasta = storage_path('app/private/tmp');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0700, true);
        }
        $arquivo = $pasta.'/xml-'.Str::random(12).'.zip';

        $zip = new ZipArchive;
        if ($zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo .zip.');
        }

        $disco = Storage::disk('local');
        $nomes = $empresas->mapWithKeys(fn (Empresa $e) => [
            $e->id => $e->cnpj.' - '.Str::of($e->razao_social)->ascii()->replaceMatches('/[^A-Za-z0-9 ]+/', '')->limit(40, '')->trim(),
        ]);
        $pastas = [Nota::AUTORIZADA => 'autorizadas', Nota::CANCELADA => 'canceladas', Nota::DENEGADA => 'denegadas'];

        foreach ($notas as $nota) {
            $base = ($empresas->count() > 1 ? $nomes[$nota->empresa_id].'/' : '').$pastas[$nota->status].'/';

            if ($disco->exists($nota->xml_path)) {
                $zip->addFromString($base.$nota->chave.'-procNFe.xml', (string) $disco->get($nota->xml_path));
            }
            if ($comDanfe && $nota->pdf_path && $disco->exists($nota->pdf_path)) {
                $zip->addFromString($base.$nota->chave.'-danfe.pdf', (string) $disco->get($nota->pdf_path));
            }
            foreach ($nota->eventos as $evento) {
                if ($disco->exists($evento->xml_path)) {
                    $zip->addFromString($base.basename($evento->xml_path), (string) $disco->get($evento->xml_path));
                }
            }
        }

        $zip->close();

        return $arquivo;
    }

    /** Mês informado como AAAA-MM (padrão: mês passado, o que o contador costuma pedir). */
    public static function mes(?string $texto): CarbonImmutable
    {
        if ($texto && preg_match('/^(\d{4})-(\d{2})$/', $texto, $m) && checkdate((int) $m[2], 1, (int) $m[1])) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1);
        }

        return CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
    }
}

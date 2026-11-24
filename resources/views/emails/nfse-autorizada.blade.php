<!DOCTYPE html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
        <p style="margin:0 0 16px;font-size:16px;">Olá{{ $nfse->tomador_dados ? ', '.$nfse->tomadorNome() : '' }},</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
            Segue a Nota Fiscal de Serviço Eletrônica (NFS-e) nº <strong>{{ $nfse->numero_nfse }}</strong>,
            emitida por <strong>{{ $nfse->empresa->razao_social }}</strong>,
            referente a {{ $nfse->competenciaTexto() }}, no valor de <strong>{{ \App\Support\Formata::moeda($nfse->valor_servico) }}</strong>{{ (float) $nfse->valor_liquido !== (float) $nfse->valor_servico ? ' (valor líquido '.\App\Support\Formata::moeda($nfse->valor_liquido).')' : '' }}.
        </p>
        <p style="margin:0 0 16px;font-size:13px;color:#475569;">Chave de acesso:<br><span style="font-family:monospace;">{{ trim(chunk_split((string) $nfse->chave, 5, ' ')) }}</span></p>
        <p style="margin:0 0 16px;font-size:13px;color:#475569;">
            A autenticidade pode ser conferida em
            <a href="{{ \App\Services\Nfse\GeradorDanfse::URL_CONSULTA.$nfse->chave }}" style="color:#047857;">nfse.gov.br</a>.
        </p>
        @if ((int) $nfse->ambiente === 2)
            <p style="margin:0 0 16px;font-size:13px;color:#92400e;background:#fef3c7;padding:8px 12px;border-radius:8px;">
                Nota emitida em ambiente de testes (homologação), sem valor fiscal.
            </p>
        @endif
        <p style="margin:0;font-size:13px;color:#475569;">O XML e o PDF (DANFSe) estão anexados. Para responder, use o botão Responder: a mensagem vai para {{ $nfse->empresa->email ?: 'a empresa' }}.</p>
    </div>
    <p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:16px;">Enviado por {{ config('app.name') }}</p>
</body>
</html>

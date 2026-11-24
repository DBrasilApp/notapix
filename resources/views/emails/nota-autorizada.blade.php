<!DOCTYPE html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
        <p style="margin:0 0 16px;font-size:16px;">Olá,</p>
        <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
            Segue a Nota Fiscal Eletrônica nº <strong>{{ $nota->numero }}</strong>, série {{ $nota->serie }},
            emitida por <strong>{{ $nota->empresa->razao_social }}</strong>
            para <strong>{{ $nota->nomeDestinatario() }}</strong>,
            no valor de <strong>{{ \App\Support\Formata::moeda($nota->valor_total) }}</strong>.
        </p>
        <p style="margin:0 0 16px;font-size:13px;color:#475569;">Chave de acesso:<br>{{ $nota->chaveFormatada() }}</p>
        @if ($nota->ambiente === 2)
            <p style="margin:0 0 16px;font-size:13px;color:#92400e;background:#fef3c7;padding:8px 12px;border-radius:8px;">
                Nota emitida em ambiente de homologação, sem valor fiscal.
            </p>
        @endif
        <p style="margin:0;font-size:13px;color:#475569;">O XML e o DANFE (PDF) estão anexados a este e-mail.</p>
    </div>
    <p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:16px;">Enviado por {{ config('app.name') }}</p>
</body>
</html>

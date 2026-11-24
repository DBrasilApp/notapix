<?php

namespace App\Mail;

use App\Models\Nfse;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** XML e PDF (DANFSe) da NFS-e para o tomador. Sai do endereço do sistema, com o nome da empresa. */
class NfseAutorizadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Nfse $nfse)
    {
    }

    public function envelope(): Envelope
    {
        $empresa = $this->nfse->empresa;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), mb_substr($empresa->razao_social, 0, 70)),
            replyTo: filled($empresa->email) ? [new Address($empresa->email, $empresa->razao_social)] : [],
            subject: 'NFS-e nº '.$this->nfse->numero_nfse.' - '.$empresa->razao_social,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.nfse-autorizada');
    }

    public function attachments(): array
    {
        $anexos = [];
        $disco = Storage::disk('local');

        if ($this->nfse->xml_path && $disco->exists($this->nfse->xml_path)) {
            $anexos[] = Attachment::fromStorageDisk('local', $this->nfse->xml_path)
                ->as("NFSe-{$this->nfse->numero_nfse}-{$this->nfse->chave}.xml")->withMime('application/xml');
        }
        if ($this->nfse->pdf_path && $disco->exists($this->nfse->pdf_path)) {
            $anexos[] = Attachment::fromStorageDisk('local', $this->nfse->pdf_path)
                ->as("NFSe-{$this->nfse->numero_nfse}.pdf")->withMime('application/pdf');
        }

        return $anexos;
    }
}

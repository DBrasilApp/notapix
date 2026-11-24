<?php

namespace App\Mail;

use App\Models\Nota;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class NotaAutorizadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Nota $nota)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "NF-e {$this->nota->numero} - {$this->nota->empresa->razao_social}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.nota-autorizada');
    }

    public function attachments(): array
    {
        $anexos = [];

        if ($this->nota->xml_path && Storage::disk('local')->exists($this->nota->xml_path)) {
            $anexos[] = Attachment::fromStorageDisk('local', $this->nota->xml_path)
                ->as("{$this->nota->chave}.xml")->withMime('application/xml');
        }
        if ($this->nota->pdf_path && Storage::disk('local')->exists($this->nota->pdf_path)) {
            $anexos[] = Attachment::fromStorageDisk('local', $this->nota->pdf_path)
                ->as("DANFE-{$this->nota->numero}.pdf")->withMime('application/pdf');
        }

        return $anexos;
    }
}

<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\Section;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewDocumentMail extends Mailable
{
    /**
     * @param  array<int, string>  $bccAddresses  ผู้รับทั้งหมดอยู่ใน BCC (ผู้รับจะไม่เห็นอีเมลของกันและกัน)
     */
    public function __construct(public Document $document, public array $bccAddresses)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'เอกสารใหม่ที่ต้องรับทราบ: ' . $this->document->name,
            bcc: $this->bccAddresses,
        );
    }

    public function content(): Content
    {
        $sections = Section::query()
            ->whereIn('id', array_map('intval', $this->document->acknowledge_sections ?? []))
            ->orderBy('id')
            ->get()
            ->map(fn (Section $section) => $section->prefix)
            ->all();

        return new Content(
            markdown: 'mail.new-document',
            with: [
                'document' => $this->document,
                'category' => $this->document->category?->name,
                'uploader' => $this->document->creator?->getFullName(),
                'sections' => implode(', ', $sections),
                'url' => route('filament.admin.resources.document-tasks.index'),
            ],
        );
    }
}

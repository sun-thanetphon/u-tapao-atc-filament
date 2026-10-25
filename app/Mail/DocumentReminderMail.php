<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\Section;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentReminderMail extends Mailable
{
    /**
     * @param  array<int, string>  $bccAddresses  ผู้รับทั้งหมดอยู่ใน BCC (ผู้รับจะไม่เห็นอีเมลของกันและกัน)
     * @param  int|null  $testRecipientCount  ไม่เป็น null = โหมดทดสอบ (จำนวนผู้รับจริงที่ยังไม่ได้ส่ง ไม่ใช่อีเมล)
     */
    public function __construct(public Document $document, public array $bccAddresses, public ?int $testRecipientCount = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->testRecipientCount !== null ? '[ทดสอบ] ' : '') . 'เตือน: เอกสารที่ยังไม่ได้รับทราบ: ' . $this->document->name,
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
            markdown: 'mail.document-reminder',
            with: [
                'document' => $this->document,
                'category' => $this->document->category?->name,
                'sections' => implode(', ', $sections),
                'testRecipientCount' => $this->testRecipientCount,
                'url' => route('filament.admin.resources.document-tasks.index'),
            ],
        );
    }
}

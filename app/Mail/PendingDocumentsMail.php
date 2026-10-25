<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PendingDocumentsMail extends Mailable
{
    /** แสดงชื่อเอกสารในเมลไม่เกินกี่ฉบับ ที่เหลือบอกเป็นจำนวน */
    public const MAX_LISTED = 20;

    /**
     * @param  array<int, array{code: string, name: string}>  $documents  เอกสารที่กลุ่มผู้รับค้างรับทราบ
     * @param  array<int, string>  $bccAddresses  ผู้รับทั้งหมดอยู่ใน BCC (ผู้รับจะไม่เห็นอีเมลของกันและกัน)
     * @param  int|null  $testRecipientCount  ไม่เป็น null = โหมดทดสอบ (จำนวนผู้รับจริงที่ยังไม่ได้ส่ง ไม่ใช่อีเมล)
     */
    public function __construct(public array $documents, public array $bccAddresses, public ?int $testRecipientCount = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->testRecipientCount !== null ? '[ทดสอบ] ' : '') . 'เตือน: มีเอกสารที่ยังไม่ได้รับทราบ ' . count($this->documents) . ' ฉบับ',
            bcc: $this->bccAddresses,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pending-documents',
            with: [
                'listed' => array_slice($this->documents, 0, self::MAX_LISTED),
                'more' => max(0, count($this->documents) - self::MAX_LISTED),
                'total' => count($this->documents),
                'testRecipientCount' => $this->testRecipientCount,
                'url' => route('filament.admin.resources.document-tasks.index'),
            ],
        );
    }
}

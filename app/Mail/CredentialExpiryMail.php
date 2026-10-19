<?php

namespace App\Mail;

use App\Models\User;
use App\Support\CredentialSheet;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CredentialExpiryMail extends Mailable
{
    /**
     * @param  list<array{kind: string, expires_on: string, days_left: int}>  $items
     * @param  bool  $test  โหมดทดสอบ/ตัวอย่าง: ไม่แสดงชื่อผู้รับจริง
     */
    public function __construct(public User $user, public array $items, public bool $test = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->test ? '[ทดสอบ] ' : '') . 'เตือนวันหมดอายุใบอนุญาต/ใบรับรองของคุณ',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.credential-expiry',
            with: [
                'greeting' => $this->test ? 'ตัวอย่างอีเมลเตือน' : $this->user->getFullName(),
                'test' => $this->test,
                'rows' => array_map(fn (array $item) => [
                    'label' => CredentialSheet::KIND_LABELS[$item['kind']] ?? $item['kind'],
                    'date' => \Carbon\Carbon::parse($item['expires_on'])->format('d/m/Y'),
                    'status' => $item['days_left'] < 0
                        ? 'หมดอายุแล้ว'
                        : ($item['days_left'] === 0 ? 'หมดอายุวันนี้' : 'อีก ' . $item['days_left'] . ' วัน'),
                ], $this->items),
            ],
        );
    }
}

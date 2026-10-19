<?php

namespace App\Mail;

use App\Support\CredentialSheet;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * สรุปให้ผู้ดูแลทุกครั้งที่ระบบส่งเตือนวันหมดอายุ (ใครถูกเตือนรายการไหน และใครส่งไม่ได้)
 * โหมดทดสอบไม่แสดงชื่อจริง แสดงแค่จำนวน
 */
class CredentialDigestMail extends Mailable
{
    /**
     * @param  list<array{name: string, items: list<array{kind: string, expires_on: string, days_left: int}>}>  $people
     * @param  list<array{name: string, reason: string}>  $problems
     */
    public function __construct(public array $people, public array $problems, public bool $test = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->test ? '[ทดสอบ] ' : '') . 'สรุปการเตือนวันหมดอายุใบอนุญาต: ' . count($this->people) . ' คน',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.credential-digest',
            with: [
                'test' => $this->test,
                'count' => count($this->people),
                'problemCount' => count($this->problems),
                'rows' => $this->test ? [] : collect($this->people)->flatMap(fn (array $person) => array_map(fn (array $item) => [
                    'name' => $person['name'],
                    'label' => CredentialSheet::KIND_LABELS[$item['kind']] ?? $item['kind'],
                    'date' => \Carbon\Carbon::parse($item['expires_on'])->format('d/m/Y'),
                    'status' => $item['days_left'] < 0 ? 'หมดอายุแล้ว' : 'อีก ' . $item['days_left'] . ' วัน',
                ], $person['items']))->all(),
                'problems' => $this->test ? [] : $this->problems,
            ],
        );
    }
}

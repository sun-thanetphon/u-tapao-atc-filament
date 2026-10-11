<?php

namespace App\Support;

use App\Mail\NewDocumentMail;
use App\Models\Document;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ส่งอีเมลแจ้งผู้ใช้หนึ่งครั้งเมื่อเอกสารที่ต้องรับทราบถูกเผยแพร่
 *
 * ข้อจำกัดของโฮสต์: ไม่มี queue worker, กล่องส่ง 100 ฉบับ/วัน และ 100 ผู้รับ/ฉบับ
 * จึงส่งหนึ่งฉบับต่อเอกสาร (ผู้รับอยู่ใน BCC) หลังตอบกลับผู้ใช้ และเคารพโควตารายวัน
 * เพื่อไม่ให้แย่งอีเมลรีเซ็ตรหัสผ่านและอีเมลลงทะเบียน
 */
class DocumentNotifier
{
    /** จำนวนผู้รับสูงสุดต่อฉบับรวมช่อง To (โฮสต์จำกัด 100) */
    public const MAX_RECIPIENTS_PER_MESSAGE = 95;

    /**
     * ค่าช่องติ๊กส่งอีเมลจากฟอร์มของ action ที่กำลังทำงาน (ช่องนี้ไม่ถูกบันทึกลงฐานข้อมูล)
     */
    public static function wantedIn(?Form $form): bool
    {
        return (bool) ($form?->getRawState()['send_notification'] ?? false);
    }

    public function notify(Document $document, bool $wanted): void
    {
        if (! $wanted || ! $document->publish || ! $document->isNeedToAck() || $document->notified_at !== null) {
            return;
        }

        [$emails, $withoutEmail] = $this->recipients($document);

        if ($emails === []) {
            Notification::make()
                ->title('ไม่ได้ส่งอีเมล: ไม่มีผู้ใช้ที่ต้องรับทราบคนใดมีอีเมล')
                ->warning()
                ->send();

            return;
        }

        $to = (string) config('mail.from.address');
        $chunks = array_chunk($emails, self::MAX_RECIPIENTS_PER_MESSAGE - 1);

        // ตรวจโควตาก่อนจับจอง เพื่อให้เอกสารยังกดส่งซ้ำได้ภายหลัง
        if (! MailQuota::canUse(count($chunks), (int) config('mail.daily_cap_for_documents', 60))) {
            Notification::make()
                ->title('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง ลองใหม่ภายหลังจากหน้าแก้ไขเอกสาร')
                ->warning()
                ->send();

            return;
        }

        // มีคำขอเดียวเท่านั้นที่จับจองได้ (กดซ้ำ หรือผู้ดูแลสองคนพร้อมกัน)
        $claimed = Document::query()
            ->whereKey($document->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $document->setAttribute('notified_at', now())->syncOriginalAttribute('notified_at');

        Notification::make()
            ->title('จะส่งอีเมลแจ้งผู้ใช้ ' . count($emails) . " คน ({$withoutEmail} คนไม่มีอีเมล)")
            ->success()
            ->send();

        // ฮอสต์ไม่มี queue worker จึงส่งหลังตอบกลับด้วย terminating callback
        app()->terminating(fn () => $this->deliver($document, $to, $chunks, count($emails)));
    }

    /**
     * @param  array<int, array<int, string>>  $chunks
     */
    protected function deliver(Document $document, string $to, array $chunks, int $recipientCount): void
    {
        $sent = 0;

        try {
            foreach ($chunks as $bcc) {
                if (SafeMail::send($to, new NewDocumentMail($document, $bcc))) {
                    $sent++;
                }
            }
        } catch (Throwable $e) {
            // ไม่บันทึกข้อความของ exception เพราะอาจมีอีเมลผู้รับปนอยู่
            Log::error('Document notification crashed while sending', [
                'document_id' => $document->id,
                'exception' => $e::class,
            ]);
        }

        $context = [
            'document_id' => $document->id,
            'recipients' => $recipientCount,
            'chunks' => count($chunks),
            'sent_chunks' => $sent,
        ];

        Log::info('Document notification sent', $context);

        if ($sent === 0) {
            Log::error('Document notification failed: no message was delivered', $context);
        }
    }

    /**
     * ผู้ใช้ที่ยังใช้งานอยู่ในแผนกที่ต้องรับทราบและยังไม่ได้รับทราบ
     *
     * @return array{0: array<int, string>, 1: int} [อีเมลที่ไม่ซ้ำ, จำนวนผู้ใช้ที่ไม่มีอีเมล]
     */
    protected function recipients(Document $document): array
    {
        // แผนกเก็บเป็น string ใน JSON จึงแปลงเป็น int ก่อนเทียบ
        $sectionIds = array_map('intval', $document->acknowledge_sections ?? []);

        $users = User::query()
            ->active()
            ->whereIn('section_id', $sectionIds)
            ->whereNotIn('id', $document->acknowledges()->select('user_id'))
            ->get(['id', 'email']);

        $emails = [];
        $withoutEmail = 0;
        $from = strtolower((string) config('mail.from.address'));

        foreach ($users as $user) {
            $email = trim((string) $user->email);

            if ($email === '') {
                $withoutEmail++;

                continue;
            }

            $key = strtolower($email);

            // ช่อง To เป็นที่อยู่ผู้ส่งอยู่แล้ว ไม่ต้องใส่ซ้ำใน BCC
            if ($key !== $from) {
                $emails[$key] ??= $email;
            }
        }

        return [array_values($emails), $withoutEmail];
    }
}

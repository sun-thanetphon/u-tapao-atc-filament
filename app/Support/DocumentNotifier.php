<?php

namespace App\Support;

use App\Mail\DocumentReminderMail;
use App\Mail\NewDocumentMail;
use App\Models\Document;
use App\Models\User;
use Carbon\Carbon;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
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

    /** ส่งเตือนเอกสารเดิม (revision เดิม) ซ้ำได้อีกหลังผ่านกี่ชั่วโมง กันกดรัวจนผู้ใช้ถูกอีเมลท่วมและโควตารายวันหมด */
    public const REMINDER_COOLDOWN_HOURS = 24;

    /**
     * ค่าช่องติ๊กส่งอีเมลจากฟอร์มของ action ที่กำลังทำงาน (ช่องนี้ไม่ถูกบันทึกลงฐานข้อมูล)
     */
    public static function wantedIn(?Schema $form): bool
    {
        return (bool) ($form?->getRawState()['send_notification'] ?? false);
    }

    public function notify(Document $document, bool $wanted): void
    {
        if (! $wanted || ! $document->publish || ! $document->isNeedToAck() || $document->notified_at !== null) {
            return;
        }

        $testRecipient = trim((string) config('mail.doc_test_recipient'));

        // ตั้งที่อยู่ทดสอบไว้แต่ไม่ถูกต้อง: ห้ามส่งให้ใครเลย (ห้ามถอยไปส่งผู้ใช้จริง)
        if ($testRecipient !== '' && ! filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Document notification skipped: MAIL_DOC_TEST_RECIPIENT is invalid', ['document_id' => $document->id]);

            Notification::make()
                ->title('MAIL_DOC_TEST_RECIPIENT ไม่ถูกต้อง ไม่ได้ส่งอีเมล')
                ->warning()
                ->send();

            return;
        }

        [$emails, $withoutEmail, $invalid] = $this->recipients($document);

        if ($testRecipient !== '') {
            $this->notifyTestMode($document, $testRecipient, count($emails));

            return;
        }

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
        $claimedAt = now();
        $claimed = Document::query()
            ->whereKey($document->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => $claimedAt]);

        if ($claimed !== 1) {
            return;
        }

        $document->setAttribute('notified_at', $claimedAt)->syncOriginalAttribute('notified_at');

        Notification::make()
            ->title('จะส่งอีเมลแจ้งผู้ใช้ ' . count($emails) . " คน ({$withoutEmail} คนไม่มีอีเมล" . ($invalid > 0 ? ", {$invalid} คนอีเมลไม่ถูกต้อง" : '') . ')')
            ->success()
            ->send();

        // ฮอสต์ไม่มี queue worker จึงส่งหลังตอบกลับด้วย terminating callback
        app()->terminating(fn () => $this->deliver($document, $to, $chunks, count($emails), $claimedAt));
    }

    /**
     * ส่งอีเมลเตือนผู้ใช้ที่ยังไม่ได้รับทราบเอกสารฉบับปัจจุบัน (เรียกจากปุ่มของ super-admin เท่านั้น)
     * ส่งหนึ่งฉบับต่อ 94 ผู้รับ (BCC) หลังตอบกลับ และเคารพโควตารายวันกับโหมดทดสอบเหมือนการแจ้งเอกสารใหม่
     */
    public function remind(Document $document): void
    {
        if (! $document->publish || ! $document->isNeedToAck()) {
            Notification::make()->title('เอกสารนี้ไม่ต้องรับทราบ หรือยังไม่เผยแพร่ ไม่ได้ส่งอีเมล')->warning()->send();

            return;
        }

        $testRecipient = trim((string) config('mail.doc_test_recipient'));

        if ($testRecipient !== '' && ! filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Document reminder skipped: MAIL_DOC_TEST_RECIPIENT is invalid', ['document_id' => $document->id]);
            Notification::make()->title('MAIL_DOC_TEST_RECIPIENT ไม่ถูกต้อง ไม่ได้ส่งอีเมล')->warning()->send();

            return;
        }

        [$emails, $withoutEmail, $invalid] = $this->recipients($document);

        if ($emails === []) {
            Notification::make()->title('ไม่ได้ส่งอีเมล: ไม่มีผู้ค้างรับทราบที่มีอีเมลที่ใช้ได้')->warning()->send();

            return;
        }

        $cap = (int) config('mail.daily_cap_for_documents', 60);
        $chunks = array_chunk($emails, self::MAX_RECIPIENTS_PER_MESSAGE - 1);

        if ($testRecipient !== '') {
            if (! MailQuota::canUse(1, $cap)) {
                Notification::make()->title('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง')->warning()->send();

                return;
            }

            Notification::make()
                ->title('โหมดทดสอบ: ส่งอีเมลเตือนถึงที่อยู่ทดสอบ 1 ที่เท่านั้น (ผู้รับจริง ' . count($emails) . ' คน ยังไม่ได้ส่ง)')
                ->warning()
                ->send();

            $realCount = count($emails);
            app()->terminating(fn () => SafeMail::send($testRecipient, app()->make(DocumentReminderMail::class, [
                'document' => $document,
                'bccAddresses' => [],
                'testRecipientCount' => $realCount,
            ])));

            return;
        }

        $key = $this->reminderKey($document);

        if (($last = Cache::get($key)) !== null) {
            Notification::make()
                ->title('เพิ่งส่งเตือนเอกสารนี้ไปเมื่อ ' . Carbon::parse($last)->timezone(config('app.timezone'))->format('d/m/Y H:i') . ' รออย่างน้อย ' . self::REMINDER_COOLDOWN_HOURS . ' ชั่วโมงก่อนส่งอีกครั้ง')
                ->warning()
                ->send();

            return;
        }

        if (! MailQuota::canUse(count($chunks), $cap)) {
            Notification::make()->title('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง ลองใหม่ภายหลัง')->warning()->send();

            return;
        }

        // มีคำขอเดียวเท่านั้นที่จับจองได้ (กดซ้ำ หรือ super-admin สองคนพร้อมกัน)
        if (! Cache::add($key, now()->toIso8601String(), now()->addHours(self::REMINDER_COOLDOWN_HOURS))) {
            return;
        }

        Notification::make()
            ->title('จะส่งอีเมลเตือนผู้ใช้ ' . count($emails) . " คน ({$withoutEmail} คนไม่มีอีเมล" . ($invalid > 0 ? ", {$invalid} คนอีเมลไม่ถูกต้อง" : '') . ')')
            ->success()
            ->send();

        $to = (string) config('mail.from.address');

        app()->terminating(function () use ($document, $to, $chunks, $key) {
            $sent = 0;

            try {
                foreach ($chunks as $bcc) {
                    if (SafeMail::send($to, app()->make(DocumentReminderMail::class, ['document' => $document, 'bccAddresses' => $bcc]))) {
                        $sent++;
                    }
                }
            } catch (Throwable $e) {
                Log::error('Document reminder crashed while sending', ['document_id' => $document->id, 'exception' => $e::class]);
            }

            if ($sent === 0) {
                // ไม่มีฉบับไหนถึงผู้รับเลย: คืนสิทธิ์ เพื่อให้กดส่งใหม่ได้
                Cache::forget($key);
                Log::error('Document reminder failed: no message was delivered', ['document_id' => $document->id]);

                return;
            }

            Log::info('Document reminder sent', ['document_id' => $document->id, 'chunks' => count($chunks), 'sent_chunks' => $sent]);
        });
    }

    protected function reminderKey(Document $document): string
    {
        return "doc-reminder:{$document->id}:{$document->revision}";
    }

    /**
     * โหมดทดสอบ: ส่งฉบับเดียวถึงที่อยู่ทดสอบเท่านั้น ไม่จับจองเอกสาร (notified_at ยังเป็น NULL)
     * จึงส่งจริงได้ภายหลังเมื่อปิดโหมดทดสอบ ผู้รับจริงไม่ปรากฏในอีเมล บันทึก หรือที่ใดเลย มีแต่จำนวน
     */
    protected function notifyTestMode(Document $document, string $testRecipient, int $realCount): void
    {
        if (! MailQuota::canUse(1, (int) config('mail.daily_cap_for_documents', 60))) {
            Notification::make()
                ->title('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง ลองใหม่ภายหลังจากหน้าแก้ไขเอกสาร')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("โหมดทดสอบ: ส่งอีเมลถึงที่อยู่ทดสอบ 1 ที่เท่านั้น (ผู้รับจริง {$realCount} คน ยังไม่ได้ส่ง)")
            ->warning()
            ->send();

        app()->terminating(function () use ($document, $testRecipient, $realCount) {
            $sent = false;

            try {
                $sent = SafeMail::send($testRecipient, app()->make(NewDocumentMail::class, [
                    'document' => $document,
                    'bccAddresses' => [],
                    'testRecipientCount' => $realCount,
                ]));
            } catch (Throwable $e) {
                Log::error('Document test notification crashed while sending', [
                    'document_id' => $document->id,
                    'exception' => $e::class,
                ]);
            }

            Log::log($sent ? 'info' : 'error', $sent ? 'Document test notification sent' : 'Document test notification failed', [
                'document_id' => $document->id,
                'real_recipients' => $realCount,
            ]);
        });
    }

    /**
     * @param  array<int, array<int, string>>  $chunks
     */
    protected function deliver(Document $document, string $to, array $chunks, int $recipientCount, Carbon $claimedAt): void
    {
        $sent = 0;

        try {
            foreach ($chunks as $bcc) {
                if (SafeMail::send($to, app()->make(NewDocumentMail::class, ['document' => $document, 'bccAddresses' => $bcc]))) {
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

        if ($sent === 0) {
            // ไม่มีฉบับไหนถึงผู้รับเลย: คืนสิทธิ์จับจอง เพื่อให้ลองส่งใหม่จากหน้าแก้ไขได้
            try {
                Document::query()
                    ->whereKey($document->getKey())
                    ->where('notified_at', $claimedAt)
                    ->update(['notified_at' => null]);
            } catch (Throwable $e) {
                Log::error('Document notification could not release its claim', [
                    'document_id' => $document->id,
                    'exception' => $e::class,
                ]);
            }

            Log::error('Document notification failed: no message was delivered', $context + ['released' => true]);

            return;
        }

        // ส่งไปบางส่วนแล้ว ไม่คืนสิทธิ์ เพราะการลองใหม่จะส่งซ้ำให้คนที่ได้รับไปแล้ว
        $unsent = array_sum(array_map('count', array_slice($chunks, $sent)));
        Log::info('Document notification sent', $context + ['released' => false, 'unsent_recipients' => $unsent]);
    }

    /**
     * ผู้ใช้ที่ยังใช้งานอยู่ในแผนกที่ต้องรับทราบและยังไม่ได้รับทราบ
     *
     * @return array{0: array<int, string>, 1: int, 2: int} [อีเมลที่ไม่ซ้ำ, จำนวนผู้ใช้ที่ไม่มีอีเมล, จำนวนผู้ใช้ที่อีเมลไม่ถูกต้อง]
     */
    public function recipients(Document $document): array
    {
        // แผนกเก็บเป็น string ใน JSON จึงแปลงเป็น int ก่อนเทียบ
        $sectionIds = array_map('intval', $document->acknowledge_sections ?? []);

        $users = User::query()
            ->active()
            ->whereIn('section_id', $sectionIds)
            ->whereNotIn('id', $document->acknowledges()->where('revision', $document->revision)->select('user_id'))
            ->get(['id', 'email']);

        $emails = [];
        $withoutEmail = 0;
        $invalid = 0;
        $from = strtolower((string) config('mail.from.address'));

        foreach ($users as $user) {
            $email = trim((string) $user->email);

            if ($email === '') {
                $withoutEmail++;

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;

                continue;
            }

            $key = strtolower($email);

            // ช่อง To เป็นที่อยู่ผู้ส่งอยู่แล้ว ไม่ต้องใส่ซ้ำใน BCC
            if ($key !== $from) {
                $emails[$key] ??= $email;
            }
        }

        return [array_values($emails), $withoutEmail, $invalid];
    }
}

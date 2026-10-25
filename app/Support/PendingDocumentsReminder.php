<?php

namespace App\Support;

use App\Mail\PendingDocumentsMail;
use App\Models\Document;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * เมลเตือนรวมทุกเอกสารที่แต่ละคนยังค้างรับทราบ
 *
 * จัดกลุ่มผู้ใช้ตามชุดเอกสารที่ค้างเหมือนกัน แล้วส่งหนึ่งฉบับ (BCC) ต่อกลุ่ม โดยระบุชื่อเอกสารของกลุ่มนั้นในเมล
 * จึงใช้โควตากล่องส่งของโฮสต์ (100 ฉบับ/วัน) น้อยกว่าส่งรายคนมาก และคนที่ค้างหลายเอกสารได้เมลฉบับเดียว
 */
class PendingDocumentsReminder
{
    /** ผู้ใช้คนเดิมถูกเตือนซ้ำได้อีกหลังผ่านกี่ชั่วโมง */
    public const COOLDOWN_HOURS = 24;

    /**
     * จัดกลุ่มผู้ที่ค้างรับทราบ
     *
     * @return array{groups: array<string, array{documents: array<int, array{code: string, name: string}>, users: array<int, string>}>, without_email: int, invalid: int, cooling: int}
     */
    public function plan(): array
    {
        $documents = Document::query()
            ->where('publish', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'revision', 'acknowledge_sections'])
            ->filter(fn (Document $document) => filled($document->acknowledge_sections))
            ->keyBy('id');

        // เอกสารที่แต่ละคนรับทราบแล้วในรอบไฟล์ปัจจุบัน
        $done = [];
        $rows = DB::table('document_acknowledges')
            ->join('documents', function ($join) {
                $join->on('documents.id', '=', 'document_acknowledges.document_id')
                    ->on('documents.revision', '=', 'document_acknowledges.revision');
            })
            ->whereIn('document_acknowledges.document_id', $documents->keys())
            ->get(['document_acknowledges.user_id', 'document_acknowledges.document_id']);

        foreach ($rows as $row) {
            $done[$row->user_id][$row->document_id] = true;
        }

        $groups = [];
        $withoutEmail = 0;
        $invalid = 0;
        $cooling = 0;
        $from = strtolower((string) config('mail.from.address'));

        foreach (User::query()->active()->get(['id', 'email', 'section_id']) as $user) {
            $pending = $documents->filter(fn (Document $document) => in_array((int) $user->section_id, array_map('intval', $document->acknowledge_sections), true)
                && ! isset($done[$user->id][$document->id]));

            if ($pending->isEmpty()) {
                continue;
            }

            $email = trim((string) $user->email);

            if ($email === '') {
                $withoutEmail++;

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;

                continue;
            }

            // ช่อง To เป็นที่อยู่ผู้ส่งอยู่แล้ว ไม่ต้องใส่ซ้ำใน BCC
            if (strtolower($email) === $from) {
                continue;
            }

            if (Cache::has($this->cooldownKey($user->id))) {
                $cooling++;

                continue;
            }

            $key = $pending->keys()->implode(',');
            $groups[$key]['documents'] ??= $pending->map(fn (Document $document) => ['code' => $document->code, 'name' => $document->name])->values()->all();
            $groups[$key]['users'][$user->id] = $email;
        }

        return ['groups' => $groups, 'without_email' => $withoutEmail, 'invalid' => $invalid, 'cooling' => $cooling];
    }

    /** จำนวนฉบับที่ต้องส่งตามแผน (แต่ละกลุ่มแบ่งฉบับละไม่เกิน 94 คน) */
    public function messageCount(array $plan): int
    {
        return array_sum(array_map(
            fn (array $group) => (int) ceil(count($group['users']) / (DocumentNotifier::MAX_RECIPIENTS_PER_MESSAGE - 1)),
            $plan['groups'],
        ));
    }

    public function userCount(array $plan): int
    {
        return array_sum(array_map(fn (array $group) => count($group['users']), $plan['groups']));
    }

    public function send(): void
    {
        $testRecipient = trim((string) config('mail.doc_test_recipient'));

        if ($testRecipient !== '' && ! filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Pending documents reminder skipped: MAIL_DOC_TEST_RECIPIENT is invalid');
            Notification::make()->title('MAIL_DOC_TEST_RECIPIENT ไม่ถูกต้อง ไม่ได้ส่งอีเมล')->warning()->send();

            return;
        }

        $plan = $this->plan();
        $users = $this->userCount($plan);

        if ($users === 0) {
            Notification::make()->title('ไม่ได้ส่งอีเมล: ไม่มีผู้ค้างรับทราบที่มีอีเมลที่ใช้ได้ (หรือเพิ่งถูกเตือนไปแล้ว)')->warning()->send();

            return;
        }

        $cap = (int) config('mail.daily_cap_for_documents', 60);

        if ($testRecipient !== '') {
            $this->sendTestSample($plan, $testRecipient, $users, $cap);

            return;
        }

        $messages = $this->messageCount($plan);

        // ไม่ส่งครึ่งๆ กลางๆ: ถ้าโควตาวันนี้ไม่พอสำหรับทุกกลุ่ม ไม่ส่งเลย
        if (! MailQuota::canUse($messages, $cap)) {
            Notification::make()
                ->title("โควตาอีเมลวันนี้เหลือไม่พอ ต้องใช้ {$messages} ฉบับ ยังไม่ได้ส่ง ลองใหม่ภายหลัง")
                ->warning()
                ->send();

            return;
        }

        // จองสิทธิ์รายคน กันกดซ้ำ/ผู้ดูแลสองคนพร้อมกัน คนที่จองไม่ได้ถูกตัดออก
        $ttl = now()->addHours(self::COOLDOWN_HOURS);
        $batches = [];

        foreach ($plan['groups'] as $group) {
            $claimed = [];

            foreach ($group['users'] as $id => $email) {
                if (Cache::add($this->cooldownKey($id), now()->toIso8601String(), $ttl)) {
                    $claimed[$id] = $email;
                }
            }

            foreach (array_chunk($claimed, DocumentNotifier::MAX_RECIPIENTS_PER_MESSAGE - 1, true) as $chunk) {
                $batches[] = ['documents' => $group['documents'], 'users' => $chunk];
            }
        }

        if ($batches === []) {
            return;
        }

        Notification::make()
            ->title("จะส่งอีเมลเตือนผู้ใช้ {$users} คน รวม " . count($batches) . " ฉบับ ({$plan['without_email']} คนไม่มีอีเมล, {$plan['cooling']} คนเพิ่งถูกเตือนใน " . self::COOLDOWN_HOURS . ' ชม.)')
            ->success()
            ->send();

        $to = (string) config('mail.from.address');

        app()->terminating(function () use ($batches, $to) {
            $sent = 0;

            try {
                foreach ($batches as $batch) {
                    $delivered = SafeMail::send($to, app()->make(PendingDocumentsMail::class, [
                        'documents' => $batch['documents'],
                        'bccAddresses' => array_values($batch['users']),
                    ]));

                    if ($delivered) {
                        $sent++;
                    } else {
                        // ฉบับนี้ไม่ถึงใครเลย: คืนสิทธิ์ให้คนในฉบับนี้ เพื่อให้กดส่งใหม่ได้
                        foreach (array_keys($batch['users']) as $id) {
                            Cache::forget($this->cooldownKey($id));
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::error('Pending documents reminder crashed while sending', ['exception' => $e::class]);
            }

            Log::log($sent === count($batches) ? 'info' : 'error', 'Pending documents reminder finished', ['batches' => count($batches), 'sent' => $sent]);
        });
    }

    /**
     * โหมดทดสอบ: ส่งฉบับเดียวถึงที่อยู่ทดสอบ เป็นตัวอย่างของกลุ่มใหญ่สุด ไม่จองสิทธิ์รายคน
     */
    protected function sendTestSample(array $plan, string $testRecipient, int $users, int $cap): void
    {
        if (! MailQuota::canUse(1, $cap)) {
            Notification::make()->title('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง')->warning()->send();

            return;
        }

        $largest = collect($plan['groups'])->sortByDesc(fn (array $group) => count($group['users']))->first();

        Notification::make()
            ->title("โหมดทดสอบ: ส่งตัวอย่าง 1 ฉบับถึงที่อยู่ทดสอบเท่านั้น (ถ้าส่งจริงจะถึงผู้ใช้ {$users} คน รวม " . $this->messageCount($plan) . ' ฉบับ)')
            ->warning()
            ->send();

        app()->terminating(fn () => SafeMail::send($testRecipient, app()->make(PendingDocumentsMail::class, [
            'documents' => $largest['documents'],
            'bccAddresses' => [],
            'testRecipientCount' => $users,
        ])));
    }

    protected function cooldownKey(int $userId): string
    {
        return "pending-reminder:{$userId}";
    }
}

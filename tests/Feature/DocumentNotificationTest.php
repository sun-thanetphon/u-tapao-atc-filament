<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Mail\NewDocumentMail;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use App\Support\DocumentNotifier;
use App\Support\MailQuota;
use App\Support\PasswordResetLink;
use App\Support\SafeMail;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use Filament\Actions\Testing\TestAction;

class DocumentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const SENT_TITLE = "จะส่งอีเมลแจ้งผู้ใช้ 1 คน (1 คนไม่มีอีเมล)";

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        config(['mail.from.address' => 'noreply@example.test', 'mail.daily_cap_for_documents' => 60]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function recipient(string $section, ?string $email = null, string $status = UserStatus::ACTIVE): User
    {
        $this->counter++;
        $user = $this->makeUser($this->section($section));
        $user->forceFill(['email' => $email ?? "person{$this->counter}@example.test", 'status' => $status])->save();

        return $user;
    }

    protected function notifier(): DocumentNotifier
    {
        return app(DocumentNotifier::class);
    }

    protected function titles(): array
    {
        return $this->sentNotifications()->pluck('title')->all();
    }

    protected function deliverDeferred(): void
    {
        app()->terminate();
    }

    /** @return array<int, NewDocumentMail> */
    protected function sentMails(): array
    {
        return Mail::sent(NewDocumentMail::class)->all();
    }

    protected function bccOf(NewDocumentMail $mail): array
    {
        return collect($mail->envelope()->bcc)->map(fn ($a) => is_string($a) ? $a : $a->address)->sort()->values()->all();
    }

    protected function publishedDoc(array $attributes = []): Document
    {
        return $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('ADC')], $attributes);
    }

    public function test_sends_exactly_one_message_with_all_recipients_in_bcc(): void
    {
        Mail::fake();
        $users = collect(range(1, 10))->map(fn () => $this->recipient('ADC'));
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $mails = $this->sentMails();
        $this->assertCount(1, $mails);
        $this->assertTrue($mails[0]->hasTo('noreply@example.test'));
        $this->assertSame($users->pluck('email')->sort()->values()->all(), $this->bccOf($mails[0]));
        $this->assertNotNull($document->fresh()->notified_at);
        $this->assertSame(1, MailQuota::used());
        $this->assertContains('จะส่งอีเมลแจ้งผู้ใช้ 10 คน (0 คนไม่มีอีเมล)', $this->titles());
    }

    public function test_only_active_users_in_acknowledge_sections_with_email_are_recipients(): void
    {
        Mail::fake();
        $this->recipient('ADC', 'Wanted@Example.test');
        $this->recipient('ADC', status: UserStatus::PENDING);
        $this->recipient('ADC', status: UserStatus::REJECTED);
        $this->recipient('ADC')->delete();
        $this->recipient('APP'); // อ่านได้แต่ไม่ต้องรับทราบ
        $this->recipient('FA');
        $this->makeUser($this->section('ADC')); // ไม่มีอีเมล
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertCount(1, $this->sentMails());
        $this->assertSame(['Wanted@Example.test'], $this->bccOf($this->sentMails()[0]));
        $this->assertContains('จะส่งอีเมลแจ้งผู้ใช้ 1 คน (1 คนไม่มีอีเมล)', $this->titles());
    }

    public function test_duplicate_emails_are_removed_case_insensitively(): void
    {
        Mail::fake();
        $this->recipient('ADC', 'same@example.test');
        $this->recipient('ADC', 'SAME@Example.test');
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertCount(1, $this->bccOf($this->sentMails()[0]));
    }

    public function test_users_who_already_acknowledged_are_excluded(): void
    {
        Mail::fake();
        $done = $this->recipient('ADC');
        $todo = $this->recipient('ADC');
        $document = $this->publishedDoc();
        $document->acknowledges()->create(['user_id' => $done->id, 'acknowledge_date' => now()]);

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertSame([$todo->email], $this->bccOf($this->sentMails()[0]));
    }

    public function test_sections_stored_as_ints_also_match(): void
    {
        Mail::fake();
        $user = $this->recipient('ADC');
        $document = $this->publishedDoc();
        $document->forceFill(['acknowledge_sections' => [$this->section('ADC')]])->save();

        $this->notifier()->notify($document->fresh(), true);
        $this->deliverDeferred();

        $this->assertSame([$user->email], $this->bccOf($this->sentMails()[0]));
    }

    public function test_nothing_is_sent_when_not_applicable(): void
    {
        Mail::fake();
        $this->recipient('ADC');

        $unpublished = $this->publishedDoc(['publish' => false]);
        $noAck = $this->makeDocument([$this->section('ADC')], []);
        $unticked = $this->publishedDoc();
        $already = $this->publishedDoc();
        Document::whereKey($already->id)->update(['notified_at' => now()->subDay()]);

        $this->notifier()->notify($unpublished, true);
        $this->notifier()->notify($noAck, true);
        $this->notifier()->notify($unticked, false);
        $this->notifier()->notify($already->fresh(), true);
        $this->deliverDeferred();

        Mail::assertNothingSent();
        $this->assertNull($unpublished->fresh()->notified_at);
        $this->assertNull($noAck->fresh()->notified_at);
        $this->assertNull($unticked->fresh()->notified_at);
        $this->assertSame([], $this->titles());
    }

    public function test_double_submit_sends_once(): void
    {
        Mail::fake();
        $this->recipient('ADC');
        $document = $this->publishedDoc();

        $this->notifier()->notify(Document::find($document->id), true);
        $this->notifier()->notify(Document::find($document->id), true);
        $this->deliverDeferred();

        $this->assertCount(1, $this->sentMails());
    }

    public function test_claim_is_atomic_across_stale_copies(): void
    {
        Mail::fake();
        $this->recipient('ADC');
        $document = $this->publishedDoc();
        $stale = Document::find($document->id);
        $fresh = Document::find($document->id);

        $this->notifier()->notify($fresh, true);
        $this->notifier()->notify($stale, true); // สำเนาเก่ายังเห็น notified_at เป็น NULL
        $this->deliverDeferred();

        $this->assertCount(1, $this->sentMails());
    }

    public function test_more_than_95_recipients_are_split_into_chunks_of_at_most_100_including_to(): void
    {
        Mail::fake();
        collect(range(1, 100))->each(fn () => $this->recipient('ADC'));
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $mails = $this->sentMails();
        $this->assertCount(2, $mails);
        $all = [];
        foreach ($mails as $mail) {
            $bcc = $this->bccOf($mail);
            $this->assertLessThanOrEqual(95, count($bcc) + 1);
            $all = array_merge($all, $bcc);
        }
        $this->assertCount(100, array_unique($all));
        $this->assertSame(2, MailQuota::used());
    }

    public function test_nobody_with_email_shows_warning_and_does_not_claim(): void
    {
        Mail::fake();
        $this->makeUser($this->section('ADC'));
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        Mail::assertNothingSent();
        $this->assertNull($document->fresh()->notified_at);
        $this->assertCount(1, $this->titles());
        $this->assertStringContainsString('ไม่มี', $this->titles()[0]);
    }

    public function test_daily_cap_blocks_sending_and_leaves_document_retryable(): void
    {
        Mail::fake();
        $this->recipient('ADC');
        MailQuota::record(60);
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        Mail::assertNothingSent();
        $this->assertNull($document->fresh()->notified_at);
        $this->assertContains('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง ลองใหม่ภายหลังจากหน้าแก้ไขเอกสาร', $this->titles());
    }

    public function test_cap_allows_the_last_message_of_the_daily_budget(): void
    {
        Mail::fake();
        $this->recipient('ADC');
        MailQuota::record(59);
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertCount(1, $this->sentMails());
    }

    public function test_cap_is_read_from_config(): void
    {
        Mail::fake();
        $this->recipient('ADC');
        config(['mail.daily_cap_for_documents' => 5]);
        MailQuota::record(5);
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        Mail::assertNothingSent();
    }

    /** @return array<int, array{0: string, 1: array}> */
    protected function captureLogs(): \ArrayObject
    {
        $logs = new \ArrayObject();
        Log::listen(fn ($event) => $logs->append([$event->level, $event->message, $event->context]));

        return $logs;
    }

    protected function assertNoAddressInLogs(\ArrayObject $logs): void
    {
        $this->assertNotEmpty($logs->getArrayCopy());
        foreach ($logs as [$level, $message, $context]) {
            $this->assertStringNotContainsString('@', $message . json_encode($context, JSON_UNESCAPED_UNICODE), "log [$level] $message leaks an address");
        }
    }

    public function test_total_send_failure_releases_the_claim_so_it_can_be_retried(): void
    {
        $this->recipient('ADC', 'secret.person@example.test');
        $document = $this->publishedDoc();
        $logs = $this->captureLogs();
        $listener = Event::listen(MessageSending::class, fn () => throw new \RuntimeException('smtp down secret.person@example.test'));

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertSame(0, MailQuota::used());
        $this->assertNull($document->fresh()->notified_at);
        $errors = collect($logs)->where(0, 'error');
        $this->assertCount(1, $errors);
        $context = $errors->first()[2];
        $this->assertSame(['document_id' => $document->id, 'recipients' => 1, 'chunks' => 1, 'sent_chunks' => 0, 'released' => true], $context);
        $this->assertEmpty(collect($logs)->where(1, 'Document notification sent'));
        $this->assertNoAddressInLogs($logs);

        // ส่งใหม่ได้เมื่อ SMTP กลับมาใช้ได้
        Event::forget(MessageSending::class);
        Mail::fake();
        $this->notifier()->notify(Document::find($document->id), true);
        app()->terminate();
        $this->assertNotNull($document->fresh()->notified_at);
        Mail::assertSent(NewDocumentMail::class);
    }

    public function test_partial_failure_keeps_the_claim_and_logs_unsent_count(): void
    {
        collect(range(1, 100))->each(fn () => $this->recipient('ADC'));
        $document = $this->publishedDoc();
        $logs = $this->captureLogs();
        $calls = 0;
        Event::listen(MessageSending::class, function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertNotNull($document->fresh()->notified_at); // ปล่อยแล้วส่งซ้ำจะส่งซ้ำให้คนที่ได้ไปแล้ว
        $this->assertSame(1, MailQuota::used());
        $info = collect($logs)->where(1, 'Document notification sent')->first();
        $this->assertSame(
            ['document_id' => $document->id, 'recipients' => 100, 'chunks' => 2, 'sent_chunks' => 1, 'released' => false, 'unsent_recipients' => 6],
            $info[2],
        );
        $this->assertNoAddressInLogs($logs);
    }

    public function test_exception_building_the_message_is_swallowed_releases_claim_and_logs_no_address(): void
    {
        $this->recipient('ADC');
        $document = $this->publishedDoc();
        $logs = $this->captureLogs();
        app()->bind(NewDocumentMail::class, fn () => throw new \LogicException('boom secret.person@example.test'));

        $this->notifier()->notify($document, true);
        $this->deliverDeferred(); // ต้องไม่โยนออกมา

        $this->assertNull($document->fresh()->notified_at);
        $this->assertNotEmpty(collect($logs)->where(0, 'error')->all());
        $this->assertNoAddressInLogs($logs);
    }

    public function test_transport_exception_message_with_an_address_is_never_logged(): void
    {
        $this->recipient('ADC', 'secret.person@example.test');
        $document = $this->publishedDoc();
        $logs = $this->captureLogs();
        Event::listen(MessageSending::class, fn () => throw new \RuntimeException('boom secret.person@example.test'));

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $warning = collect($logs)->where(1, 'Failed to send mail')->first();
        $this->assertNotNull($warning);
        $this->assertSame(\RuntimeException::class, $warning[2]['exception']);
        $this->assertArrayNotHasKey('error', $warning[2]);
        $this->assertArrayNotHasKey('to', $warning[2]);
        $this->assertNoAddressInLogs($logs);
    }

    public function test_invalid_addresses_are_skipped_and_counted(): void
    {
        Mail::fake();
        $good = $this->recipient('ADC');
        $this->recipient('ADC', 'not an address');
        $this->recipient('ADC', 'missing-at.example.test');
        $document = $this->publishedDoc();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        $this->assertSame([$good->email], $this->bccOf($this->sentMails()[0]));
        $this->assertContains('จะส่งอีเมลแจ้งผู้ใช้ 1 คน (0 คนไม่มีอีเมล, 2 คนอีเมลไม่ถูกต้อง)', $this->titles());
    }

    public function test_quota_check_fails_closed_when_cache_cannot_be_read(): void
    {
        \Illuminate\Support\Facades\Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));

        $this->assertFalse(MailQuota::canUse(1, 60));
        $this->assertSame(0, MailQuota::used());
    }

    public function test_info_log_has_counts_only(): void
    {
        Mail::fake();
        $this->recipient('ADC', 'secret.person@example.test');
        $document = $this->publishedDoc();
        Log::spy();

        $this->notifier()->notify($document, true);
        $this->deliverDeferred();

        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context = []) => $context === [
            'document_id' => $document->id,
            'recipients' => 1,
            'chunks' => 1,
            'sent_chunks' => 1,
            'released' => false,
            'unsent_recipients' => 0,
        ]);
    }

    public function test_quota_counts_safe_mail_and_reset_link_mail(): void
    {
        Mail::fake();
        $user = $this->recipient('ADC');

        $this->assertSame(0, MailQuota::used());
        SafeMail::send('a@example.test', new \App\Mail\NewRegistrationMail($user));
        $this->assertSame(1, MailQuota::used());

        NotificationFacade::fake();
        PasswordResetLink::send($user->email);
        $this->assertSame(2, MailQuota::used());
    }

    public function test_quota_does_not_count_failed_safe_mail(): void
    {
        $user = $this->recipient('ADC');
        Log::spy();
        Event::listen(MessageSending::class, fn () => throw new \RuntimeException('smtp down'));

        $this->assertFalse(SafeMail::send('a@example.test', new \App\Mail\NewRegistrationMail($user)));
        $this->assertSame(0, MailQuota::used());
    }

    public function test_quota_can_use_logic(): void
    {
        $this->assertTrue(MailQuota::canUse(60, 60));
        $this->assertFalse(MailQuota::canUse(61, 60));
        MailQuota::record(10);
        $this->assertTrue(MailQuota::canUse(50, 60));
        $this->assertFalse(MailQuota::canUse(51, 60));
    }

    public function test_email_content(): void
    {
        $creator = $this->recipient('ADC');
        $document = $this->publishedDoc(['name' => 'ระเบียบ <b>ใหม่</b>', 'creator_id' => $creator->id]);

        $mail = new NewDocumentMail($document, ['person1@example.test']);
        $html = (string) app(\Illuminate\Mail\Markdown::class)->render('mail.new-document', $mail->content()->with);

        $this->assertSame('เอกสารใหม่ที่ต้องรับทราบ: ระเบียบ <b>ใหม่</b>', $mail->envelope()->subject);
        $this->assertStringContainsString($document->code, $html);
        $this->assertStringContainsString($creator->getFullName(), $html);
        $this->assertStringContainsString('ADC', $html);
        $this->assertStringContainsString(route('filament.admin.resources.document-tasks.index'), $html);
        $this->assertStringContainsString('ไม่ต้องตอบกลับ', $html);
        $this->assertStringNotContainsString('person1@example.test', $html);
        $this->assertStringNotContainsString('<b>ใหม่</b>', $html);
    }

    public function test_migration_is_additive_and_existing_documents_stay_unnotified(): void
    {
        $this->assertTrue(Schema::hasColumn('documents', 'notified_at'));
        $document = $this->publishedDoc();
        $this->assertNull($document->fresh()->notified_at);
        $this->assertNotContains('notified_at', (new Document())->getFillable());
    }

    public function test_migration_backfills_published_documents_only_and_is_idempotent(): void
    {
        $published = $this->publishedDoc();
        $draft = $this->publishedDoc(['publish' => false]);
        $createdAt = '2026-01-05 08:00:00';
        \Illuminate\Support\Facades\DB::table('documents')->whereIn('id', [$published->id, $draft->id])->update(['created_at' => $createdAt]);

        $migration = require database_path('migrations/2026_10_11_000001_add_notified_at_to_documents_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame($createdAt, $published->fresh()->notified_at->format('Y-m-d H:i:s'));
        $this->assertNull($draft->fresh()->notified_at);
    }

    public function test_send_checkbox_defaults_off_on_edit_and_on_for_create(): void
    {
        $this->admin();
        $draft = $this->publishedDoc(['publish' => false]);

        Livewire::test(ListDocuments::class)
            ->mountAction(TestAction::make('edit')->table($draft))
            ->assertSchemaStateSet(['send_notification' => false]);

        Livewire::test(ListDocuments::class)
            ->mountAction(CreateAction::class)
            ->assertSchemaStateSet(['send_notification' => true]);
    }

    // ---- ผ่านฟอร์มจริง (modal บนหน้ารายการเอกสาร) ----

    protected function admin(): User
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $this->actingAs($admin);

        return $admin;
    }

    protected function createData(array $overrides = []): array
    {
        return [
            'view_sections' => [(string) $this->section('ADC')],
            'acknowledge_sections' => [(string) $this->section('ADC')],
            'category_id' => DocumentCategory::query()->value('id'),
            'name' => 'เอกสารฟอร์ม',
            'file_path' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'publish' => true,
            ...$overrides,
        ];
    }

    // หมายเหตุ: Livewire::test() รัน terminate() ของแอปทุกคำขอ และ terminating callback ไม่ถูกล้าง
    // จึงถูกรันซ้ำในคำขอถัดไปของเทสต์ ตรงนี้จึงยืนยันด้วย notified_at และการแจ้งเตือนของ Filament แทนการนับฉบับ

    public function test_create_form_publishes_and_notifies_by_default(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->admin();
        $user = $this->recipient('ADC');

        Livewire::test(ListDocuments::class)
            ->callAction(CreateAction::class, $this->createData())
            ->assertHasNoFormErrors();
        $this->assertContains(self::SENT_TITLE, $this->titles());

        $document = Document::query()->where('name', 'เอกสารฟอร์ม')->firstOrFail();
        $this->assertNotNull($document->notified_at);
        Mail::assertSent(NewDocumentMail::class, fn (NewDocumentMail $mail) => $this->bccOf($mail) === [$user->email]);
    }

    public function test_create_form_unticked_does_not_notify(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->admin();
        $this->recipient('ADC');

        Livewire::test(ListDocuments::class)
            ->callAction(CreateAction::class, $this->createData(['send_notification' => 0]))
            ->assertHasNoFormErrors();
        $this->assertNotContains(self::SENT_TITLE, $this->titles());

        Mail::assertNothingSent();
        $this->assertNull(Document::query()->where('name', 'เอกสารฟอร์ม')->firstOrFail()->notified_at);
    }

    public function test_create_unpublished_then_publish_through_edit_form_sends_once(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->admin();
        $this->recipient('ADC');

        Livewire::test(ListDocuments::class)
            ->callAction(CreateAction::class, $this->createData(['publish' => 0]));
        $this->assertNotContains(self::SENT_TITLE, $this->titles());
        Mail::assertNothingSent();

        $document = Document::query()->where('name', 'เอกสารฟอร์ม')->firstOrFail();
        $this->assertNull($document->notified_at);

        Livewire::test(ListDocuments::class)
            ->callAction(TestAction::make('edit')->table($document), ['publish' => true, 'send_notification' => true])
            ->assertHasNoFormErrors();
        $this->assertContains(self::SENT_TITLE, $this->titles());

        $notifiedAt = $document->fresh()->notified_at;
        $this->assertNotNull($notifiedAt);
        Mail::assertSent(NewDocumentMail::class);

        // แก้ไขอีกครั้ง ไม่ส่งซ้ำ และไม่แตะ notified_at
        $this->travel(5)->minutes();
        $this->forgetNotifications();
        Livewire::test(ListDocuments::class)
            ->callAction(TestAction::make('edit')->table($document->fresh()), ['name' => 'ชื่อใหม่', 'send_notification' => true]);
        $this->assertNotContains(self::SENT_TITLE, $this->titles());
        $this->assertTrue($notifiedAt->equalTo($document->fresh()->notified_at));
    }

    public function test_checkbox_is_hidden_on_edit_when_already_notified(): void
    {
        $this->admin();
        $fresh = $this->publishedDoc(['publish' => false]);
        $notified = $this->publishedDoc();
        Document::whereKey($notified->id)->update(['notified_at' => now()]);

        Livewire::test(ListDocuments::class)
            ->mountAction(TestAction::make('edit')->table($fresh))
            ->assertFormFieldVisible('send_notification');

        Livewire::test(ListDocuments::class)
            ->mountAction(TestAction::make('edit')->table($notified->fresh()))
            ->assertFormFieldHidden('send_notification');
    }

    public function test_table_publish_toggle_does_not_send(): void
    {
        Mail::fake();
        $this->admin();
        $this->recipient('ADC');
        $document = $this->publishedDoc(['publish' => false]);

        Livewire::test(ListDocuments::class)->call("updateTableColumnState", "publish", (string) $document->id, true);
        $this->deliverDeferred();

        Mail::assertNothingSent();
        $this->assertTrue($document->fresh()->publish);
        $this->assertNull($document->fresh()->notified_at);
    }
}

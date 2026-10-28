<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Mail\PendingDocumentsMail;
use App\Models\Document;
use App\Models\User;
use App\Support\MailQuota;
use App\Support\PendingDocumentsReminder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ปุ่มเมลเตือนรวม: จัดกลุ่มคนตามชุดเอกสารที่ค้างเหมือนกัน หนึ่งฉบับต่อกลุ่ม
 */
class PendingDocumentsReminderTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private Document $docAdcOnly;

    private Document $docAdcApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Cache::flush();
        config(['mail.from.address' => 'noreply@example.test', 'mail.daily_cap_for_documents' => 60, 'mail.doc_test_recipient' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->docAdcOnly = $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('ADC')]);
        $this->docAdcApp = $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('ADC'), $this->section('APP')]);
    }

    private function member(string $section, ?string $email = 'auto'): User
    {
        $this->counter++;
        $user = $this->makeUser($this->section($section));
        $user->forceFill(['email' => $email === 'auto' ? "member{$this->counter}@example.test" : $email])->save();

        return $user;
    }

    private function acknowledge(User $user, Document $document): void
    {
        $document->acknowledges()->create(['user_id' => $user->id, 'revision' => $document->revision, 'acknowledge_date' => now()]);
    }

    private function sent(): array
    {
        return Mail::sent(PendingDocumentsMail::class)->all();
    }

    private function bcc(PendingDocumentsMail $mail): array
    {
        return collect($mail->envelope()->bcc)->map(fn ($a) => is_string($a) ? $a : $a->address)->sort()->values()->all();
    }

    public function test_users_with_the_same_pending_set_share_one_message_listing_their_documents(): void
    {
        Mail::fake();
        $a = $this->member('ADC');
        $b = $this->member('ADC');
        $c = $this->member('APP');
        $d = $this->member('ADC');
        $this->acknowledge($d, $this->docAdcOnly); // d ค้างแค่ docAdcApp เหมือน c

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));
        Livewire::test(ListDocuments::class)->callAction('remindAllPending');

        $mails = collect($this->sent())->keyBy(fn ($m) => count($m->documents));
        $this->assertCount(2, $mails);

        $both = $mails[2];
        $this->assertEqualsCanonicalizing([$this->docAdcOnly->code, $this->docAdcApp->code], array_column($both->documents, 'code'));
        $this->assertSame(collect([$a->email, $b->email])->sort()->values()->all(), $this->bcc($both));

        $one = $mails[1];
        $this->assertSame($this->docAdcApp->code, $one->documents[0]['code']);
        $this->assertSame(collect([$c->email, $d->email])->sort()->values()->all(), $this->bcc($one));
        $this->assertSame(2, MailQuota::used());
    }

    public function test_acknowledged_users_and_users_without_email_get_nothing(): void
    {
        $done = $this->member('ADC');
        $this->acknowledge($done, $this->docAdcOnly);
        $this->acknowledge($done, $this->docAdcApp);
        $this->member('ADC', null);
        $this->member('FA'); // แผนกที่ไม่ต้องรับทราบ

        $plan = app(PendingDocumentsReminder::class)->plan();

        $this->assertSame([], $plan['groups']);
        $this->assertSame(1, $plan['without_email']);
    }

    public function test_user_is_not_reminded_again_within_cooldown(): void
    {
        Mail::fake();
        $this->member('ADC');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        Livewire::test(ListDocuments::class)->callAction('remindAllPending');

        $plan = app(PendingDocumentsReminder::class)->plan();
        $this->assertSame([], $plan['groups']);
        $this->assertSame(1, $plan['cooling']);
    }

    public function test_nothing_is_sent_when_quota_cannot_cover_every_group(): void
    {
        Mail::fake();
        $this->member('ADC');
        $this->member('APP');
        config(['mail.daily_cap_for_documents' => 1]); // ต้องใช้ 2 ฉบับ

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));
        Livewire::test(ListDocuments::class)->callAction('remindAllPending');

        Mail::assertNothingSent();
        // ไม่ได้จองสิทธิ์ใคร ผู้ใช้ยังถูกเตือนได้เมื่อโควตาพอ
        $this->assertSame(2, collect(app(PendingDocumentsReminder::class)->plan()['groups'])->sum(fn ($g) => count($g['users'])));
    }

    public function test_test_mode_sends_one_sample_to_the_test_address_only(): void
    {
        Mail::fake();
        config(['mail.doc_test_recipient' => 'tester@example.test']);
        $this->member('ADC');
        $this->member('APP');

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));
        Livewire::test(ListDocuments::class)->callAction('remindAllPending');

        Mail::assertSent(PendingDocumentsMail::class, 1);
        Mail::assertSent(PendingDocumentsMail::class, fn ($m) => $m->hasTo('tester@example.test') && $m->testRecipientCount === 2 && $m->bccAddresses === []);
    }

    public function test_only_super_admin_sees_the_button(): void
    {
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));

        Livewire::test(ListDocuments::class)->assertActionHidden('remindAllPending');
    }

    public function test_recipient_list_names_each_person_without_emails(): void
    {
        $a = $this->member('ADC');
        $this->member('APP');

        $reminder = app(PendingDocumentsReminder::class);
        $list = $reminder->recipientList($reminder->plan());

        $this->assertCount(2, $list);
        $this->assertContains($a->getFullName(), array_column($list, 'name'));
        $this->assertStringNotContainsString('@', json_encode($list));
    }

    public function test_confirmation_modal_lists_recipients(): void
    {
        $a = $this->member('ADC');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        Livewire::test(ListDocuments::class)
            ->mountAction('remindAllPending')
            ->assertMountedActionModalSee($a->getFullName());
    }
}

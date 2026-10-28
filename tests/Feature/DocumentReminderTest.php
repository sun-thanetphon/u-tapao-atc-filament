<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentResource\Pages\FollowDocument;
use App\Mail\DocumentReminderMail;
use App\Models\User;
use App\Support\MailQuota;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;
use Filament\Actions\Testing\TestAction;

/**
 * super-admin กดปุ่มส่งอีเมลเตือนผู้ที่ยังไม่รับทราบจากหน้าติดตามเอกสาร
 */
class DocumentReminderTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Cache::flush();
        config(['mail.from.address' => 'noreply@example.test', 'mail.daily_cap_for_documents' => 60, 'mail.doc_test_recipient' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function member(string $section = 'ADC'): User
    {
        $this->counter++;
        $user = $this->makeUser($this->section($section));
        $user->forceFill(['email' => "member{$this->counter}@example.test"])->save();

        return $user;
    }

    private function bccs(): array
    {
        return collect(Mail::sent(DocumentReminderMail::class)->all())
            ->flatMap(fn ($m) => collect($m->envelope()->bcc)->map(fn ($a) => is_string($a) ? $a : $a->address))
            ->sort()->values()->all();
    }

    public function test_super_admin_reminds_only_users_who_have_not_acknowledged(): void
    {
        Mail::fake();
        $pending = $this->member();
        $done = $this->member();
        $other = $this->member('APP'); // ไม่ต้องรับทราบ
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $document->acknowledges()->create(['user_id' => $done->id, 'revision' => $document->revision, 'acknowledge_date' => now()]);

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->callAction(TestAction::make('remind')->table())
            ->assertNotified();

        $this->assertSame([$pending->email], $this->bccs());
        $this->assertSame(1, MailQuota::used());
    }

    public function test_second_press_within_cooldown_is_refused(): void
    {
        Mail::fake();
        $this->member();
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        Livewire::test(FollowDocument::class, ['record' => $document->id])->callAction(TestAction::make('remind')->table());
        $this->assertTrue(Cache::has("doc-reminder:{$document->id}:{$document->revision}"));

        // ฟังก์ชันส่งหลังตอบกลับสะสมในแอปเดียวกันตอนเทสต์ จึงนับว่าไม่มีตัวส่งเพิ่มหลังกดครั้งที่สอง
        $callbacksBefore = count((new \ReflectionProperty(app(), 'terminatingCallbacks'))->getValue(app()));
        Livewire::test(FollowDocument::class, ['record' => $document->id])->callAction(TestAction::make('remind')->table());
        $callbacksAfter = count((new \ReflectionProperty(app(), 'terminatingCallbacks'))->getValue(app()));

        $this->assertSame($callbacksBefore, $callbacksAfter);
    }

    public function test_nothing_is_sent_when_everyone_has_acknowledged(): void
    {
        Mail::fake();
        $done = $this->member();
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $document->acknowledges()->create(['user_id' => $done->id, 'revision' => $document->revision, 'acknowledge_date' => now()]);
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        Livewire::test(FollowDocument::class, ['record' => $document->id])->callAction(TestAction::make('remind')->table());

        Mail::assertNothingSent();
    }

    public function test_test_mode_sends_one_message_to_the_test_address_only(): void
    {
        Mail::fake();
        config(['mail.doc_test_recipient' => 'tester@example.test']);
        $this->member();
        $this->member();
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        Livewire::test(FollowDocument::class, ['record' => $document->id])->callAction(TestAction::make('remind')->table());

        Mail::assertSent(DocumentReminderMail::class, fn ($mail) => $mail->hasTo('tester@example.test') && $mail->testRecipientCount === 2 && $mail->bccAddresses === []);
        Mail::assertSent(DocumentReminderMail::class, 1);
    }

    public function test_regular_admin_cannot_open_the_follow_page(): void
    {
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));

        Livewire::test(FollowDocument::class, ['record' => $document->id])->assertForbidden();
    }
}

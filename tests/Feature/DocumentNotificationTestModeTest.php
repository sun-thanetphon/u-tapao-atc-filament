<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Mail\NewDocumentMail;
use App\Models\Document;
use App\Models\User;
use App\Support\DocumentNotifier;
use App\Support\MailQuota;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DocumentNotificationTestModeTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ADDRESS = 'owner@example.test';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        config([
            'mail.from.address' => 'noreply@example.test',
            'mail.daily_cap_for_documents' => 60,
            'mail.doc_test_recipient' => self::TEST_ADDRESS,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function recipient(string $section = 'ADC'): User
    {
        $this->counter++;
        $user = $this->makeUser($this->section($section));
        $user->forceFill(['email' => "person{$this->counter}@example.test", 'status' => UserStatus::ACTIVE])->save();

        return $user;
    }

    protected function doc(): Document
    {
        return $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('ADC')]);
    }

    protected function run_(Document $document): void
    {
        app(DocumentNotifier::class)->notify($document, true);
        app()->terminate();
    }

    protected function titles(): array
    {
        return $this->sentNotifications()->pluck('title')->all();
    }

    protected function render(NewDocumentMail $mail): string
    {
        return (string) app(\Illuminate\Mail\Markdown::class)->render('mail.new-document', $mail->content()->with);
    }

    public function test_test_mode_sends_one_message_only_to_the_test_address(): void
    {
        Mail::fake();
        $users = collect(range(1, 3))->map(fn () => $this->recipient());
        $document = $this->doc();
        $logs = new \ArrayObject();
        Log::listen(fn ($e) => $logs->append($e->message . json_encode($e->context)));

        $this->run_($document);

        $mails = Mail::sent(NewDocumentMail::class)->all();
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertTrue($mail->hasTo(self::TEST_ADDRESS));
        $this->assertCount(1, $mail->to);
        $this->assertSame([], $mail->bcc);
        $this->assertSame([], $mail->envelope()->bcc);
        $this->assertStringStartsWith('[ทดสอบ] ', $mail->envelope()->subject);

        $html = $this->render($mail) . $mail->envelope()->subject;
        $this->assertStringContainsString('โหมดทดสอบ: ถ้าส่งจริงอีเมลนี้จะถึงผู้ใช้ 3 คน (ยังไม่ได้ส่งถึงใคร)', $html);
        foreach ($users as $user) {
            $this->assertStringNotContainsString($user->email, $html);
            Mail::assertNotSent(NewDocumentMail::class, fn ($m) => $m->hasTo($user->email) || $m->hasBcc($user->email));
        }

        $this->assertNull($document->fresh()->notified_at);
        $this->assertSame(1, MailQuota::used());
        $this->assertContains('โหมดทดสอบ: ส่งอีเมลถึงที่อยู่ทดสอบ 1 ที่เท่านั้น (ผู้รับจริง 3 คน ยังไม่ได้ส่ง)', $this->titles());
        $this->assertNotEmpty($logs->getArrayCopy());
        $this->assertStringNotContainsString('@', implode(' ', $logs->getArrayCopy()));
    }

    public function test_document_can_still_be_notified_for_real_after_test_mode_is_turned_off(): void
    {
        Mail::fake();
        $user = $this->recipient();
        $document = $this->doc();

        $this->run_($document);
        $this->assertNull($document->fresh()->notified_at);

        config(['mail.doc_test_recipient' => null]);
        $this->run_($document->fresh());

        $this->assertNotNull($document->fresh()->notified_at);
        Mail::assertSent(NewDocumentMail::class, fn ($m) => $m->hasBcc($user->email) && ! $m->hasTo(self::TEST_ADDRESS));
    }


    public function test_test_mode_respects_the_daily_cap(): void
    {
        Mail::fake();
        $this->recipient();
        MailQuota::record(60);

        $this->run_($this->doc());

        Mail::assertNothingSent();
        $this->assertContains('โควตาอีเมลวันนี้เหลือไม่พอ ยังไม่ได้ส่ง ลองใหม่ภายหลังจากหน้าแก้ไขเอกสาร', $this->titles());
    }

    public function test_invalid_test_address_sends_nothing_and_warns(): void
    {
        Mail::fake();
        $this->recipient();
        $document = $this->doc();
        config(['mail.doc_test_recipient' => 'not-an-email']);

        $this->run_($document);

        Mail::assertNothingSent();
        $this->assertNull($document->fresh()->notified_at);
        $this->assertContains('MAIL_DOC_TEST_RECIPIENT ไม่ถูกต้อง ไม่ได้ส่งอีเมล', $this->titles());
        $this->assertSame(0, MailQuota::used());
    }

    public function test_blank_test_address_means_normal_behaviour(): void
    {
        Mail::fake();
        $user = $this->recipient();
        $document = $this->doc();
        config(['mail.doc_test_recipient' => '   ']);

        $this->run_($document);

        $this->assertNotNull($document->fresh()->notified_at);
        Mail::assertSent(NewDocumentMail::class, fn ($m) => $m->hasBcc($user->email) && $m->hasTo('noreply@example.test'));
    }

    public function test_test_mode_with_zero_real_recipients_still_sends_the_test_message(): void
    {
        Mail::fake();
        $document = $this->doc();

        $this->run_($document);

        $mails = Mail::sent(NewDocumentMail::class)->all();
        $this->assertCount(1, $mails);
        $this->assertTrue($mails[0]->hasTo(self::TEST_ADDRESS));
        $this->assertStringContainsString('ผู้ใช้ 0 คน', $this->render($mails[0]));
        $this->assertNull($document->fresh()->notified_at);
        $this->assertContains('โหมดทดสอบ: ส่งอีเมลถึงที่อยู่ทดสอบ 1 ที่เท่านั้น (ผู้รับจริง 0 คน ยังไม่ได้ส่ง)', $this->titles());
    }

    public function test_normal_mail_has_no_test_banner(): void
    {
        $document = $this->doc();
        $mail = new NewDocumentMail($document, ['person1@example.test']);

        $this->assertStringNotContainsString('โหมดทดสอบ', $this->render($mail));
        $this->assertStringNotContainsString('[ทดสอบ]', $mail->envelope()->subject);
    }
}

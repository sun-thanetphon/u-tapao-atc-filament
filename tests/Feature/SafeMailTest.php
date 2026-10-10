<?php

namespace Tests\Feature;

use App\Mail\NewRegistrationMail;
use App\Mail\RegistrationApprovedMail;
use App\Mail\RegistrationRejectedMail;
use App\Support\SafeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SafeMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_returns_true_when_mail_is_sent(): void
    {
        Mail::fake();
        $user = $this->makeUser($this->section('ADC'));

        $this->assertTrue(SafeMail::send('a@b.test', new NewRegistrationMail($user)));

        Mail::assertSent(NewRegistrationMail::class);
    }

    public function test_returns_false_and_logs_when_transport_throws(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('smtp down'));
        Log::shouldReceive('warning')->once();

        $this->assertFalse(SafeMail::send('a@b.test', new NewRegistrationMail($user)));
    }

    public function test_mailables_render_thai_content(): void
    {
        $user = $this->makeUser($this->section('ADC'));

        $new = (new NewRegistrationMail($user))->render();
        $this->assertStringContainsString($user->username, $new);
        $this->assertStringContainsString(route('filament.admin.resources.users.index'), $new);

        $approved = (new RegistrationApprovedMail($user, 'user'))->render();
        $this->assertStringContainsString(route('filament.admin.auth.login'), $approved);
        $this->assertStringContainsString('user', $approved);

        $rejected = (new RegistrationRejectedMail($user, 'ข้อมูลไม่ครบ'))->render();
        $this->assertStringContainsString('ข้อมูลไม่ครบ', $rejected);
        $this->assertStringNotContainsString('เหตุผล', (new RegistrationRejectedMail($user, null))->render());
    }
}

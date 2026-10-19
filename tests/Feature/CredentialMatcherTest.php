<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\CredentialMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    private function person(string $first, string $last, ?string $email = 'p@example.com'): User
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['firstname' => $first, 'lastname' => $last, 'email' => $email])->save();

        return $user;
    }

    public function test_matches_name_with_rank_glued_in_front(): void
    {
        $user = $this->person('สมคิด', 'สุนทรเพราะ');

        $result = (new CredentialMatcher())->match('น.ต.สมคิด สุนทรเพราะ');

        $this->assertSame(CredentialMatcher::OK, $result['status']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function test_odd_whitespace_and_case_are_ignored(): void
    {
        $this->person('Somkid', 'Sunthon');

        $result = (new CredentialMatcher())->match("  Flt Lt.  SOMKID \u{00A0} Sunthon ");

        $this->assertSame(CredentialMatcher::OK, $result['status']);
    }

    public function test_thai_marks_typed_in_a_different_order_still_match(): void
    {
        // ระบบ: ธ + สระอุ + การันต์ / ชีต: ธ + การันต์ + สระอุ (หน้าตาเหมือนกัน รหัสต่างกัน)
        $user = $this->person('อภิวัฒน์', "พิมพ์พันธ\u{0E38}\u{0E4C}ดี");

        $result = (new CredentialMatcher())->match("พ.จ.อ.อภิวัฒน์ พิมพ์พันธ\u{0E4C}\u{0E38}ดี");

        $this->assertSame(CredentialMatcher::OK, $result['status']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function test_unknown_name_has_no_account(): void
    {
        $this->person('สมคิด', 'สุนทรเพราะ');

        $this->assertSame(CredentialMatcher::NO_ACCOUNT, (new CredentialMatcher())->match('น.ต.คนอื่น นามสกุลอื่น')['status']);
    }

    public function test_two_users_with_the_same_name_are_not_mailed(): void
    {
        $this->person('สมคิด', 'สุนทรเพราะ');
        $this->person('สมคิด', 'สุนทรเพราะ');

        $result = (new CredentialMatcher())->match('น.ต.สมคิด สุนทรเพราะ');

        $this->assertSame(CredentialMatcher::DUPLICATE, $result['status']);
        $this->assertNull($result['user']);
    }

    public function test_user_without_valid_email_is_reported(): void
    {
        $this->person('สมคิด', 'สุนทรเพราะ', null);
        $this->person('มาลี', 'สุขใจ', 'not-an-email');

        $matcher = new CredentialMatcher();

        $this->assertSame(CredentialMatcher::NO_EMAIL, $matcher->match('น.ต.สมคิด สุนทรเพราะ')['status']);
        $this->assertSame(CredentialMatcher::NO_EMAIL, $matcher->match('ร.ท.มาลี สุขใจ')['status']);
    }

    public function test_inactive_and_deleted_users_are_ignored(): void
    {
        $pending = $this->person('สมคิด', 'สุนทรเพราะ');
        $pending->forceFill(['status' => UserStatus::PENDING])->save();
        $this->person('มาลี', 'สุขใจ')->delete();

        $matcher = new CredentialMatcher();

        $this->assertSame(CredentialMatcher::NO_ACCOUNT, $matcher->match('น.ต.สมคิด สุนทรเพราะ')['status']);
        $this->assertSame(CredentialMatcher::NO_ACCOUNT, $matcher->match('ร.ท.มาลี สุขใจ')['status']);
    }
}

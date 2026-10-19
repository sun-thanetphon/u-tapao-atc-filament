<?php

namespace App\Support;

use App\Models\User;

/**
 * จับคู่ชื่อในชีต (มียศติดหน้า เช่น "น.ต.สมคิด สุนทรเพราะ") กับบัญชีผู้ใช้ที่ยังใช้งานอยู่
 * ชื่อในชีตต้องลงท้ายด้วย ชื่อ+นามสกุล ของผู้ใช้ จึงไม่ต้องรู้รายการยศ
 */
class CredentialMatcher
{
    public const OK = 'ok';

    public const NO_ACCOUNT = 'no_account';

    public const DUPLICATE = 'duplicate';

    public const NO_EMAIL = 'no_email';

    /** @var list<array{key: string, user: User}> */
    private array $users;

    public function __construct()
    {
        $this->users = User::query()
            ->active()
            ->with('rank')
            ->get(['id', 'rank_id', 'firstname', 'lastname', 'email'])
            ->map(fn (User $user) => ['key' => self::normalize($user->firstname . $user->lastname), 'user' => $user])
            ->filter(fn (array $entry) => $entry['key'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{status: string, user: ?User}
     */
    public function match(string $sheetName): array
    {
        $name = self::normalize($sheetName);

        $found = array_values(array_filter(
            $this->users,
            fn (array $entry) => str_ends_with($name, $entry['key'])
        ));

        if ($found === []) {
            return ['status' => self::NO_ACCOUNT, 'user' => null];
        }

        if (count($found) > 1) {
            return ['status' => self::DUPLICATE, 'user' => null];
        }

        $user = $found[0]['user'];
        $email = trim((string) $user->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => self::NO_EMAIL, 'user' => $user];
        }

        return ['status' => self::OK, 'user' => $user];
    }

    public static function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        // พิมพ์ "ธุ์" ได้สองลำดับ (สระอุ+การันต์ หรือ การันต์+สระอุ) ที่หน้าตาเหมือนกันแต่รหัสต่างกัน
        // จึงเรียงเครื่องหมายที่ซ้อนบนตัวอักษรเดียวกันให้เป็นลำดับเดียว: สระ ก่อน วรรณยุกต์/การันต์
        $value = (string) preg_replace_callback(
            '/[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]{2,}/u',
            function (array $m): string {
                $marks = mb_str_split($m[0]);
                $vowels = array_filter($marks, fn (string $c) => mb_ord($c) < 0x0E47);
                $tones = array_filter($marks, fn (string $c) => mb_ord($c) >= 0x0E47);

                return implode('', $vowels) . implode('', $tones);
            },
            $value
        );

        return mb_strtolower((string) preg_replace('/[\s\x{200B}\x{00A0}]+/u', '', $value));
    }
}

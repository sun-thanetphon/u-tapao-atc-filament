<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Support\EmailImport;
use App\Support\EmailImportException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class EmailImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');

        // id 1 ถูกป้องกันสำหรับแอดมินทั่วไป จึงใช้ id นี้กับผู้ใช้ทิ้งก่อน ไม่ให้กระทบเทสต์อื่น
        $this->user();
    }

    private function user(?string $email = null, string $role = RoleEnum::USER): User
    {
        $user = $this->makeUser($this->section('ADC'), $role);

        if ($email !== null) {
            $user->forceFill(['email' => $email])->save();
        }

        return $user->fresh();
    }

    private function admin(string $role = RoleEnum::ADMIN): User
    {
        return $this->user(null, $role);
    }

    /** @return array{row:int,username:string,email:string} */
    private function row(int $n, string $username, string $email): array
    {
        return ['row' => $n, 'username' => $username, 'email' => $email];
    }

    private function csv(string $content, string $name = 'emails.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function reasons(array $skipped): array
    {
        return array_column($skipped, 'reason', 'row');
    }

    // ---------- วิเคราะห์ / นำเข้า (service) ----------

    public function test_happy_path_updates_only_users_without_email(): void
    {
        $a = $this->user();
        $b = $this->user();
        $actor = $this->admin();

        $rows = [
            $this->row(2, $a->username, 'person1@example.test'),
            $this->row(3, $b->username, 'person2@example.test'),
        ];

        $result = (new EmailImport)->apply($rows, false, $actor);

        $this->assertCount(2, $result->toUpdate);
        $this->assertSame('person1@example.test', $a->fresh()->email);
        $this->assertSame('person2@example.test', $b->fresh()->email);
    }

    public function test_unknown_username_is_skipped(): void
    {
        $actor = $this->admin();

        $result = (new EmailImport)->analyse([$this->row(2, 'nobody_here', 'x@example.test')], false, $actor);

        $this->assertCount(0, $result->toUpdate);
        $this->assertSame(['ไม่พบ username นี้'], array_values($this->reasons($result->skipped)));
        $this->assertSame(2, $result->skipped[0]['row']);
    }

    public function test_invalid_and_empty_email_are_skipped(): void
    {
        $a = $this->user();
        $b = $this->user();
        $actor = $this->admin();

        $result = (new EmailImport)->apply([
            $this->row(2, $a->username, 'not-an-email'),
            $this->row(3, $b->username, '   '),
        ], false, $actor);

        $reasons = $this->reasons($result->skipped);
        $this->assertSame('รูปแบบอีเมลไม่ถูกต้อง', $reasons[2]);
        $this->assertSame('ไม่มีอีเมล', $reasons[3]);
        $this->assertNull($a->fresh()->email);
        $this->assertNull($b->fresh()->email);
    }

    public function test_duplicate_email_in_file_skips_all_rows_involved(): void
    {
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        $actor = $this->admin();

        $result = (new EmailImport)->apply([
            $this->row(2, $a->username, 'same@example.test'),
            $this->row(3, $b->username, 'SAME@example.test'),
            $this->row(4, $c->username, 'unique@example.test'),
        ], false, $actor);

        $reasons = $this->reasons($result->skipped);
        $this->assertSame('อีเมลซ้ำกันในไฟล์', $reasons[2]);
        $this->assertSame('อีเมลซ้ำกันในไฟล์', $reasons[3]);
        $this->assertNull($a->fresh()->email);
        $this->assertNull($b->fresh()->email);
        $this->assertSame('unique@example.test', $c->fresh()->email);
    }

    public function test_email_owned_by_another_user_is_skipped(): void
    {
        $this->user('taken@example.test');
        $target = $this->user();
        $actor = $this->admin();

        $result = (new EmailImport)->apply([$this->row(2, $target->username, 'Taken@Example.test')], false, $actor);

        $this->assertSame('อีเมลนี้ถูกใช้โดยผู้ใช้อื่น', $result->skipped[0]['reason']);
        $this->assertNull($target->fresh()->email);
    }

    public function test_email_of_soft_deleted_user_can_be_reused(): void
    {
        $this->user('old@example.test')->delete();
        $target = $this->user();
        $actor = $this->admin();

        (new EmailImport)->apply([$this->row(2, $target->username, 'old@example.test')], false, $actor);

        $this->assertSame('old@example.test', $target->fresh()->email);
    }

    public function test_soft_deleted_username_is_not_matched(): void
    {
        $gone = $this->user();
        $gone->delete();
        $actor = $this->admin();

        $result = (new EmailImport)->analyse([$this->row(2, $gone->username, 'g@example.test')], false, $actor);

        $this->assertSame('ไม่พบ username นี้', $result->skipped[0]['reason']);
    }

    public function test_existing_email_not_overwritten_by_default(): void
    {
        $target = $this->user('keep@example.test');
        $actor = $this->admin();

        $result = (new EmailImport)->apply([$this->row(2, $target->username, 'new@example.test')], false, $actor);

        $this->assertSame('มีอีเมลอยู่แล้ว', $result->skipped[0]['reason']);
        $this->assertSame('keep@example.test', $target->fresh()->email);
    }

    public function test_existing_email_overwritten_when_ticked(): void
    {
        $target = $this->user('keep@example.test');
        $actor = $this->admin();

        $result = (new EmailImport)->apply([$this->row(2, $target->username, 'new@example.test')], true, $actor);

        $this->assertCount(1, $result->toUpdate);
        $this->assertSame('new@example.test', $target->fresh()->email);
    }

    public function test_same_email_is_unchanged_not_error(): void
    {
        $target = $this->user('same@example.test');
        $actor = $this->admin();

        $result = (new EmailImport)->apply([$this->row(2, $target->username, 'same@example.test')], false, $actor);

        $this->assertCount(1, $result->unchanged);
        $this->assertCount(0, $result->skipped);
        $this->assertCount(0, $result->toUpdate);
    }

    public function test_username_is_trimmed_and_case_insensitive_and_email_case_preserved(): void
    {
        $target = $this->user();
        $actor = $this->admin();

        (new EmailImport)->apply([
            $this->row(2, '  ' . strtoupper($target->username) . ' ', '  Person.One@Example.test  '),
        ], false, $actor);

        $this->assertSame('Person.One@Example.test', $target->fresh()->email);
    }

    public function test_non_super_admin_cannot_change_user_id_1_but_super_admin_can(): void
    {
        $first = User::query()->find(1) ?? $this->user();
        if ($first->id !== 1) {
            User::query()->whereKey($first->id)->update(['id' => 1]);
            $first = User::query()->findOrFail(1);
        }
        $admin = $this->admin();
        $super = $this->admin(RoleEnum::SUPERADMIN);

        $result = (new EmailImport)->apply([$this->row(2, $first->username, 'one@example.test')], true, $admin);
        $this->assertSame('ไม่มีสิทธิ์แก้ผู้ใช้นี้', $result->skipped[0]['reason']);
        $this->assertNull($first->fresh()->email);

        (new EmailImport)->apply([$this->row(2, $first->username, 'one@example.test')], true, $super);
        $this->assertSame('one@example.test', $first->fresh()->email);
    }

    public function test_import_never_touches_role_status_or_password(): void
    {
        $target = $this->user();
        $target->forceFill(['status' => UserStatus::REJECTED])->save();
        $before = $target->fresh();
        $actor = $this->admin();

        (new EmailImport)->apply([$this->row(2, $target->username, 'a@example.test')], false, $actor);

        $after = $target->fresh();
        $this->assertSame('a@example.test', $after->email);
        $this->assertSame(UserStatus::REJECTED, $after->status);
        $this->assertSame($before->password, $after->password);
        $this->assertTrue($after->hasRole(RoleEnum::USER));
    }

    public function test_rollback_when_exception_mid_apply(): void
    {
        $a = $this->user();
        $b = $this->user();
        $actor = $this->admin();

        User::updating(function (User $user) use ($b) {
            if ($user->id === $b->id) {
                throw new RuntimeException('boom');
            }
        });

        try {
            (new EmailImport)->apply([
                $this->row(2, $a->username, 'a@example.test'),
                $this->row(3, $b->username, 'b@example.test'),
            ], false, $actor);
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertNull($a->fresh()->email);
        $this->assertNull($b->fresh()->email);
    }

    public function test_apply_revalidates_against_fresh_db_state(): void
    {
        $a = $this->user();
        $b = $this->user();
        $actor = $this->admin();
        $rows = [$this->row(2, $a->username, 'race@example.test')];

        $service = new EmailImport;
        $preview = $service->analyse($rows, false, $actor);
        $this->assertCount(1, $preview->toUpdate);

        // ระหว่างดูตัวอย่าง มีแอดมินอื่นตั้งอีเมลนี้ให้คนอื่นไปแล้ว
        $b->forceFill(['email' => 'race@example.test'])->save();

        $result = $service->apply($rows, false, $actor);

        $this->assertCount(0, $result->toUpdate);
        $this->assertSame('อีเมลนี้ถูกใช้โดยผู้ใช้อื่น', $result->skipped[0]['reason']);
        $this->assertNull($a->fresh()->email);
    }

    // ---------- อ่านไฟล์ ----------

    public function test_reads_csv_with_bom_case_and_spacing_tolerance_and_extra_columns(): void
    {
        $content = "\xEF\xBB\xBF  Username , ชื่อ ,  EMAIL \r\n"
            . "alice , อลิซ , alice@example.test\r\n"
            . "\r\n"
            . ",,\r\n"
            . "bob,บ็อบ,bob@example.test\r\n";
        $file = $this->csv($content);

        $rows = (new EmailImport)->readFile($file->getRealPath(), 'csv');

        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame('alice@example.test', $rows[0]['email']);
        $this->assertSame(2, $rows[0]['row']);
        $this->assertSame('bob', $rows[1]['username']);
        $this->assertSame(5, $rows[1]['row']);
    }

    public function test_reads_semicolon_csv(): void
    {
        $file = $this->csv("username;email\nalice;alice@example.test\n");

        $rows = (new EmailImport)->readFile($file->getRealPath(), 'csv');

        $this->assertSame('alice@example.test', $rows[0]['email']);
    }

    public function test_reads_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['Username', 'Email'], ['alice', 'alice@example.test']]);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        $rows = (new EmailImport)->readFile($path, 'xlsx');
        @unlink($path);

        $this->assertCount(1, $rows);
        $this->assertSame('alice@example.test', $rows[0]['email']);
    }

    public function test_missing_column_throws_thai_error(): void
    {
        foreach (["name,email\nx,y@example.test\n", "username,mail\nx,y@example.test\n"] as $content) {
            $file = $this->csv($content);
            try {
                (new EmailImport)->readFile($file->getRealPath(), 'csv');
                $this->fail('expected exception');
            } catch (EmailImportException $e) {
                $this->assertStringContainsString('คอลัมน์', $e->getMessage());
            }
        }
    }

    public function test_more_than_1000_rows_rejected_and_1000_accepted(): void
    {
        $make = fn(int $n) => "username,email\n" . collect(range(1, $n))->map(fn($i) => "u{$i},u{$i}@example.test")->implode("\n") . "\n";

        $ok = $this->csv($make(1000));
        $rows = (new EmailImport)->readFile($ok->getRealPath(), 'csv');
        $this->assertCount(1000, $rows);

        $tooMany = $this->csv($make(1001));
        $this->expectException(EmailImportException::class);
        (new EmailImport)->readFile($tooMany->getRealPath(), 'csv');
    }

    // ---------- Filament action ----------

    private function importData(UploadedFile $file, bool $overwrite = false): array
    {
        return ['file' => $file, 'overwrite' => $overwrite];
    }

    public function test_action_imports_after_confirm_and_notifies_and_logs_without_pii(): void
    {
        Log::spy();
        $a = $this->user();
        $b = $this->user('has@example.test');
        $actor = $this->admin();
        $file = $this->csv("username,email\n{$a->username},person1@example.test\n{$b->username},person2@example.test\nnobody,z@example.test\n");

        Livewire::actingAs($actor)->test(ListUsers::class)
            ->callAction('importEmails', $this->importData($file))
            ->assertHasNoActionErrors();

        $this->assertSame('person1@example.test', $a->fresh()->email);
        $this->assertSame('has@example.test', $b->fresh()->email);
        $this->assertSame([], Storage::disk('local')->allFiles('email-imports'), 'ไฟล์ชั่วคราวต้องถูกลบหลังนำเข้า');

        $body = collect(session('filament.notifications', []))->pluck('body')->implode(' ');
        $this->assertStringContainsString('อัปเดต 1 ผู้ใช้ / ไม่เปลี่ยนแปลง 0 / ข้าม 2 แถว', $body);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($actor) {
            $dump = $message . json_encode($context);

            return ($context['actor_id'] ?? null) === $actor->id
                && ! str_contains($dump, '@')
                && ! str_contains($dump, 'nobody');
        })->once();
    }

    public function test_preview_step_writes_nothing_and_shows_summary(): void
    {
        $a = $this->user();
        $actor = $this->admin();
        $file = $this->csv("username,email\n{$a->username},person1@example.test\nnobody,z@example.test\n");

        $component = Livewire::actingAs($actor)->test(ListUsers::class)
            ->mountAction('importEmails')
            ->setActionData($this->importData($file))
            ->assertHasNoActionErrors()
            ->assertSee('ไม่พบ username นี้');

        $this->assertNull($a->fresh()->email, 'ดูตัวอย่างต้องไม่เขียนฐานข้อมูล');

        $component->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('person1@example.test', $a->fresh()->email);
    }

    public function test_action_rejects_file_with_missing_column_and_writes_nothing(): void
    {
        $a = $this->user();
        $actor = $this->admin();
        $file = $this->csv("username,mail\n{$a->username},person1@example.test\n");

        Livewire::actingAs($actor)->test(ListUsers::class)
            ->callAction('importEmails', $this->importData($file))
            ->assertHasActionErrors(['file']);

        $this->assertNull($a->fresh()->email);
    }

    public function test_user_without_user_edit_cannot_see_or_call_action(): void
    {
        $a = $this->user();
        $viewer = $this->user();
        $viewer->syncRoles([]);
        $viewer->givePermissionTo(PermissionEnum::USER_VIEW);
        $file = $this->csv("username,email\n{$a->username},person1@example.test\n");

        $component = Livewire::actingAs($viewer)->test(ListUsers::class);

        $component->assertActionHidden('importEmails');
        Storage::disk('local')->put('email-imports/valid.csv', "username,email
{$a->username},person1@example.test
");

        $component->call('mountAction', 'importEmails')
            ->set('mountedActionsData.0', ['file' => ['x' => 'email-imports/valid.csv'], 'overwrite' => false])
            ->call('callMountedAction');

        $this->assertNull($a->fresh()->email);
    }

    public function test_handler_aborts_403_even_if_visibility_is_bypassed(): void
    {
        $viewer = $this->user();
        $viewer->syncRoles([]);
        $this->actingAs($viewer);

        try {
            \App\Filament\Resources\UserResource::runEmailImport(
                $this->csv("username,email\nx,y@example.test\n")->store('email-imports', 'local'),
                false,
            );
            $this->fail('expected 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }


    // ---------- ความปลอดภัย / ความแข็งแรง ----------

    public function test_tampered_path_is_rejected_and_nothing_is_deleted(): void
    {
        $a = $this->user();
        $actor = $this->admin();
        Storage::disk('local')->put('documents/a.pdf', 'pdf-bytes');
        Storage::disk('local')->put('documents/other.csv', "username,email\n{$a->username},hack@example.test\n");

        foreach (['documents/a.pdf', 'email-imports/../documents/a.pdf', 'documents/other.csv', 'email-imports/../documents/other.csv'] as $path) {
            Livewire::actingAs($actor)->test(ListUsers::class)
                ->callAction('importEmails', ['file' => ['x' => $path], 'overwrite' => false]);

            $titles = collect(session('filament.notifications', []))->pluck('title')->implode(' ');
            $this->assertStringContainsString('ไม่พบไฟล์ที่อัปโหลด', $titles, $path);
            session()->forget('filament.notifications');
        }

        $this->assertTrue(Storage::disk('local')->exists('documents/a.pdf'));
        $this->assertTrue(Storage::disk('local')->exists('documents/other.csv'));
        $this->assertNull($a->fresh()->email);
    }

    public function test_preview_does_not_read_tampered_path(): void
    {
        $actor = $this->admin();
        Storage::disk('local')->put('documents/other.csv', "username,email\nzzz,hack@example.test\n");
        $this->actingAs($actor);

        $html = UserResource::emailImportPreview(['x' => 'documents/other.csv'], false)->render();

        $this->assertStringContainsString('ไม่พบไฟล์ที่อัปโหลด', $html);
    }

    public function test_file_over_2mb_is_rejected_before_parsing(): void
    {
        $file = $this->csv("username,email
" . str_repeat('a', 3 * 1024 * 1024));

        try {
            (new EmailImport)->readFile($file->getRealPath(), 'csv');
            $this->fail('expected exception');
        } catch (EmailImportException $e) {
            $this->assertStringContainsString('2 MB', $e->getMessage());
        }
    }

    public function test_xlsx_with_too_many_rows_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $rows = [['username', 'email']];
        for ($i = 1; $i <= 2100; $i++) {
            $rows[] = ["u{$i}", "u{$i}@example.test"];
        }
        $book->getActiveSheet()->fromArray($rows);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        try {
            (new EmailImport)->readFile($path, 'xlsx');
            $this->fail('expected exception');
        } catch (EmailImportException $e) {
            $this->assertStringContainsString('เกิน', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_preview_without_file_returns_empty_without_error(): void
    {
        $this->actingAs($this->admin());

        $html = UserResource::emailImportPreview(null, false)->render();

        $this->assertStringNotContainsString('ไม่พบไฟล์', $html);
    }

    public function test_too_many_rows_with_blank_lines_still_rejected(): void
    {
        $body = "username,email\n" . str_repeat("\n", 5000) . collect(range(1, 1001))->map(fn($i) => "u{$i},u{$i}@example.test")->implode("\n") . "\n";
        $file = $this->csv($body);

        $this->expectException(EmailImportException::class);
        (new EmailImport)->readFile($file->getRealPath(), 'csv');
    }

    public function test_collation_lookalike_of_another_users_email_is_skipped(): void
    {
        $this->user('jose@example.test');
        $target = $this->user();
        $actor = $this->admin();

        $result = (new EmailImport)->apply([$this->row(2, $target->username, 'josé@example.test')], false, $actor);

        $this->assertSame('อีเมลนี้ถูกใช้โดยผู้ใช้อื่น', $result->skipped[0]['reason']);
        $this->assertCount(0, $result->toUpdate);
        $this->assertNull($target->fresh()->email);
    }

    public function test_collation_equal_emails_in_one_file_save_at_most_one(): void
    {
        $a = $this->user();
        $b = $this->user();
        $actor = $this->admin();
        $rows = [
            $this->row(2, $a->username, 'jose@example.test'),
            $this->row(3, $b->username, 'josé@example.test'),
        ];

        $preview = (new EmailImport)->analyse($rows, false, $actor);
        $result = (new EmailImport)->apply($rows, false, $actor);

        $this->assertCount(1, $preview->toUpdate, 'ตัวอย่างต้องตรงกับผลจริง');
        $this->assertCount(1, $result->toUpdate);
        $this->assertCount(1, $result->skipped);
        $this->assertSame('jose@example.test', $a->fresh()->email);
        $this->assertNull($b->fresh()->email);
    }

    public function test_case_only_difference_needs_overwrite(): void
    {
        $target = $this->user('Case@example.test');
        $actor = $this->admin();
        $rows = [$this->row(2, $target->username, 'case@example.test')];

        $off = (new EmailImport)->apply($rows, false, $actor);
        $this->assertSame('มีอีเมลอยู่แล้ว', $off->skipped[0]['reason']);
        $this->assertSame('Case@example.test', $target->fresh()->email);

        (new EmailImport)->apply($rows, true, $actor);
        $this->assertSame('case@example.test', $target->fresh()->email);
    }

    // ---------- CSV แบบ stream / path ----------

    private function readCsvString(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        try {
            return (new EmailImport)->readFile($path, 'csv');
        } finally {
            @unlink($path);
        }
    }

    public function test_crafted_csv_with_thousands_of_blank_lines_is_rejected_not_fatal(): void
    {
        $content = "username,email\n" . str_repeat(',', 29) . "\n" . str_repeat("\n", 20000) . "a,b@example.test\n";

        $this->expectException(EmailImportException::class);
        $this->readCsvString($content);
    }

    public function test_crafted_csv_of_only_blank_lines_is_rejected_not_fatal(): void
    {
        $this->expectException(EmailImportException::class);
        $this->readCsvString("username,email\n" . str_repeat("\n", 1_900_000));
    }

    public function test_crafted_csv_with_300_columns_is_handled(): void
    {
        $header = 'username,email,' . implode(',', array_map(fn($i) => "c{$i}", range(1, 298)));
        $row = 'alice,alice@example.test,' . implode(',', array_fill(0, 298, 'x'));

        $rows = $this->readCsvString("{$header}\n{$row}\n");

        $this->assertSame([['row' => 2, 'username' => 'alice', 'email' => 'alice@example.test']], $rows);
    }

    public function test_csv_streaming_keeps_quoted_comma_and_thai_text(): void
    {
        $rows = $this->readCsvString("\xEF\xBB\xBF Email ,ชื่อ, USERNAME \n\"a@example.test\",\"สมชาย, ใจดี\", alice \n");

        $this->assertSame([['row' => 2, 'username' => 'alice', 'email' => 'a@example.test']], $rows);
    }

    public function test_1000_row_csv_reads_and_1001_is_rejected_immediately(): void
    {
        $make = fn(int $n) => "username,email\n" . collect(range(1, $n))->map(fn($i) => "u{$i},u{$i}@example.test")->implode("\n") . "\n";

        $this->assertCount(1000, $this->readCsvString($make(1000)));

        $this->expectException(EmailImportException::class);
        $this->readCsvString($make(1001));
    }

    public function test_path_with_trailing_newline_or_nested_array_gives_thai_error_and_deletes_nothing(): void
    {
        $a = $this->user();
        $actor = $this->admin();
        Storage::disk('local')->put('email-imports/x.csv', "username,email\n{$a->username},hack@example.test\n");

        // ค่า nested array ถูก Filament ปฏิเสธเองในกฎของ FileUpload ก่อนถึงโค้ดนี้ จึงทดสอบที่ตัว handler โดยตรง
        $this->actingAs($actor);
        Livewire::actingAs($actor)->test(ListUsers::class)
            ->callAction('importEmails', ['file' => ['x' => "email-imports/x.csv\n"], 'overwrite' => false]);

        $titles = collect(session('filament.notifications', []))->pluck('title')->implode(' ');
        $this->assertStringContainsString('ไม่พบไฟล์ที่อัปโหลด', $titles);

        foreach (["email-imports/x.csv\n", ''] as $path) {
            $this->assertNull(UserResource::runEmailImport($path, false));
        }

        try {
            UserResource::readEmailImportFile(['x' => ['y' => 'email-imports/x.csv']]);
            $this->fail('expected exception');
        } catch (EmailImportException $e) {
            $this->assertSame('ไม่พบไฟล์ที่อัปโหลด', $e->getMessage());
        }

        $this->assertTrue(Storage::disk('local')->exists('email-imports/x.csv'));
        $this->assertNull($a->fresh()->email);
    }
}

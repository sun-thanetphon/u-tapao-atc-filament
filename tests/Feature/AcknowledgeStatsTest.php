<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\DocumentResource\Pages\FollowDocument;
use App\Support\AcknowledgeStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcknowledgeStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_counts_only_acknowledgements_from_required_sections(): void
    {
        $adcUsers = collect(range(1, 4))->map(fn () => $this->makeUser($this->section('ADC')));
        $appUser = $this->makeUser($this->section('APP'));

        $document = $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('ADC')]);

        foreach ($adcUsers->take(3) as $user) {
            $document->acknowledges()->create(['user_id' => $user->id, 'acknowledge_date' => now()]);
        }
        // คนนอกแผนกที่ต้องรับทราบ ไม่นับ
        $document->acknowledges()->create(['user_id' => $appUser->id, 'acknowledge_date' => now()]);

        $stats = AcknowledgeStats::get();

        $this->assertSame(4, $stats->required());
        $this->assertSame(3, $stats->done());
        $this->assertEqualsWithDelta(0.75, $stats->rate(), 0.0001);
        $this->assertSame(['ADC'], $stats->sectionPrefixes([$this->section('ADC')]));
        $this->assertSame(4, $stats->sections[$this->section('ADC')]['required']);
        $this->assertSame(3, $stats->sections[$this->section('ADC')]['done']);
        $this->assertNull($stats->sections[$this->section('APP')]['rate']);
    }

    public function test_pending_is_sorted_by_lowest_progress_and_excludes_complete_documents(): void
    {
        $users = collect(range(1, 4))->map(fn () => $this->makeUser($this->section('ADC')));

        $half = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $none = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $complete = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $readOnly = $this->makeDocument([$this->section('ADC')]);

        foreach ($users->take(2) as $user) {
            $half->acknowledges()->create(['user_id' => $user->id, 'acknowledge_date' => now()]);
        }
        foreach ($users as $user) {
            $complete->acknowledges()->create(['user_id' => $user->id, 'acknowledge_date' => now()]);
        }

        $pending = AcknowledgeStats::get()->pending();

        $this->assertSame([$none->id, $half->id], $pending->pluck('id')->all());
        $this->assertNotContains($readOnly->id, AcknowledgeStats::get()->documents->pluck('id'));
    }

    public function test_rate_is_null_when_nothing_requires_acknowledgement(): void
    {
        $this->makeUser($this->section('ADC'));
        $this->makeDocument([$this->section('ADC')]);

        $this->assertNull(AcknowledgeStats::get()->rate());
        $this->assertTrue(AcknowledgeStats::get()->pending()->isEmpty());
    }

    public function test_pending_and_rejected_users_are_excluded_from_figures_and_follow_table(): void
    {
        $active = $this->makeUser($this->section('ADC'));
        $pending = $this->makeUser($this->section('ADC'));
        $rejected = $this->makeUser($this->section('ADC'));
        $pending->forceFill(['status' => UserStatus::PENDING])->save();
        $rejected->forceFill(['status' => UserStatus::REJECTED])->save();

        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $document->acknowledges()->create(['user_id' => $rejected->id, 'acknowledge_date' => now()]);

        $stats = AcknowledgeStats::get();
        $this->assertSame(1, $stats->required());
        $this->assertSame(0, $stats->done());

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));
        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$pending, $rejected]);
    }
}

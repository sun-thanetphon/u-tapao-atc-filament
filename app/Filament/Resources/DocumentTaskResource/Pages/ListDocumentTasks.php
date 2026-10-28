<?php

namespace App\Filament\Resources\DocumentTaskResource\Pages;

use Filament\Schemas\Components\Tabs\Tab;
use App\Filament\Concerns\SearchesDocuments;
use App\Filament\Resources\DocumentTaskResource;
use App\Models\Document;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListDocumentTasks extends ListRecords
{
    use SearchesDocuments;

    protected static string $resource = DocumentTaskResource::class;

    /** ประเภทที่เลือก: null = ทั้งหมด, 'none' = ไม่มีประเภท, นอกนั้นเป็น id ของประเภท */
    #[Url(as: 'category')]
    public ?string $category = null;

    public function updatedCategory(): void
    {
        $this->category = filled($this->category) ? $this->category : null;
        $this->resetPage();
    }

    /** @var array<int, array<string, mixed>>|null คำนวณครั้งเดียวต่อ request */
    protected ?array $categoryCards = null;

    public function categoryCards(): array
    {
        return $this->categoryCards ??= DocumentTaskResource::categoryCards();
    }

    /**
     * หลังรับทราบเอกสาร จำนวนที่รอรับทราบบนการ์ดต้องคำนวณใหม่
     */
    public function forgetCategoryCards(): void
    {
        $this->categoryCards = null;
    }

    /**
     * ประเภทที่ใช้กรองจริง: ค่าที่ไม่มีการ์ดรองรับ (เช่น ลิงก์เก่า ประเภทที่ถูกลบ หรือประเภทที่แผนกนี้ไม่เห็น) ถือเป็น "ทั้งหมด"
     */
    public function activeCategory(): ?string
    {
        if (blank($this->category)) {
            return null;
        }

        return collect($this->categoryCards())->contains('key', $this->category) ? $this->category : null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'เอกสารของฉัน';
    }

    public function getSubheading(): ?string
    {
        $pending = $this->pendingCount();
        $scope = $this->activeCategory() ? 'ในประเภทนี้' : '';

        return $pending > 0
            ? "มีเอกสาร{$scope} {$pending} ฉบับที่รอให้คุณรับทราบ อ่านแล้วกด \"รับทราบ\" ที่ท้ายรายการ"
            : ($scope ? 'คุณรับทราบเอกสารในประเภทนี้ครบทุกฉบับแล้ว' : 'คุณรับทราบเอกสารครบทุกฉบับแล้ว');
    }

    /** จำนวนเอกสารที่รอรับทราบ ตามประเภทที่เลือก (ไม่เลือก = ทุกประเภท) */
    private function pendingCount(): int
    {
        return DocumentTaskResource::whereCategory(
            DocumentTaskResource::pendingQuery(Document::query()),
            $this->activeCategory(),
        )->count();
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('รอรับทราบ')
                ->icon('heroicon-m-clock')
                // คำนวณตอนเรนเดอร์ เพื่อให้ตามประเภทที่เพิ่งเลือก (แท็บถูกแคชไว้ก่อนค่าประเภทอัปเดต)
                ->badge(fn () => $this->pendingCount() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => DocumentTaskResource::pendingQuery($query)),
            'acknowledged' => Tab::make('รับทราบแล้ว')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query) => DocumentTaskResource::acknowledgedQuery($query)),
            'all' => Tab::make('ทั้งหมด'),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return DocumentTaskResource::pendingQuery(Document::query())->exists() ? 'pending' : 'all';
    }
}

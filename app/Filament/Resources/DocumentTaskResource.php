<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Filament\Resources\DocumentTaskResource\Pages;
use App\Models\Document;
use App\Models\DocumentCategory;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class DocumentTaskResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Tasks & Acknowledgements';

    protected static ?string $label = 'Document Tasks';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::TASK_VIEW);
    }

    /**
     * จำนวนเอกสารที่ผู้ใช้ยังต้องรับทราบ แสดงเป็น badge ที่เมนู
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::pendingQuery(Document::query())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'เอกสารที่รอการรับทราบ';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * เอกสารที่แผนกของผู้ใช้มองเห็น
     */
    public static function visibleQuery(Builder $query): Builder
    {
        return $query->publish()
            ->where(fn (Builder $query) => static::whereSectionIn($query, 'view_sections'));
    }

    /**
     * เอกสารที่แผนกของผู้ใช้ต้องรับทราบ แต่ผู้ใช้ยังไม่ได้รับทราบ
     */
    public static function pendingQuery(Builder $query): Builder
    {
        return static::visibleQuery($query)
            ->where(fn (Builder $query) => static::whereSectionIn($query, 'acknowledge_sections'))
            ->whereDoesntHave('acknowledges', fn (Builder $query) => $query->where('user_id', auth()->id()));
    }

    public static function acknowledgedQuery(Builder $query): Builder
    {
        return static::visibleQuery($query)
            ->whereHas('acknowledges', fn (Builder $query) => $query->where('user_id', auth()->id()));
    }

    /**
     * ฟอร์มเก็บ id แผนกเป็น string ใน JSON จึงเช็กทั้ง string และ int
     */
    private static function whereSectionIn(Builder $query, string $column): Builder
    {
        $sectionId = auth()->user()->section_id;

        return $query->whereJsonContains($column, (string) $sectionId)
            ->orWhereJsonContains($column, (int) $sectionId);
    }

    /**
     * สถานะของเอกสารสำหรับผู้ใช้ปัจจุบัน: pending, acknowledged หรือ read-only
     */
    public static function statusFor(Document $record): string
    {
        if ($record->acknowledges->isNotEmpty()) {
            return 'acknowledged';
        }

        $sections = array_map('strval', $record->acknowledge_sections ?? []);

        return in_array((string) auth()->user()->section_id, $sections, true) ? 'pending' : 'read-only';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::visibleQuery($query)
                ->with([
                    'category:id,name',
                    // โหลดเฉพาะการรับทราบของผู้ใช้ปัจจุบัน
                    'acknowledges' => fn ($query) => $query->where('user_id', auth()->id()),
                ]))
            ->defaultSort('created_at', 'desc')
            ->recordClasses(fn (Document $record) => 'uta-task uta-task-' . static::statusFor($record))
            ->columns([
                Split::make([
                    Stack::make([
                        Tables\Columns\TextColumn::make('name')
                            ->label('ชื่อเอกสาร')
                            ->weight(FontWeight::Medium)
                            ->wrap()
                            ->searchable(),
                        Tables\Columns\TextColumn::make('code')
                            ->label('รหัส')
                            ->color('gray')
                            ->size(Tables\Columns\TextColumn\TextColumnSize::Small)
                            ->formatStateUsing(fn (Document $record) => new HtmlString(
                                e($record->code) . ($record->category ? '<span class="uta-task-category">' . e($record->category->name) . '</span>' : '')
                            ))
                            ->searchable(),
                    ])->space(1),

                    Tables\Columns\TextColumn::make('status')
                        ->label('สถานะ')
                        ->badge()
                        ->grow(false)
                        ->getStateUsing(fn (Document $record) => static::statusFor($record))
                        ->formatStateUsing(fn (string $state, Document $record) => match ($state) {
                            'acknowledged' => 'รับทราบแล้ว ' . static::thaiDate($record->acknowledges->first()->acknowledge_date),
                            'pending' => 'รอรับทราบ',
                            default => 'อ่านอย่างเดียว',
                        })
                        ->icon(fn (string $state) => match ($state) {
                            'acknowledged' => 'heroicon-m-check-circle',
                            'pending' => 'heroicon-m-clock',
                            default => null,
                        })
                        ->color(fn (string $state) => match ($state) {
                            'acknowledged' => 'success',
                            'pending' => 'warning',
                            default => 'gray',
                        }),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('ประเภทเอกสาร')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->options(fn () => DocumentCategory::query()->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('viewPdf')
                    ->url(function (Document $record) {
                        [$folder, $path] = explode('/', $record->file_path, 2);

                        return route('view.pdf', ['folder' => $folder, 'path' => $path]);
                    })
                    ->openUrlInNewTab()
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->icon('heroicon-m-document-text')
                    ->label('อ่านเอกสาร'),

                Tables\Actions\Action::make('acknowledge')
                    ->visible(fn (Document $record) => static::statusFor($record) === 'pending')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-document-check')
                    ->modalIconColor('primary')
                    ->modalHeading(fn (Document $record) => 'รับทราบ: ' . $record->name)
                    ->modalDescription('คุณยืนยันว่าได้อ่านและรับทราบเอกสารนี้แล้ว การกด "รับทราบ" ถือเป็นการยอมรับข้อกำหนดและเงื่อนไขทั้งหมดในเอกสารนี้')
                    ->modalSubmitActionLabel('รับทราบ')
                    ->modalCancelActionLabel('ยกเลิก')
                    ->action(function (Document $record) {
                        $record->acknowledges()->firstOrCreate(
                            ['user_id' => auth()->id()],
                            ['acknowledge_date' => now()],
                        );

                        Notification::make()
                            ->title('รับทราบเอกสารแล้ว')
                            ->body($record->name)
                            ->icon('heroicon-o-document-check')
                            ->iconColor('success')
                            ->send();
                    })
                    ->button()
                    ->color('primary')
                    ->icon('heroicon-m-check')
                    ->label('รับทราบ'),
            ])
            ->emptyStateIcon('heroicon-o-document-check')
            ->emptyStateHeading('ไม่มีเอกสารในรายการนี้')
            ->emptyStateDescription('เอกสารที่แผนกของคุณต้องอ่านหรือรับทราบจะแสดงที่นี่')
            ->paginated([10, 25, 50]);
    }

    private static function thaiDate($date): string
    {
        $date = \Illuminate\Support\Carbon::parse($date)->locale('th');

        return $date->translatedFormat('j M') . ' ' . ($date->year + 543);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTasks::route('/'),
        ];
    }
}

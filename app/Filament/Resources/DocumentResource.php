<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ToggleButtons;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Actions\ActionGroup;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\FollowDocument;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Section;
use App\Models\User;
use App\Support\DocumentNotifier;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 2;

    protected static string | \UnitEnum | null $navigationGroup = 'Document Management';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::DOCUMENT_VIEW);
    }

    /**
     * ป้ายแผนกจาก id เช่น ["1","2"] => "ADC, APP" โหลดตารางแผนกครั้งเดียวต่อ request ไม่ query ต่อแถว
     */
    protected static function sectionPrefixes(?array $ids): string
    {
        static $prefixes = null;
        $prefixes ??= Section::pluck('prefix', 'id');

        return collect($ids)->map(fn ($id) => $prefixes[(int) $id] ?? '')->filter()->implode(', ');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make()
                    ->schema([
                        Select::make('view_sections')
                            ->label('แผนกที่ต้องเห็นเอกสาร')
                            ->required()
                            ->options(function () {
                                return Section::get()->mapWithKeys(function ($section) {
                                    return [$section->id => "{$section->name} ({$section->prefix})"];
                                });
                            })
                            ->afterStateUpdated(function (Set $set) {
                                $set('acknowledge_sections', []);
                            })
                            ->multiple()
                            ->live(),
                        Select::make('acknowledge_sections')
                            ->label('แผนกที่ต้องรับทราบเอกสาร')
                            ->multiple()
                            ->options(function (Get $get): Collection {
                                return Section::whereIn('id', $get('view_sections'))->get()->mapWithKeys(function ($section) {
                                    return [$section->id => "{$section->name} ({$section->prefix})"];
                                });
                            }),
                        Select::make('category_id')
                            ->label('ประเภทเอกสาร')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->relationship('category', 'name'),
                        TextInput::make('name')
                            ->label('ชื่อเอกสาร')
                            ->required()
                            ->maxLength(255),

                    ])->columns(2),
                FileUpload::make('file_path')
                    ->label('File Upload')
                    ->columnSpanFull()
                    ->directory('documents')
                    ->disk('local')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(104857600)
                    ->maxFiles(1)
                    ->required(),
                ToggleButtons::make('require_reacknowledge')
                    ->label('ถ้าเปลี่ยนไฟล์ ผู้ใช้ต้องรับทราบใหม่หรือไม่?')
                    ->helperText('เลือก "ไม่ต้อง" ถ้าเป็นการแก้ไขเล็กน้อย เช่น แก้คำผิด ผู้ที่รับทราบแล้วจะไม่ต้องรับทราบซ้ำ และไม่ส่งอีเมลแจ้งใหม่')
                    ->boolean('ต้องรับทราบใหม่', 'ไม่ต้อง (แก้ไขเล็กน้อย)')
                    ->colors([1 => 'warning', 0 => 'info'])
                    ->icons([1 => 'heroicon-m-arrow-path', 0 => 'heroicon-m-pencil'])
                    ->grouped()
                    // หน้าแก้ไขเติมค่าจาก record ซึ่งไม่มีคอลัมน์นี้ จึงต้องตั้งค่าเริ่มต้นตรงนี้แทน default()
                    ->formatStateUsing(fn (): bool => true)
                    // ไม่ใช่คอลัมน์ในฐานข้อมูล: อ่านค่าจากฟอร์มก่อนบันทึกแทน
                    ->dehydrated(false)
                    // มีผลเฉพาะตอนแก้ไขเอกสารที่ต้องรับทราบ
                    ->visible(fn (?Document $record): bool => $record?->isNeedToAck() ?? false),
                TextInput::make('revision_note')
                    ->label('ไฟล์ใหม่เปลี่ยนอะไร (ไม่บังคับ)')
                    ->helperText('บันทึกไว้ในประวัติฉบับเมื่อเปลี่ยนไฟล์')
                    ->maxLength(255)
                    ->columnSpanFull()
                    // ไม่ใช่คอลัมน์ในฐานข้อมูล: อ่านค่าจากฟอร์มก่อนบันทึกแทน
                    ->dehydrated(false)
                    // มีผลเฉพาะตอนแก้ไขเอกสาร
                    ->visible(fn (?Document $record): bool => $record !== null),
                \Filament\Schemas\Components\Section::make()
                    ->description('ต้องการประกาศให้เป็นสาธารณะหรือไม่?')
                    ->schema([
                        ToggleButtons::make('publish')
                            ->label('การเผยแพร่')
                            ->boolean('เผยแพร่', 'ซ่อน')
                            ->icons([1 => 'heroicon-m-eye', 0 => 'heroicon-m-eye-slash'])
                            ->grouped()
                            ->required()
                            ->default(true),
                        ToggleButtons::make('send_notification')
                            ->label('ส่งอีเมลแจ้งเตือนผู้ที่ต้องรับทราบ')
                            ->helperText('ส่งครั้งเดียวเมื่อเผยแพร่เอกสารที่ต้องรับทราบ ถ้าเลือก "ไม่ส่ง" ตอนนี้ ยังเลือกส่งภายหลังจากหน้าแก้ไขได้')
                            ->boolean('ส่ง', 'ไม่ส่ง')
                            ->icons([1 => 'heroicon-m-envelope', 0 => 'heroicon-m-x-mark'])
                            ->grouped()
                            // หน้าสร้างเลือกส่งไว้ให้ ส่วนหน้าแก้ไขต้องเลือกเอง กันส่งอีเมลโดยไม่ตั้งใจ
                            // ใช้ formatStateUsing แทน default() เพราะหน้าแก้ไขเติมค่าจาก record ซึ่งไม่มีคอลัมน์นี้
                            ->formatStateUsing(fn (?Document $record): bool => $record === null)
                            // ไม่ใช่คอลัมน์ในฐานข้อมูล: อ่านค่าจากฟอร์มหลังบันทึกแทน
                            ->dehydrated(false)
                            // ส่งแล้วจะไม่ส่งซ้ำอีก
                            ->hidden(fn (?Document $record): bool => $record?->notified_at !== null),
                    ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('ค้นหาชื่อเอกสาร รหัส หรือประเภท')
            ->searchDebounce('400ms')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('ประเภทเอกสาร')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('ชื่อเอกสาร')
                    ->searchable(),
                ToggleColumn::make('publish')
                    ->onIcon('heroicon-o-eye') // กำหนดไอคอนเมื่อเปิด (เมื่อค่าเป็น true)
                    ->offIcon('heroicon-o-x-mark')    // กำหนดไอคอนเมื่อปิด (เมื่อค่าเป็น false)
                    ->onColor('success')        // กำหนดสีเมื่อเปิด (สามารถใช้สีเช่น success, danger, info, etc.)
                    ->offColor('danger'),
                TextColumn::make('view_sections')
                    ->alignCenter()
                    ->formatStateUsing(fn ($record) => static::sectionPrefixes($record->view_sections))

                    ->label('แผนกที่ต้องเห็นเอกสาร'),
                TextColumn::make('acknowledge_sections')
                    ->alignCenter()
                    ->formatStateUsing(fn ($record) => static::sectionPrefixes($record->acknowledge_sections))

                    ->label('แผนกที่ต้องรับทราบเอกสาร'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('เลือกประเภทเอกสาร')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpan(2)
                    ->options(DocumentCategory::all()->pluck('name', 'id'))
            ], FiltersLayout::AboveContent)
            ->recordActions([

                ActionGroup::make([
                    Action::make('viewPdf')
                        ->url(function ($record) {
                            $filePath = $record->file_path;
                            $pathParts = explode('/', $filePath);
                            $folder = $pathParts[0];
                            $path = $pathParts[1];
                            return route('view.pdf', ['folder' => $folder, 'path' => $path]);
                        })
                        ->color('success')
                        ->icon('heroicon-o-eye')
                        ->label('อ่าน')
                        ->openUrlInNewTab(),
                    Action::make('history')
                        ->label('ประวัติฉบับ')
                        ->icon('heroicon-o-clock')
                        ->color('gray')
                        ->modalHeading(fn (Document $record): string => 'ประวัติฉบับ: ' . $record->name)
                        ->modalContent(fn (Document $record) => view('filament.resources.document-resource.file-history', [
                            'document' => $record,
                            'revisions' => $record->files()->with('uploader.rank')->get()->groupBy('revision')->sortKeysDesc(),
                            'acknowledged' => $record->acknowledges()
                                ->selectRaw('revision, count(*) as total')
                                ->groupBy('revision')
                                ->pluck('total', 'revision'),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('ปิด'),
                    Action::make('follow')
                        ->icon('heroicon-o-arrow-trending-up')
                        ->hidden(function ($record) {
                            return !auth()->user()->hasRole(RoleEnum::SUPERADMIN) || !$record->isNeedToAck();
                        })
                        ->label('ติดตามการรับทราบ')
                        ->url(fn(Document $record): string => route('filament.admin.resources.documents.follow', $record)),
                    EditAction::make()
                        ->before(function (Document $record, $livewire): void {
                            $raw = $livewire->getMountedTableActionForm()?->getRawState() ?? [];
                            $record->keepRevision = ! ($raw['require_reacknowledge'] ?? true);
                            $record->revisionNote = trim((string) ($raw['revision_note'] ?? '')) ?: null;
                        })
                        ->after(function (Document $record, $livewire): void {
                            app(DocumentNotifier::class)->notify($record, DocumentNotifier::wantedIn($livewire->getMountedTableActionForm()));
                        }),
                    DeleteAction::make(),
                ])
                    ->button()

            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'follow' => FollowDocument::route('/{record}/follow'),
        ];
    }
}

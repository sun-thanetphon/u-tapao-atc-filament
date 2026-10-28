<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Utilities\Get;
use Closure;
use Filament\Forms\Components\ToggleButtons;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\TutorialVideoResource\Pages\ListTutorialVideos;
use App\Filament\Resources\TutorialVideoResource\Pages\CreateTutorialVideo;
use App\Filament\Resources\TutorialVideoResource\Pages\EditTutorialVideo;
use App\Enums\RoleEnum;
use App\Filament\Resources\TutorialVideoResource\Pages;
use App\Models\TutorialVideo;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * จัดการวิดีโอแนะนำ (เฉพาะ super-admin และ admin) ส่วนหน้าดูอยู่ที่ Pages\TutorialVideos
 */
class TutorialVideoResource extends Resource
{
    protected static ?string $model = TutorialVideo::class;

    protected static ?string $slug = 'manage-learning-video';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-video-camera';

    protected static string | \UnitEnum | null $navigationGroup = 'Public Links';

    protected static ?string $navigationLabel = 'จัดการวิดีโอแนะนำ';

    protected static ?string $modelLabel = 'วิดีโอแนะนำ';

    // ภาษาไทยไม่มีรูปพหูพจน์ ถ้าไม่ตั้งไว้ Filament จะเติม "s" ให้เป็น "วิดีโอแนะนำs"
    protected static ?string $pluralModelLabel = 'วิดีโอแนะนำ';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([RoleEnum::SUPERADMIN, RoleEnum::ADMIN]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('title')
                            ->label('ชื่อวิดีโอ')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('คำอธิบาย')
                            ->columnSpanFull(),
                        Radio::make('source')
                            ->label('แหล่งวิดีโอ')
                            ->options([
                                'upload' => 'อัปโหลดไฟล์ (mp4 / webm)',
                                'embed' => 'ฝังลิงก์ YouTube / Vimeo / Google Drive',
                            ])
                            ->default('upload')
                            ->required()
                            ->live(),
                        FileUpload::make('file_path')
                            ->label('ไฟล์วิดีโอ')
                            ->helperText('แนะนำบีบเป็น 720p ก่อนอัปโหลด ขนาดไม่เกิน 300 MB')
                            ->disk('local')
                            ->directory('tutorials')
                            ->acceptedFileTypes(['video/mp4', 'video/webm'])
                            ->maxSize(300 * 1024)
                            ->visible(fn (Get $get) => $get('source') === 'upload')
                            ->required(fn (Get $get) => $get('source') === 'upload'),
                        TextInput::make('embed_url')
                            ->label('ลิงก์วิดีโอ')
                            ->helperText('เช่น https://youtu.be/xxxxxxxxxxx, https://vimeo.com/123456789 หรือลิงก์แชร์ไฟล์ Google Drive (ต้องตั้งให้ "ทุกคนที่มีลิงก์" ดูได้)')
                            ->url()
                            ->visible(fn (Get $get) => $get('source') === 'embed')
                            ->required(fn (Get $get) => $get('source') === 'embed')
                            ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                                if ((new TutorialVideo(['embed_url' => $value]))->embedSrc() === null) {
                                    $fail('รองรับเฉพาะลิงก์ YouTube, Vimeo และ Google Drive');
                                }
                            }),
                        TextInput::make('seq')
                            ->label('ลำดับ')
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                        ToggleButtons::make('publish')
                            ->label('การเผยแพร่')
                            ->boolean('เผยแพร่', 'ซ่อน')
                            ->icons([1 => 'heroicon-m-eye', 0 => 'heroicon-m-eye-slash'])
                            ->grouped()
                            ->required()
                            ->default(true)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('seq')->label('ลำดับ'),
                TextColumn::make('title')->label('ชื่อวิดีโอ')->searchable(),
                TextColumn::make('source')
                    ->label('แหล่ง')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'upload' ? 'ไฟล์' : 'ลิงก์ฝัง'),
                IconColumn::make('publish')->label('เผยแพร่')->boolean(),
                TextColumn::make('created_at')->label('สร้างเมื่อ')->dateTime()->sortable(),
            ])
            ->defaultSort(fn ($query) => $query->orderBy('seq', 'asc')->orderByDesc('created_at'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTutorialVideos::route('/'),
            'create' => CreateTutorialVideo::route('/create'),
            'edit' => EditTutorialVideo::route('/{record}/edit'),
        ];
    }
}

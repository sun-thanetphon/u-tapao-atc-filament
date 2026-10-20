<?php

namespace App\Filament\Resources;

use App\Enums\RoleEnum;
use App\Filament\Resources\TutorialVideoResource\Pages;
use App\Models\TutorialVideo;
use Filament\Forms;
use Filament\Forms\Form;
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

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationGroup = 'Public Links';

    protected static ?string $navigationLabel = 'จัดการวิดีโอแนะนำ';

    protected static ?string $modelLabel = 'วิดีโอแนะนำ';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([RoleEnum::SUPERADMIN, RoleEnum::ADMIN]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('ชื่อวิดีโอ')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->label('คำอธิบาย')
                            ->columnSpanFull(),
                        Forms\Components\Radio::make('source')
                            ->label('แหล่งวิดีโอ')
                            ->options([
                                'upload' => 'อัปโหลดไฟล์ (mp4 / webm)',
                                'embed' => 'ฝังลิงก์ YouTube / Vimeo',
                            ])
                            ->default('upload')
                            ->required()
                            ->live(),
                        Forms\Components\FileUpload::make('file_path')
                            ->label('ไฟล์วิดีโอ')
                            ->helperText('แนะนำบีบเป็น 720p ก่อนอัปโหลด ขนาดไม่เกิน 300 MB')
                            ->disk('local')
                            ->directory('tutorials')
                            ->acceptedFileTypes(['video/mp4', 'video/webm'])
                            ->maxSize(300 * 1024)
                            ->visible(fn (Forms\Get $get) => $get('source') === 'upload')
                            ->required(fn (Forms\Get $get) => $get('source') === 'upload'),
                        Forms\Components\TextInput::make('embed_url')
                            ->label('ลิงก์วิดีโอ')
                            ->helperText('เช่น https://youtu.be/xxxxxxxxxxx หรือ https://vimeo.com/123456789')
                            ->url()
                            ->visible(fn (Forms\Get $get) => $get('source') === 'embed')
                            ->required(fn (Forms\Get $get) => $get('source') === 'embed')
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if ((new TutorialVideo(['embed_url' => $value]))->embedSrc() === null) {
                                    $fail('รองรับเฉพาะลิงก์ YouTube และ Vimeo');
                                }
                            }),
                        Forms\Components\TextInput::make('seq')
                            ->label('ลำดับ')
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                        Forms\Components\ToggleButtons::make('publish')
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
                Tables\Columns\TextColumn::make('seq')->label('ลำดับ'),
                Tables\Columns\TextColumn::make('title')->label('ชื่อวิดีโอ')->searchable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('แหล่ง')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'upload' ? 'ไฟล์' : 'ลิงก์ฝัง'),
                Tables\Columns\IconColumn::make('publish')->label('เผยแพร่')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('สร้างเมื่อ')->dateTime()->sortable(),
            ])
            ->defaultSort(fn ($query) => $query->orderBy('seq', 'asc')->orderByDesc('created_at'))
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTutorialVideos::route('/'),
            'create' => Pages\CreateTutorialVideo::route('/create'),
            'edit' => Pages\EditTutorialVideo::route('/{record}/edit'),
        ];
    }
}

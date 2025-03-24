<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Section;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Documents management';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::DOCUMENT_VIEW);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('view_sections')
                            ->label('แผนกที่ต้องเห็นเอกสาร')
                            ->required()
                            ->options(function () {
                                return Section::get()->mapWithKeys(function ($section) {
                                    return [$section->id => "{$section->name} ({$section->prefix})"];
                                });
                            })
                            ->multiple()
                            ->live(),
                        Forms\Components\Select::make('acknowledge_sections')
                            ->label('แผนกที่ต้องรับทราบเอกสาร')
                            ->multiple()
                            ->options(function (Forms\Get $get): Collection {
                                return Section::whereIn('id', $get('view_sections'))->get()->mapWithKeys(function ($section) {
                                    return [$section->id => "{$section->name} ({$section->prefix})"];
                                });
                            }),
                        Forms\Components\Select::make('category_id')
                            ->searchable()
                            ->preload()
                            ->relationship('category', 'name'),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                    ])->columns(2),
                Forms\Components\FileUpload::make('file_path')
                    ->label('File Upload')
                    ->columnSpanFull()
                    ->directory('documents')
                    ->disk('local')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(104857600)
                    ->maxFiles(1)
                    ->required(),
                Forms\Components\Section::make()
                    ->description('ต้องการประกาศให้เป็นสาธารณะหรือไม่?')
                    ->schema([
                        Forms\Components\Toggle::make('publish')
                            ->onIcon('heroicon-m-eye')
                            ->offIcon('heroicon-m-x-circle')
                            ->onColor('success')
                            ->offColor('danger')
                            ->default(true),
                    ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // ->modifyQueryUsing(function (Builder $query) {
            //     if (auth()->user()->hasRole(RoleEnum::TRAINER)) {
            //         return  $query->whereJsonContains('trainers', ['trainer_id' => (string)auth()->user()->id]);
            //     }
            //     return $query;
            // })
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('publish')
                    ->onIcon('heroicon-o-eye') // กำหนดไอคอนเมื่อเปิด (เมื่อค่าเป็น true)
                    ->offIcon('heroicon-o-x-mark')    // กำหนดไอคอนเมื่อปิด (เมื่อค่าเป็น false)
                    ->onColor('success')        // กำหนดสีเมื่อเปิด (สามารถใช้สีเช่น success, danger, info, etc.)
                    ->offColor('danger'),
                Tables\Columns\TextColumn::make('view_sections')
                    ->alignCenter()
                    ->formatStateUsing(function ($record) {
                        $sectionNames = "";
                        $sections = $record->view_sections;
                        $totalSections = count($sections);

                        foreach ($sections as $index => $section) {
                            $sectionName = Section::findOrFail($section)->prefix;

                            $sectionNames .= $sectionName;
                            if ($index < $totalSections - 1) {
                                $sectionNames .= ", ";
                            }
                        }
                        return $sectionNames;
                    })

                    ->label('แผนกที่ต้องเห็นเอกสาร'),
                Tables\Columns\TextColumn::make('acknowledge_sections')
                    ->alignCenter()
                    ->formatStateUsing(function ($record) {
                        $sectionNames = "";
                        $sections = $record->acknowledge_sections;
                        $totalSections = count($sections);

                        foreach ($sections as $index => $section) {
                            $sectionName = Section::findOrFail($section)->prefix;

                            $sectionNames .= $sectionName;
                            if ($index < $totalSections - 1) {
                                $sectionNames .= ", ";
                            }
                        }
                        return $sectionNames;
                    })

                    ->label('แผนกที่ต้องรับทราบเอกสาร'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('ประเภทเอกสาร')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpan(2)
                    ->options(DocumentCategory::all()->pluck('name', 'id'))
            ], Tables\Enums\FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('viewPdf')
                    ->url(function ($record) {
                        $filePath = $record->file_path;
                        $pathParts = explode('/', $filePath);
                        $folder = $pathParts[0];
                        $path = $pathParts[1];
                        return route('view.pdf', ['folder' => $folder, 'path' => $path]);
                    })
                    ->color('success')
                    ->button()
                    ->icon('heroicon-o-eye')
                    ->label('View')
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('follow')
                    ->hidden(function ($record) {
                        return !$record->isNeedToAck();
                    })
                    ->label('Follow')
                    ->url(fn(Document $record): string => route('filament.admin.resources.documents.follow', $record))
                    ->button(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListDocuments::route('/'),
            'follow' => Pages\FollowDocument::route('/{record}/follow'),
        ];
    }
}

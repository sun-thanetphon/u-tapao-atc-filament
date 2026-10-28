<?php

namespace App\Filament\Resources\UserResource\Pages;

use Filament\Schemas\Components\Tabs\Tab;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Forms\Components\FileUpload;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use App\Enums\PermissionEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\EmailImportException;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('รออนุมัติ')
                ->badge(User::query()->status(UserStatus::PENDING)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', UserStatus::PENDING)),
            'active' => Tab::make('ใช้งานอยู่')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', UserStatus::ACTIVE)),
            'rejected' => Tab::make('ไม่อนุมัติ')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', UserStatus::REJECTED)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create new account'),
            $this->importEmailsAction(),
        ];
    }

    /**
     * นำเข้าอีเมลจากไฟล์: อัปโหลด -> ดูตัวอย่าง (ไม่เขียนฐานข้อมูล) -> ยืนยัน
     */
    protected function importEmailsAction(): Action
    {
        return Action::make('importEmails')
            ->label('นำเข้าอีเมล')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn() => (bool) auth()->user()?->can(PermissionEnum::USER_EDIT))
            ->modalHeading('นำเข้าอีเมลจากไฟล์')
            ->modalSubmitActionLabel('ยืนยันนำเข้า')
            ->steps([
                Step::make('อัปโหลดไฟล์')
                    ->description('ไฟล์ .csv, .xlsx หรือ .xls ที่มีคอลัมน์ username และ email (ไม่เกิน 1,000 แถว)')
                    ->schema([
                        FileUpload::make('file')
                            ->label('ไฟล์')
                            ->required()
                            ->disk('local')
                            ->directory('email-imports')
                            ->visibility('private')
                            ->maxSize(2048)
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/csv',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->rules([
                                fn() => function (string $attribute, mixed $value, Closure $fail) {
                                    try {
                                        UserResource::readEmailImportFile($value);
                                    } catch (EmailImportException $e) {
                                        $fail($e->getMessage());
                                    }
                                },
                            ]),
                        Checkbox::make('overwrite')
                            ->label('ทับอีเมลเดิม')
                            ->helperText('ถ้าไม่ติ๊ก ผู้ใช้ที่มีอีเมลอยู่แล้วจะถูกข้าม')
                            ->default(false),
                    ]),
                Step::make('ตรวจสอบก่อนนำเข้า')
                    ->description('ตรวจสอบสรุปด้านล่าง ยังไม่มีการบันทึกจนกว่าจะกดยืนยัน')
                    ->schema([
                        TextEntry::make('preview')
                            ->hiddenLabel()
                            ->state(fn(Get $get) => UserResource::emailImportPreview($get('file'), (bool) $get('overwrite'))),
                    ]),
            ])
            ->action(function (array $data) {
                $file = is_array($data['file']) ? collect($data['file'])->first() : $data['file'];

                UserResource::runEmailImport(is_string($file) ? $file : '', (bool) ($data['overwrite'] ?? false));
            });
    }
}

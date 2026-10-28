<?php

namespace App\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Http\UploadedFile;
use Throwable;
use Illuminate\Contracts\View\View;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Mail\RegistrationApprovedMail;
use App\Mail\RegistrationRejectedMail;
use App\Models\User;
use App\Support\EmailImport;
use App\Support\EmailImportException;
use App\Support\EmailImportResult;
use App\Support\SafeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    protected static string | \UnitEnum | null $navigationGroup = 'Accounts';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::USER_VIEW);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canApprove()) {
            return null;
        }

        $count = User::query()->status(UserStatus::PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * บทบาทที่เลือกได้ตอนอนุมัติ (ไม่รวม super-admin)
     */
    public static function approvableRoles(): array
    {
        return [
            RoleEnum::USER => 'user',
            RoleEnum::ADMIN => 'admin',
        ];
    }

    protected static function roleField(): Select
    {
        return Select::make('role')
            ->label('บทบาท')
            ->options(static::approvableRoles())
            ->default(RoleEnum::USER)
            ->required()
            ->native(false)
            ->rules([Rule::in(array_keys(static::approvableRoles()))]);
    }

    /**
     * ช่องอีเมล (ไม่บังคับ) ใช้ร่วมกับหน้าโปรไฟล์: ตัดช่องว่าง, ไม่ซ้ำกับผู้ใช้ที่ยังไม่ถูกลบ, ค่าว่างเก็บเป็น null
     */
    public static function emailField(): TextInput
    {
        return TextInput::make('email')
            ->label('อีเมล')
            ->email()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(fn($state, Set $set) => $set('email', is_string($state) ? trim($state) : $state))
            ->unique(
                table: 'users',
                column: 'email',
                ignoreRecord: true,
                modifyRuleUsing: fn(Unique $rule) => $rule->whereNull('deleted_at'),
            )
            ->dehydrateStateUsing(fn($state) => filled(trim((string) $state)) ? trim($state) : null)
            ->dehydrated();
    }

    /**
     * อ่านไฟล์นำเข้าอีเมลจากค่าของช่องอัปโหลด (ไฟล์ชั่วคราวระหว่างดูตัวอย่าง หรือ path ใน disk local หลังบันทึกแล้ว)
     *
     * @return list<array{row:int,username:string,email:string}>
     */
    public static function readEmailImportFile(mixed $file): array
    {
        $file = is_array($file) ? (collect($file)->first()) : $file;

        if ($file instanceof UploadedFile) {
            return (new EmailImport)->readFile($file->getRealPath(), $file->getClientOriginalExtension());
        }

        if (static::isStoredEmailImportPath($file)) {
            try {
                $exists = Storage::disk('local')->exists($file);
                $fullPath = $exists ? Storage::disk('local')->path($file) : null;
            } catch (Throwable $e) {
                $fullPath = null;
            }

            if ($fullPath !== null) {
                return (new EmailImport)->readFile($fullPath, pathinfo($file, PATHINFO_EXTENSION));
            }
        }

        throw new EmailImportException('ไม่พบไฟล์ที่อัปโหลด');
    }

    /**
     * path ที่รับได้ต้องเป็นไฟล์ที่ action นี้เก็บเองใน email-imports/ เท่านั้น (ค่าจาก Livewire แก้ได้จากฝั่งผู้ใช้)
     */
    public static function isStoredEmailImportPath(mixed $path): bool
    {
        return is_string($path)
            && ! str_contains($path, '..')
            && preg_match('#^email-imports/[A-Za-z0-9._-]+\.(csv|xlsx|xls)\z#i', $path) === 1;
    }

    /**
     * ตัวอย่างผลนำเข้าสำหรับขั้นตอนที่ 2 (ไม่เขียนฐานข้อมูล)
     */
    public static function emailImportPreview(mixed $file, bool $overwrite): View
    {
        $result = null;
        $error = null;

        // ยังไม่มีไฟล์ (เช่นยังอยู่ขั้นแรก) ไม่ต้องอ่านหรือแจ้งอะไร
        if (blank($file)) {
            return view('filament.resources.user-resource.email-import-preview', ['result' => null, 'error' => null]);
        }

        try {
            $result = (new EmailImport)->analyse(static::readEmailImportFile($file), $overwrite, auth()->user());
        } catch (EmailImportException $e) {
            $error = $e->getMessage();
        }

        return view('filament.resources.user-resource.email-import-preview', ['result' => $result, 'error' => $error]);
    }

    /**
     * ขั้นยืนยันนำเข้าอีเมล: ตรวจสิทธิ์ซ้ำฝั่งเซิร์ฟเวอร์ อ่านไฟล์ใหม่แล้วตรวจซ้ำกับฐานข้อมูลใน transaction เดียว
     */
    public static function runEmailImport(string $path, bool $overwrite): ?EmailImportResult
    {
        abort_unless(auth()->user()?->can(PermissionEnum::USER_EDIT), 403);

        // ลบเฉพาะไฟล์ที่ผ่านการตรวจ path แล้วเท่านั้น ไม่เช่นนั้นห้ามแตะไฟล์ใดเลย
        if (! static::isStoredEmailImportPath($path)) {
            Notification::make()->title('ไม่พบไฟล์ที่อัปโหลด')->danger()->send();

            return null;
        }

        try {
            $rows = static::readEmailImportFile($path);
            $result = (new EmailImport)->apply($rows, $overwrite, auth()->user());
        } catch (EmailImportException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        } finally {
            Storage::disk('local')->delete($path);
        }

        $updated = count($result->toUpdate);
        $unchanged = count($result->unchanged);
        $skipped = count($result->skipped);

        Log::info('Email import finished', [
            'actor_id' => auth()->id(),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
        ]);

        Notification::make()
            ->title('นำเข้าอีเมลเรียบร้อยแล้ว')
            ->body("อัปเดต {$updated} ผู้ใช้ / ไม่เปลี่ยนแปลง {$unchanged} / ข้าม {$skipped} แถว")
            ->success()
            ->send();

        return $result;
    }

    protected static function canApprove(): bool
    {
        return (bool) auth()->user()?->can(PermissionEnum::USER_APPROVE);
    }

    protected static function authorizeApprove(): void
    {
        abort_unless(static::canApprove(), 403);
    }

    /**
     * อนุมัติเฉพาะแถวที่ยัง pending อยู่จริง (อัปเดตแบบมีเงื่อนไขครั้งเดียว ต่อแถว) คืนจำนวนที่อนุมัติได้
     */
    protected static function approveRecords(Collection $records, string $role): int
    {
        static::authorizeApprove();
        abort_unless(in_array($role, array_keys(static::approvableRoles()), true), 422);

        $approved = 0;
        $mailFailed = false;

        foreach ($records as $record) {
            $changed = DB::transaction(function () use ($record, $role) {
                $affected = User::query()
                    ->where('id', $record->id)
                    ->where('status', UserStatus::PENDING)
                    ->update([
                        'status' => UserStatus::ACTIVE,
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                        'rejected_reason' => null,
                    ]);

                if ($affected === 0) {
                    return false;
                }

                User::query()->findOrFail($record->id)->syncRoles([$role]);

                return true;
            });

            if (! $changed) {
                continue;
            }

            $approved++;

            $user = User::query()->find($record->id);
            if (filled($user->email) && ! SafeMail::send($user->email, new RegistrationApprovedMail($user, $role))) {
                $mailFailed = true;
            }
        }

        static::notifyResult($records->count(), $approved, 'อนุมัติบัญชีเรียบร้อยแล้ว', $mailFailed);

        return $approved;
    }

    protected static function rejectRecord(User $record, ?string $reason): void
    {
        static::authorizeApprove();

        $reason = filled($reason) ? $reason : null;

        $affected = User::query()
            ->where('id', $record->id)
            ->where('status', UserStatus::PENDING)
            ->update([
                'status' => UserStatus::REJECTED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'rejected_reason' => $reason,
            ]);

        $mailFailed = false;
        if ($affected > 0) {
            $user = User::query()->find($record->id);
            $mailFailed = filled($user->email)
                && ! SafeMail::send($user->email, new RegistrationRejectedMail($user, $reason));
        }

        static::notifyResult(1, $affected, 'ไม่อนุมัติบัญชีเรียบร้อยแล้ว', $mailFailed);
    }

    protected static function notifyResult(int $requested, int $changed, string $successTitle, bool $mailFailed): void
    {
        if ($changed === 0) {
            Notification::make()->title('รายการนี้ถูกดำเนินการไปแล้ว')->warning()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();

        if ($changed < $requested) {
            Notification::make()->title('บางรายการถูกดำเนินการไปแล้ว จึงข้ามไป')->warning()->send();
        }

        if ($mailFailed) {
            Notification::make()->title('ส่งอีเมลไม่สำเร็จ')->warning()->send();
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('rank_id')
                            ->relationship('rank', 'name')
                            ->required(),
                        Select::make('section_id')
                            ->relationship('section', 'name')
                            ->required(),
                        TextInput::make('username')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('firstname')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('lastname')
                            ->required()
                            ->maxLength(255),
                        static::emailField(),
                        TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->hint('อย่างน้อย 8 ตัวอักษร')
                            ->minLength(8)
                            ->revealable()
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create'),
                    ])->columns(2),

                Section::make('Roles & Permissions')
                    ->schema([
                        Select::make('roles')
                            ->preload()
                            ->required(fn(string $context): bool => $context === 'create')
                            ->native(false)
                            ->extraAttributes([
                                'class' => 'uta-capitalize'
                            ])
                            ->relationship(
                                'roles',
                                'name',
                                modifyQueryUsing: fn(Builder $query) => $query->where('name', '<>', RoleEnum::SUPERADMIN)->orderBy('id')
                            )
                            ->reactive(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // super-admin ก่อน แล้ว admin แล้วผู้ใช้ทั่วไป ในแต่ละกลุ่มเรียงตามเวลาสมัคร (เก่าสุดก่อน)
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw(
                    "(select coalesce(min(case roles.name when ? then 0 when ? then 1 else 2 end), 3)"
                    . " from roles inner join model_has_roles on roles.id = model_has_roles.role_id"
                    . " where model_has_roles.model_id = users.id and model_has_roles.model_type = ?)",
                    [RoleEnum::SUPERADMIN, RoleEnum::ADMIN, User::class]
                )
                ->orderBy('users.created_at')
                ->orderBy('users.id'))
            ->columns([
                TextColumn::make('section.name')
                    ->getStateUsing(function ($record) {
                        return  $record->section->name . " (" . $record->section->prefix . ")";
                    })
                    ->sortable(),
                TextColumn::make('username')
                    ->searchable(),
                // อยู่ถัดจาก username เพื่อให้เห็นตำแหน่งโดยไม่ต้องเลื่อนตารางไปทางขวา
                TextColumn::make('roles.name')
                    ->badge()
                    ->sortable()
                    ->extraAttributes([
                        'class' => 'uta-capitalize'
                    ])
                    ->label('Role')
                    ->color(fn($record) => match ($record->roles->first()?->name) {
                        RoleEnum::SUPERADMIN  => 'warning',
                        RoleEnum::ADMIN => 'info',
                        RoleEnum::USER => 'success',
                        default => 'secondary'
                    }),
                TextColumn::make('rank.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('firstname')
                    ->searchable(),
                TextColumn::make('lastname')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('อีเมล')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('วันที่สมัคร')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('อนุมัติ')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(User $record) => $record->status === UserStatus::PENDING && static::canApprove())
                    ->modalHeading('อนุมัติบัญชีผู้ใช้')
                    ->schema([static::roleField()])
                    ->action(fn(User $record, array $data) => static::approveRecords(collect([$record]), $data['role'])),
                Action::make('reject')
                    ->label('ไม่อนุมัติ')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(User $record) => $record->status === UserStatus::PENDING && static::canApprove())
                    ->modalHeading('ไม่อนุมัติบัญชีผู้ใช้')
                    ->schema([
                        Textarea::make('reason')
                            ->label('เหตุผล (ถ้ามี)')
                            ->maxLength(500),
                    ])
                    ->action(fn(User $record, array $data) => static::rejectRecord($record, $data['reason'] ?? null)),
                Action::make('reopen')
                    ->label('เปิดพิจารณาใหม่')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn(User $record) => $record->status === UserStatus::REJECTED && static::canApprove())
                    ->action(function (User $record) {
                        static::authorizeApprove();

                        $affected = User::query()
                            ->where('id', $record->id)
                            ->where('status', UserStatus::REJECTED)
                            ->update(['status' => UserStatus::PENDING]);

                        $affected > 0
                            ? Notification::make()->title('ย้ายกลับไปรออนุมัติแล้ว')->success()->send()
                            : Notification::make()->title('รายการนี้ถูกดำเนินการไปแล้ว')->warning()->send();
                    }),
                ViewAction::make()
                    ->hidden(function ($record) {
                        if (!auth()->user()->hasRole(RoleEnum::SUPERADMIN)) {
                            return $record->id == 1 ? true : false;
                        }
                    }),
                EditAction::make()
                    ->hidden(function ($record) {
                        if (!auth()->user()->hasRole(RoleEnum::SUPERADMIN)) {
                            return $record->id == 1 ? true : false;
                        }
                    }),
                DeleteAction::make()
                    ->hidden(function ($record) {
                        return $record->id == auth()->user()->id || $record->id == 1;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('อนุมัติที่เลือก')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn() => static::canApprove())
                        ->modalHeading('อนุมัติบัญชีที่เลือก')
                        ->schema([static::roleField()])
                        ->action(fn(Collection $records, array $data) => static::approveRecords($records, $data['role']))
                        ->deselectRecordsAfterCompletion(),
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
            'index' => ListUsers::route('/'),
        ];
    }
}

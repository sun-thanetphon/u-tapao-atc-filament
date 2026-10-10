<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Mail\RegistrationApprovedMail;
use App\Mail\RegistrationRejectedMail;
use App\Models\User;
use App\Support\SafeMail;
use Filament\Forms;
use Filament\Forms\Form;
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

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Accounts';

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

    protected static function roleField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('role')
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
    public static function emailField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('email')
            ->label('อีเมล')
            ->email()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(fn($state, Forms\Set $set) => $set('email', is_string($state) ? trim($state) : $state))
            ->unique(
                table: 'users',
                column: 'email',
                ignoreRecord: true,
                modifyRuleUsing: fn(\Illuminate\Validation\Rules\Unique $rule) => $rule->whereNull('deleted_at'),
            )
            ->dehydrateStateUsing(fn($state) => filled(trim((string) $state)) ? trim($state) : null)
            ->dehydrated();
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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('rank_id')
                            ->relationship('rank', 'name')
                            ->required(),
                        Forms\Components\Select::make('section_id')
                            ->relationship('section', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('firstname')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('lastname')
                            ->required()
                            ->maxLength(255),
                        static::emailField(),
                        Forms\Components\TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->hint('อย่างน้อย 8 ตัวอักษร')
                            ->minLength(8)
                            ->revealable()
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create'),
                    ])->columns(2),

                Forms\Components\Section::make('Roles & Permissions')
                    ->schema([
                        Forms\Components\Select::make('roles')
                            ->preload()
                            ->required(fn(string $context): bool => $context === 'create')
                            ->native(false)
                            ->extraAttributes([
                                'class' => 'capitalize'
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
            ->columns([
                Tables\Columns\TextColumn::make('section.name')
                    ->getStateUsing(function ($record) {
                        return  $record->section->name . " (" . $record->section->prefix . ")";
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rank.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('firstname')
                    ->searchable(),
                Tables\Columns\TextColumn::make('lastname')
                    ->searchable(),
                Tables\Columns\TagsColumn::make('roles.name')
                    ->sortable()
                    ->extraAttributes([
                        'class' => 'capitalize'
                    ])
                    ->label('Role')
                    ->color(fn($record) => match ($record->roles->first()?->name) {
                        RoleEnum::SUPERADMIN  => 'warning',
                        RoleEnum::ADMIN => 'info',
                        RoleEnum::USER => 'success',
                        default => 'secondary'
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('อนุมัติ')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(User $record) => $record->status === UserStatus::PENDING && static::canApprove())
                    ->modalHeading('อนุมัติบัญชีผู้ใช้')
                    ->form([static::roleField()])
                    ->action(fn(User $record, array $data) => static::approveRecords(collect([$record]), $data['role'])),
                Tables\Actions\Action::make('reject')
                    ->label('ไม่อนุมัติ')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(User $record) => $record->status === UserStatus::PENDING && static::canApprove())
                    ->modalHeading('ไม่อนุมัติบัญชีผู้ใช้')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('เหตุผล (ถ้ามี)')
                            ->maxLength(500),
                    ])
                    ->action(fn(User $record, array $data) => static::rejectRecord($record, $data['reason'] ?? null)),
                Tables\Actions\Action::make('reopen')
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
                Tables\Actions\ViewAction::make()
                    ->hidden(function ($record) {
                        if (!auth()->user()->hasRole(RoleEnum::SUPERADMIN)) {
                            return $record->id == 1 ? true : false;
                        }
                    }),
                Tables\Actions\EditAction::make()
                    ->hidden(function ($record) {
                        if (!auth()->user()->hasRole(RoleEnum::SUPERADMIN)) {
                            return $record->id == 1 ? true : false;
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->hidden(function ($record) {
                        return $record->id == auth()->user()->id || $record->id == 1;
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approveSelected')
                        ->label('อนุมัติที่เลือก')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn() => static::canApprove())
                        ->modalHeading('อนุมัติบัญชีที่เลือก')
                        ->form([static::roleField()])
                        ->action(fn(Collection $records, array $data) => static::approveRecords($records, $data['role']))
                        ->deselectRecordsAfterCompletion(),
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
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}

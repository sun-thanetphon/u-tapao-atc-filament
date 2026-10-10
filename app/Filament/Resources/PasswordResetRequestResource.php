<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Filament\Resources\PasswordResetRequestResource\Pages;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Support\PasswordResetLink;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use RuntimeException;
use Throwable;

class PasswordResetRequestResource extends Resource
{
    protected static ?string $model = PasswordResetRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'คำขอรีเซ็ตรหัสผ่าน';

    protected static ?string $modelLabel = 'คำขอรีเซ็ตรหัสผ่าน';

    protected static ?string $pluralModelLabel = 'คำขอรีเซ็ตรหัสผ่าน';

    public static function canAccess(): bool
    {
        return static::canApprove();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canApprove()) {
            return null;
        }

        $count = PasswordResetRequest::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected static function canApprove(): bool
    {
        return (bool) auth()->user()?->can(PermissionEnum::USER_APPROVE);
    }

    /**
     * บัญชี id 1 (super-admin หลัก) รีเซ็ตได้เฉพาะ super-admin เท่านั้น
     */
    protected static function isProtectedTarget(PasswordResetRequest $request): bool
    {
        return (int) $request->user_id === 1 && ! auth()->user()?->hasRole(RoleEnum::SUPERADMIN);
    }

    protected static function canHandle(PasswordResetRequest $request): bool
    {
        return $request->isOpen()
            && static::canApprove()
            && ! static::isProtectedTarget($request)
            && (bool) $request->user?->isActive()
            && ! $request->user?->trashed();
    }

    protected static function authorizeHandle(PasswordResetRequest $request): void
    {
        abort_unless(static::canApprove(), 403);
        abort_if(static::isProtectedTarget($request), 403);
    }

    /**
     * ปิดคำขอแบบมีเงื่อนไข (เฉพาะที่ยัง open) คืน false ถ้าถูกดำเนินการไปแล้ว
     */
    protected static function claim(PasswordResetRequest $request): bool
    {
        return PasswordResetRequest::query()
            ->whereKey($request->id)
            ->where('status', PasswordResetRequest::STATUS_OPEN)
            ->update([
                'status' => PasswordResetRequest::STATUS_DONE,
                'handled_by' => auth()->id(),
                'handled_at' => now(),
            ]) > 0;
    }

    /**
     * อีเมลต้องไม่ซ้ำกับบัญชีอื่นที่ยังไม่ถูกลบ
     */
    protected static function uniqueEmailRule(int $userId): Unique
    {
        return Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at');
    }

    protected static function activeTarget(PasswordResetRequest $request): ?User
    {
        $user = User::query()->find($request->user_id);

        return $user && $user->isActive() ? $user : null;
    }

    protected static function notifyAlreadyHandled(): void
    {
        Notification::make()->title('รายการนี้ถูกดำเนินการไปแล้ว')->warning()->send();
    }

    /**
     * ส่งลิงก์ตั้งรหัสผ่านใหม่ (ลิงก์เดียวกับหน้าลืมรหัสผ่าน ส่งทันที ไม่เข้าคิว)
     * - ผู้ใช้มีอีเมลแล้ว: ส่งไปที่อีเมลเดิมเสมอ ไม่สนใจ $email ที่ส่งเข้ามา
     * - ผู้ใช้ยังไม่มีอีเมล (บัญชีเก่า): บันทึก $email ให้ผู้ใช้แล้วส่งลิงก์ ถ้าส่งไม่สำเร็จจะย้อนกลับทั้งหมด
     * คืน true เมื่อส่งสำเร็จ
     */
    public static function sendLinkFor(PasswordResetRequest $request, ?string $email = null): bool
    {
        static::authorizeHandle($request);

        $user = static::activeTarget($request);

        if (! $user) {
            Notification::make()->title('บัญชีนี้ไม่ได้อยู่ในสถานะใช้งาน')->warning()->send();

            return false;
        }

        $newEmail = null;

        if (blank($user->email)) {
            $newEmail = trim((string) $email);

            // ตรวจซ้ำฝั่งเซิร์ฟเวอร์ (ฟอร์มตรวจแล้ว แต่กันการเรียกตรง)
            $validator = Validator::make(
                ['email' => $newEmail],
                ['email' => ['required', 'email', 'max:255', static::uniqueEmailRule($user->id)]],
            );

            if ($validator->fails()) {
                Notification::make()->title('อีเมลไม่ถูกต้องหรือถูกใช้แล้ว')->warning()->send();

                return false;
            }
        }

        $target = $newEmail ?? $user->email;

        try {
            $sent = DB::transaction(function () use ($request, $user, $newEmail, $target) {
                if (! static::claim($request)) {
                    return false;
                }

                if ($newEmail !== null) {
                    $user->email = $newEmail;
                    $user->save();
                }

                $status = PasswordResetLink::send($target);

                if ($status !== Password::RESET_LINK_SENT) {
                    // โยนออกไปเพื่อย้อนกลับทั้งคำขอและอีเมลที่เพิ่งบันทึก
                    throw new RuntimeException('Password broker returned [' . $status . ']');
                }

                return true;
            });
        } catch (Throwable $e) {
            Log::error('Failed to send password reset link', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('ส่งลิงก์ไม่สำเร็จ')
                ->body('ยังไม่ได้บันทึกการเปลี่ยนแปลง กรุณาลองใหม่อีกครั้ง')
                ->warning()
                ->send();

            return false;
        }

        if (! $sent) {
            static::notifyAlreadyHandled();

            return false;
        }

        Notification::make()
            ->title('ส่งลิงก์ตั้งรหัสผ่านไปที่ ' . $target . ' แล้ว')
            ->success()
            ->send();

        return true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user.rank', 'handler']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.username')
                    ->label('ผู้ขอ')
                    ->description(fn (PasswordResetRequest $record): string => trim(
                        ($record->user?->rank?->name ?? '') . ' ' . ($record->user?->firstname ?? '') . ' ' . ($record->user?->lastname ?? '')
                    ))
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('อีเมล')
                    ->placeholder('ไม่มีอีเมล'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('ขอเมื่อ')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('สถานะ')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === PasswordResetRequest::STATUS_OPEN ? 'รอดำเนินการ' : 'ดำเนินการแล้ว')
                    ->color(fn (string $state): string => $state === PasswordResetRequest::STATUS_OPEN ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('handler.username')
                    ->label('ผู้ดำเนินการ')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('handled_at')
                    ->label('ดำเนินการเมื่อ')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('สถานะ')
                    ->options([
                        PasswordResetRequest::STATUS_OPEN => 'รอดำเนินการ',
                        PasswordResetRequest::STATUS_DONE => 'ดำเนินการแล้ว',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('sendLink')
                    ->label('ส่งลิงก์ตั้งรหัสผ่านใหม่')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalHeading('ส่งลิงก์ตั้งรหัสผ่านใหม่')
                    ->modalDescription(fn (PasswordResetRequest $record): string => filled($record->user?->email)
                        ? 'ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปที่ ' . $record->user->email
                        : 'บัญชีนี้ยังไม่มีอีเมล กรอกอีเมลของผู้ใช้เพื่อส่งลิงก์ตั้งรหัสผ่านใหม่')
                    ->form(fn (PasswordResetRequest $record): array => filled($record->user?->email) ? [] : [
                        Forms\Components\TextInput::make('email')
                            ->label('อีเมลของผู้ใช้')
                            ->helperText('บัญชีนี้ยังไม่มีอีเมล กรอกอีเมลของผู้ใช้เพื่อส่งลิงก์ตั้งรหัสผ่านใหม่')
                            ->required()
                            ->email()
                            ->maxLength(255)
                            ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('email', trim((string) $state)))
                            ->rules([static::uniqueEmailRule($record->user_id)]),
                    ])
                    ->visible(fn (PasswordResetRequest $record) => static::canHandle($record))
                    ->action(fn (PasswordResetRequest $record, array $data) => static::sendLinkFor($record, $data['email'] ?? null)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPasswordResetRequests::route('/'),
        ];
    }
}

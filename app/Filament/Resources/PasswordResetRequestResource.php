<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Filament\Resources\PasswordResetRequestResource\Pages;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Support\PasswordResetLink;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    protected static function release(PasswordResetRequest $request): void
    {
        PasswordResetRequest::query()
            ->whereKey($request->id)
            ->update([
                'status' => PasswordResetRequest::STATUS_OPEN,
                'handled_by' => null,
                'handled_at' => null,
            ]);
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
     * ส่งลิงก์ตั้งรหัสผ่านทางอีเมล (ลิงก์เดียวกับหน้าลืมรหัสผ่าน ส่งทันที ไม่เข้าคิว)
     */
    public static function sendLinkFor(PasswordResetRequest $request): void
    {
        static::authorizeHandle($request);

        $user = static::activeTarget($request);

        if (! $user || blank($user->email)) {
            Notification::make()->title('บัญชีนี้ไม่มีอีเมล หรือไม่ได้อยู่ในสถานะใช้งาน')->warning()->send();

            return;
        }

        if (! static::claim($request)) {
            static::notifyAlreadyHandled();

            return;
        }

        try {
            $status = PasswordResetLink::send($user->email);
        } catch (Throwable $e) {
            Log::error('Failed to send password reset link', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $status = null;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            // ส่งไม่สำเร็จ เปิดคำขอไว้ตามเดิมเพื่อให้ลองใหม่ได้
            static::release($request);

            Notification::make()
                ->title('ส่งลิงก์ไม่สำเร็จ')
                ->body($status === Password::RESET_THROTTLED ? 'เพิ่งส่งลิงก์ไปเมื่อสักครู่ กรุณารอสักครู่แล้วลองใหม่' : 'กรุณาลองใหม่อีกครั้ง')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('ส่งลิงก์ตั้งรหัสผ่านไปที่ ' . $user->email . ' แล้ว')
            ->success()
            ->send();
    }

    /**
     * ตั้งรหัสผ่านชั่วคราว แสดงให้ผู้ดูแลเห็นครั้งเดียว และบังคับให้ผู้ใช้เปลี่ยนเมื่อเข้าสู่ระบบ
     */
    public static function setTemporaryPasswordFor(PasswordResetRequest $request): void
    {
        static::authorizeHandle($request);

        $user = static::activeTarget($request);

        if (! $user) {
            Notification::make()->title('บัญชีนี้ไม่ได้อยู่ในสถานะใช้งาน')->warning()->send();

            return;
        }

        $plain = Str::password(12);

        $changed = DB::transaction(function () use ($request, $user, $plain) {
            if (! static::claim($request)) {
                return false;
            }

            // cast "hashed" ของโมเดลแฮชรหัสผ่านให้ ไม่เก็บข้อความธรรมดา
            $user->forceFill([
                'password' => $plain,
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            // ออกจากระบบทุกอุปกรณ์ที่ล็อกอินค้างไว้
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

            return true;
        });

        if (! $changed) {
            static::notifyAlreadyHandled();

            return;
        }

        Notification::make()
            ->title('ตั้งรหัสผ่านชั่วคราวให้ ' . $user->username . ' แล้ว')
            ->body('รหัสผ่านชั่วคราว: <code>' . e($plain) . '</code><br>แสดงเพียงครั้งเดียว กรุณาจดและแจ้งผู้ใช้โดยตรง ผู้ใช้จะต้องเปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบ')
            ->success()
            ->persistent()
            ->send();
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
                    ->label('ส่งลิงก์ทางอีเมล')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalHeading('ส่งลิงก์ตั้งรหัสผ่านทางอีเมล')
                    ->visible(fn (PasswordResetRequest $record) => static::canHandle($record) && filled($record->user?->email))
                    ->action(fn (PasswordResetRequest $record) => static::sendLinkFor($record)),
                Tables\Actions\Action::make('setTemporaryPassword')
                    ->label('ตั้งรหัสผ่านชั่วคราว')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('ตั้งรหัสผ่านชั่วคราว')
                    ->modalDescription('ระบบจะสร้างรหัสผ่านใหม่และแสดงให้เห็นเพียงครั้งเดียว ผู้ใช้จะต้องเปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบ')
                    ->visible(fn (PasswordResetRequest $record) => static::canHandle($record))
                    ->action(fn (PasswordResetRequest $record) => static::setTemporaryPasswordFor($record)),
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

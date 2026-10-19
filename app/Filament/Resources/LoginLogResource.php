<?php

namespace App\Filament\Resources;

use App\Enums\RoleEnum;
use App\Filament\Resources\LoginLogResource\Pages;
use App\Models\LoginLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoginLogResource extends Resource
{
    protected static ?string $model = LoginLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'ประวัติการเข้าใช้งาน';

    protected static ?string $modelLabel = 'ประวัติการเข้าใช้งาน';

    protected static ?string $pluralModelLabel = 'ประวัติการเข้าใช้งาน';

    // Gate::before ให้สิทธิ์ super-admin อยู่แล้ว แต่ต้องกันคนอื่นเอง
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(RoleEnum::SUPERADMIN);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user.rank'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('เวลา')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->label('ผู้ใช้')
                    ->description(fn (LoginLog $record): ?string => $record->user
                        ? trim(($record->user->rank?->name ?? '') . ' ' . $record->user->firstname . ' ' . $record->user->lastname)
                        : null)
                    ->searchable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('เหตุการณ์')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        LoginLog::EVENT_LOGIN => 'เข้าระบบ',
                        LoginLog::EVENT_LOGOUT => 'ออกจากระบบ',
                        default => 'เข้าไม่สำเร็จ',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        LoginLog::EVENT_LOGIN => 'success',
                        LoginLog::EVENT_LOGOUT => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label('อุปกรณ์/เบราว์เซอร์')
                    ->limit(50)
                    ->tooltip(fn (LoginLog $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('เหตุการณ์')
                    ->options([
                        LoginLog::EVENT_LOGIN => 'เข้าระบบ',
                        LoginLog::EVENT_LOGOUT => 'ออกจากระบบ',
                        LoginLog::EVENT_FAILED => 'เข้าไม่สำเร็จ',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('ตั้งแต่'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('ถึง'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoginLogs::route('/'),
        ];
    }
}

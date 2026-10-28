<?php

namespace App\Filament\Resources;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use App\Filament\Resources\LoginLogResource\Pages\ListLoginLogs;
use App\Enums\RoleEnum;
use App\Filament\Resources\LoginLogResource\Pages;
use App\Models\LoginLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LoginLogResource extends Resource
{
    protected static ?string $model = LoginLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static ?int $navigationSort = 3;

    protected static string | \UnitEnum | null $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'ประวัติการเข้าใช้งาน';

    protected static ?string $modelLabel = 'ประวัติการเข้าใช้งาน';

    protected static ?string $pluralModelLabel = 'ประวัติการเข้าใช้งาน';

    // Gate::before ให้สิทธิ์ super-admin อยู่แล้ว แต่ต้องกันคนอื่นเอง
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole(RoleEnum::SUPERADMIN);
    }

    // บันทึกเป็นหลักฐาน ห้ามสร้าง แก้ หรือลบ (Filament 4 ถามสิทธิ์ผ่านเมธอดเหล่านี้ ไม่ใช่ can*() และ Gate::before ให้ super-admin ผ่านทุกอย่าง)
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user.rank'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('เวลา')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('username')
                    ->label('ผู้ใช้')
                    ->description(fn (LoginLog $record): ?string => $record->user
                        ? trim(($record->user->rank?->name ?? '') . ' ' . $record->user->firstname . ' ' . $record->user->lastname)
                        : null)
                    ->searchable(),
                TextColumn::make('event')
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
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable(),
                TextColumn::make('user_agent')
                    ->label('อุปกรณ์/เบราว์เซอร์')
                    ->limit(50)
                    ->tooltip(fn (LoginLog $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('เหตุการณ์')
                    ->options([
                        LoginLog::EVENT_LOGIN => 'เข้าระบบ',
                        LoginLog::EVENT_LOGOUT => 'ออกจากระบบ',
                        LoginLog::EVENT_FAILED => 'เข้าไม่สำเร็จ',
                    ]),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('ตั้งแต่'),
                        DatePicker::make('until')->label('ถึง'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoginLogs::route('/'),
        ];
    }
}

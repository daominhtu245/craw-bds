<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Người dùng';

    protected static ?string $modelLabel = 'người dùng';

    protected static ?string $pluralModelLabel = 'người dùng';

    protected static ?int $navigationSort = 10;

    /** Admins cannot delete themselves. */
    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->isAdmin() && $record->getKey() !== auth()->id();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->label('Tên')->required()->maxLength(255),
                TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                Select::make('role')->label('Vai trò')->options(UserRole::class)->required()->default(UserRole::Sales->value),
                TextInput::make('telegram_username')->label('Telegram username')->maxLength(64)->prefix('@'),
                TextInput::make('password')->label('Mật khẩu')->password()->revealable()
                    ->minLength(10)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText('Để trống khi sửa nếu không đổi mật khẩu.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('role')->label('Vai trò')->badge(),
                TextColumn::make('telegram_username')->label('Telegram')->placeholder('—'),
                TextColumn::make('created_at')->label('Tạo lúc')->date('d/m/Y'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}

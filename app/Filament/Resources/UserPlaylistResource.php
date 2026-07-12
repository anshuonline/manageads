<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserPlaylistResource\Pages;
use App\Filament\Resources\UserPlaylistResource\RelationManagers;
use App\Models\UserPlaylist;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserPlaylistResource extends Resource
{
    protected static ?string $model = UserPlaylist::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('playlist_id')
                    ->maxLength(50),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Forms\Components\TextInput::make('playlist_name')
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_public'),
                Forms\Components\TextInput::make('songs'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('playlist_id'),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('playlist_name'),
                Tables\Columns\IconColumn::make('is_public')
                    ->boolean(),
                Tables\Columns\TextColumn::make('songs'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListUserPlaylists::route('/'),
            'create' => Pages\CreateUserPlaylist::route('/create'),
            'edit' => Pages\EditUserPlaylist::route('/{record}/edit'),
        ];
    }    
}

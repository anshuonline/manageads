<?php

namespace App\Filament\Resources\UserPlaylistResource\Pages;

use App\Filament\Resources\UserPlaylistResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUserPlaylist extends EditRecord
{
    protected static string $resource = UserPlaylistResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\UserPlaylistResource\Pages;

use App\Filament\Resources\UserPlaylistResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUserPlaylists extends ListRecords
{
    protected static string $resource = UserPlaylistResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

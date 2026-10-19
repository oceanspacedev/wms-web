<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        $user = $this->getRecord();

        if ($user instanceof User && filled($user->whatsapp_number)) {
            SendUserAccountActivatedWhatsAppJob::dispatch((int) $user->id)->afterCommit();
        }
    }
}

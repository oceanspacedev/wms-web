<?php

namespace App\Filament\Pages\Auth;

use App\Support\MediaFileName;
use Apriansyahrs\MekayaTheme\Auth\MekayaEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditProfile extends MekayaEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('avatar_url')
                    ->label('Foto Profil')
                    ->avatar()
                    ->alignCenter()
                    ->directory('avatars')
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file, Get $get): string => MediaFileName::make($file, auth()->user()?->username ?: $get('name'), 'avatar'))
                    ->imageEditor()
                    ->circleCropper()
                    ->maxSize(2048),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
            ]);
    }
}

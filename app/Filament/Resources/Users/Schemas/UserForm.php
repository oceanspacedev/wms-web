<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\ShipmentOptions;
use App\Support\WhatsAppNumber;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['lg' => 3])
            ->components([
                Group::make([
                    Section::make('Informasi Pengguna')
                        ->description('Data profil dan kredensial login pengguna.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Nama Lengkap')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('username')
                                ->label('Username')
                                ->maxLength(50)
                                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::lower(trim($state)) : null)
                                ->rules([
                                    fn (TextInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                                        if (blank($value)) {
                                            return;
                                        }

                                        $username = Str::lower(trim((string) $value));

                                        if (preg_match('/^[a-z0-9_-]{3,50}$/', $username) !== 1) {
                                            $fail('Username harus 3-50 karakter: huruf, angka, strip, atau garis bawah.');

                                            return;
                                        }

                                        $record = $component->getRecord();
                                        $taken = User::query()
                                            ->where('username', $username)
                                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                            ->exists();

                                        if ($taken) {
                                            $fail('Username sudah dipakai.');
                                        }
                                    },
                                ]),
                            TextInput::make('email')
                                ->label('Email Address')
                                ->email()
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255),
                            TextInput::make('whatsapp_number')
                                ->label('Nomor WhatsApp')
                                ->tel()
                                ->maxLength(30)
                                ->placeholder('081234567890')
                                ->dehydrateStateUsing(function (?string $state): ?string {
                                    if (blank($state)) {
                                        return null;
                                    }

                                    return WhatsAppNumber::normalize($state);
                                })
                                ->rules([
                                    fn (TextInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                                        if (blank($value)) {
                                            return;
                                        }

                                        $number = WhatsAppNumber::normalize((string) $value);

                                        if (! WhatsAppNumber::isValid($number)) {
                                            $fail('Nomor WhatsApp tidak valid.');

                                            return;
                                        }

                                        $record = $component->getRecord();
                                        $taken = User::query()
                                            ->where('whatsapp_number', $number)
                                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                            ->exists();

                                        if ($taken) {
                                            $fail('Nomor WhatsApp sudah dipakai.');
                                        }
                                    },
                                ]),
                            TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->nullable()
                                ->autocomplete('new-password')
                                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Kosongkan jika tidak ingin mengubah password.' : null)
                                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->maxLength(255)
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ])
                    ->columnSpan(['lg' => 2]),

                Group::make([
                    Section::make('Foto Profil')
                        ->schema([
                            FileUpload::make('avatar_url')
                                ->hiddenLabel()
                                ->avatar()
                                ->alignCenter()
                                ->directory('avatars')
                                ->disk('public')
                                ->visibility('public')
                                ->imageEditor()
                                ->circleCropper()
                                ->maxSize(2048),
                        ]),

                    Section::make('Peran & Akses')
                        ->description('Pilih peran hak akses untuk pengguna ini.')
                        ->schema([
                            Select::make('roles')
                                ->hiddenLabel()
                                ->relationship('roles', 'name')
                                ->multiple()
                                ->preload()
                                ->searchable(),

                            TextInput::make('kode_gudang_arsip')
                                ->label('Kode Gudang Arsip')
                                ->placeholder('GDG-BDG-01')
                                ->maxLength(50)
                                ->helperText('Untuk akun petugas gudang (role "gudang" + "panel_user"). Menentukan PO di SCM Arsip yang bisa dikirimi Memo/DO.'),

                            Select::make('cabang')
                                ->label('Cabang Data Pengiriman')
                                ->placeholder('Semua cabang')
                                ->options(fn (): array => ShipmentOptions::cabang())
                                ->searchable()
                                ->helperText('Untuk admin cabang: hanya melihat & mengisi Data Pengiriman cabang ini. Kosongkan untuk admin pusat.'),
                        ]),
                ])
                    ->columnSpan(['lg' => 1]),
            ]);
    }
}

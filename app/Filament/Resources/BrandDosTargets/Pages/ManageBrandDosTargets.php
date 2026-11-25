<?php

namespace App\Filament\Resources\BrandDosTargets\Pages;

use App\Filament\Resources\BrandDosTargets\BrandDosTargetResource;
use App\Models\BrandDosTarget;
use App\Services\BrandDosTargetExcelService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageBrandDosTargets extends ManageRecords
{
    protected static string $resource = BrandDosTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadExcel')
                ->label('Download Excel')
                ->color('gray')
                ->action(fn (): StreamedResponse => app(BrandDosTargetExcelService::class)->download()),

            Action::make('importExcel')
                ->label('Import Excel')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('create', BrandDosTarget::class) ?? false)
                ->modalHeading('Import Master Target DOS')
                ->modalDescription('Upload file Excel (.xlsx) berisi kolom COMPANY, BRAND, dan TARGET DOS (atau TARGET DOS BY BRAND).')
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel('Import')
                ->schema([
                    FileUpload::make('file')
                        ->label('File Excel (.xlsx)')
                        ->disk('local')
                        ->directory('brand-dos-imports')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/octet-stream',
                            'application/zip',
                        ])
                        ->maxSize(10240)
                        ->required(),

                    Toggle::make('replace_existing')
                        ->label('Ganti seluruh data master sebelumnya')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path($data['file']);

                    try {
                        $count = app(BrandDosTargetExcelService::class)->import(
                            $path,
                            (bool) ($data['replace_existing'] ?? false),
                        );
                    } catch (Exception $exception) {
                        Notification::make()
                            ->title('File tidak bisa diimport')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }

                    Notification::make()
                        ->title($count.' data Target DOS berhasil diimport')
                        ->success()
                        ->send();
                }),

            CreateAction::make()
                ->modalWidth(Width::Medium),
        ];
    }
}

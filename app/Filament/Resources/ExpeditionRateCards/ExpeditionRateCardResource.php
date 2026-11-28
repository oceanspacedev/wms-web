<?php

namespace App\Filament\Resources\ExpeditionRateCards;

use App\Filament\Resources\ExpeditionRateCards\Pages\ManageExpeditionRateCards;
use App\Models\ExpeditionRateCard;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ExpeditionRateCardResource extends Resource
{
    protected static ?string $model = ExpeditionRateCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static \UnitEnum|string|null $navigationGroup = 'Ekspedisi';

    protected static ?string $navigationLabel = 'Tarif Kontrak';

    protected static ?string $modelLabel = 'Tarif Kontrak PKS';

    protected static ?string $pluralModelLabel = 'Tarif Kontrak PKS';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('expedition_id')
                    ->relationship('expedition', 'name')
                    ->required(),
                TextInput::make('origin_depo')
                    ->required(),
                TextInput::make('destination_city')
                    ->required(),
                TextInput::make('destination_district'),
                TextInput::make('province')
                    ->label('Provinsi'),
                TextInput::make('service_type')
                    ->required()
                    ->default('DARAT'),
                TextInput::make('rate_per_kg')
                    ->required()
                    ->numeric(),
                TextInput::make('min_kg')
                    ->label('Min KG')
                    ->required()
                    ->numeric()
                    ->default(1),
                Select::make('pricing_type')
                    ->label('Jenis Tarif')
                    ->options(ExpeditionRateCard::PRICING_LABELS)
                    ->default(ExpeditionRateCard::PRICING_PER_KG)
                    ->required(),
                TextInput::make('insurance_rate_percent')
                    ->required()
                    ->numeric()
                    ->default(0.2),
                TextInput::make('sla_days'),
                TextInput::make('notes')
                    ->label('Catatan / Keterangan'),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expedition.name')
                    ->label('Ekspedisi')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('origin_depo')
                    ->label('Asal (Depo)')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('destination_city')
                    ->label('Kota Tujuan')
                    ->sortable()
                    ->searchable(['destination_city', 'destination_district', 'province'])
                    ->description(fn (ExpeditionRateCard $record): ?string => $record->province ?: null)
                    ->tooltip(fn (ExpeditionRateCard $record): string => implode(', ', array_filter([$record->destination_city, $record->destination_district, $record->province])) ?: $record->destination_city),
                TextColumn::make('destination_district')
                    ->label('Kecamatan')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('service_type')
                    ->label('Layanan')
                    ->badge()
                    ->colors([
                        'primary' => 'DARAT',
                        'warning' => 'UDARA',
                        'info' => 'LAUT',
                    ])
                    ->sortable()
                    ->searchable(),
                TextColumn::make('rate_per_kg')
                    ->label('Tarif / KG')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('min_kg')
                    ->label('Min KG')
                    ->formatStateUsing(fn ($state, ExpeditionRateCard $record): string => $record->isFlatPricing()
                        ? '–'
                        : rtrim(rtrim(number_format((float) $state, 1, ',', '.'), '0'), ',').' KG')
                    ->sortable(),
                TextColumn::make('pricing_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ExpeditionRateCard::PRICING_LABELS[$state] ?? 'Per KG')
                    ->color(fn (?string $state): string => match ($state) {
                        ExpeditionRateCard::PRICING_FLAT, ExpeditionRateCard::PRICING_PER_COLLY => 'warning',
                        ExpeditionRateCard::PRICING_KUBIKASI => 'info',
                        ExpeditionRateCard::PRICING_DOC => 'gray',
                        default => 'success',
                    })
                    ->sortable(),
                TextColumn::make('sla_days')
                    ->label('SLA')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state): string => $state ? $state.' hr' : '-')
                    ->searchable(),
                TextColumn::make('insurance_rate_percent')
                    ->label('Premi (%)')
                    ->suffix('%')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(30)
                    ->tooltip(fn (ExpeditionRateCard $record): ?string => $record->notes)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('expedition_id')
                    ->label('Ekspedisi')
                    ->relationship('expedition', 'name'),
                SelectFilter::make('origin_depo')
                    ->label('Asal (Depo)')
                    ->placeholder('Semua asal')
                    ->searchable()
                    ->options(fn (): array => Cache::remember(
                        'rate_cards:filter_origins:v'.ExpeditionRateCard::routeCacheVersion(),
                        3600,
                        fn (): array => ExpeditionRateCard::query()
                            ->whereNotNull('origin_depo')
                            ->where('origin_depo', '!=', '')
                            ->distinct()
                            ->orderBy('origin_depo')
                            ->pluck('origin_depo', 'origin_depo')
                            ->all(),
                    )),
                SelectFilter::make('destination_city')
                    ->label('Kota Tujuan')
                    ->placeholder('Semua kota tujuan')
                    ->searchable()
                    ->options(fn (): array => Cache::remember(
                        'rate_cards:filter_destinations:v'.ExpeditionRateCard::routeCacheVersion(),
                        3600,
                        fn (): array => ExpeditionRateCard::query()
                            ->whereNotNull('destination_city')
                            ->where('destination_city', '!=', '')
                            ->distinct()
                            ->orderBy('destination_city')
                            ->pluck('destination_city', 'destination_city')
                            ->all(),
                    )),
                SelectFilter::make('service_type')
                    ->label('Layanan')
                    ->options([
                        'DARAT' => 'DARAT',
                        'UDARA' => 'UDARA',
                        'LAUT' => 'LAUT',
                    ]),
                SelectFilter::make('pricing_type')
                    ->label('Jenis Tarif')
                    ->options(ExpeditionRateCard::PRICING_LABELS),
                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Aksi'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpeditionRateCards::route('/'),
        ];
    }
}

<?php

namespace App\Filament\Resources\BrandDosTargets;

use App\Filament\Resources\BrandDosTargets\Pages\ManageBrandDosTargets;
use App\Models\BrandDosTarget;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Master Target DOS per brand sesuai tabel Monitoring DOS.
 */
class BrandDosTargetResource extends Resource
{
    protected static ?string $model = BrandDosTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static \UnitEnum|string|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Master Target DOS';

    protected static ?string $modelLabel = 'Target DOS';

    protected static ?string $pluralModelLabel = 'Master Target DOS';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('company')
                    ->label('Company')
                    ->placeholder('Contoh: PT. MSI, CV. TOP, PT. SMI')
                    ->datalist(fn (): array => array_values(array_unique(array_merge(
                        ['PT. MSI', 'CV. TOP', 'PT. SMI'],
                        BrandDosTarget::query()->whereNotNull('company')->distinct()->orderBy('company')->pluck('company')->all(),
                    ))))
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                    ->maxLength(50),

                TextInput::make('label')
                    ->label('Brand')
                    ->placeholder('Contoh: TECNO ONLINE, TECNO OFFLINE, MOTOROLA')
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                    ->unique(
                        table: BrandDosTarget::class,
                        column: 'label',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, callable $get): Unique => $rule->where(
                            'company',
                            filled($get('company')) ? strtoupper(trim((string) $get('company'))) : null,
                        ),
                    )
                    ->required()
                    ->maxLength(120),

                TextInput::make('target_dos')
                    ->label('Target DOS')
                    ->numeric()
                    ->integer()
                    ->minValue(7)
                    ->maxValue(180)
                    ->suffix('hari')
                    ->helperText('Kosongkan jika ikut target default.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('company')
                    ->label('Company')
                    ->placeholder('–')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('label')
                    ->label('Brand')
                    ->searchable(['label', 'brand'])
                    ->sortable(),

                TextColumn::make('target_dos')
                    ->label('Target DOS')
                    ->formatStateUsing(fn ($state): string => $state !== null ? $state.' hari' : '–')
                    ->placeholder('–')
                    ->alignment(Alignment::End)
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrandDosTargets::route('/'),
        ];
    }
}

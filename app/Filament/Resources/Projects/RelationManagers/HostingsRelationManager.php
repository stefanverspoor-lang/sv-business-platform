<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Shared\Currencies;
use App\Models\ProjectHosting;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class HostingsRelationManager extends RelationManager
{
    protected static string $relationship = 'hostings';

    public const SECRETS = ['username' => 'Gebruikersnaam', 'password' => 'Wachtwoord', 'ftp_details' => 'FTP/SSH-gegevens', 'database_details' => 'Databasegegevens'];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Hosting');
    }

    public static function getModelLabel(): string
    {
        return __('hosting');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    protected static function secrets(): array
    {
        return array_map(fn ($l) => __($l), self::SECRETS);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('provider')->label(__('Hostingbedrijf'))->required()->maxLength(255)->placeholder('Hostinger'),
            TextInput::make('plan')->label(__('Pakket'))->maxLength(255),
            TextInput::make('panel_url')->label(__('Beheerpaneel'))->url()->maxLength(500),
            TextInput::make('monthly_cost')->label(__('Kosten per maand'))->numeric()->minValue(0),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default('EUR')->required(),
            DatePicker::make('renewal_date')->label(__('Verlengdatum')),
            Toggle::make('paid_by_client')->label(__('Klant betaalt zelf de hosting'))->default(true)->inline(false),
            Textarea::make('notes')->label(__('Notities'))->rows(2)->columnSpan(2),
            Section::make(__('Inloggegevens (versleuteld)'))->columnSpanFull()->columns(2)
                ->schema(CredentialFields::inputs(static::secrets(), ['ftp_details', 'database_details'])),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label(__('Hostingbedrijf'))->description(fn (ProjectHosting $r) => $r->plan),
                TextColumn::make('panel_url')->label(__('Beheerpaneel'))->url(fn (ProjectHosting $r) => $r->panel_url, true)->limit(40)->placeholder('-'),
                TextColumn::make('renewal_date')->label(__('Verlengdatum'))->date('d-m-Y')->placeholder('-'),
                TextColumn::make('monthly_cost')->label(__('Per maand'))
                    ->formatStateUsing(fn (ProjectHosting $r) => $r->monthly_cost !== null ? $r->currency.' '.number_format((float) $r->monthly_cost, 2, ',', '.') : null)->placeholder('-'),
                IconColumn::make('paid_by_client')->label(__('Klant betaalt'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                CredentialFields::showAction(static::secrets())->visible(fn (ProjectHosting $r) => CredentialFields::hasAny($r, array_keys(self::SECRETS)) && (auth()->user()->can('credentials.view') || auth()->user()->can('projects.manage'))),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

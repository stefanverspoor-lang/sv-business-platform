<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Shared\Currencies;
use App\Models\ProjectDomain;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    public const SECRETS = ['username' => 'Gebruikersnaam', 'password' => 'Wachtwoord', 'notes' => 'Overige gegevens'];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Domeinnamen');
    }

    public static function getModelLabel(): string
    {
        return __('domeinnaam');
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
            TextInput::make('domain')->label(__('Domeinnaam'))->required()->maxLength(255)->placeholder('voorbeeld.nl'),
            TextInput::make('registrar')->label(__('Geregistreerd bij'))->maxLength(255)->placeholder('Hostinger, TransIP, ...'),
            TextInput::make('registrant')->label(__('Op naam van'))->maxLength(255),
            DatePicker::make('registered_at')->label(__('Registratiedatum')),
            DatePicker::make('expires_at')->label(__('Verloopt op'))->helperText(__('Je krijgt 30 dagen vooraf een melding.')),
            Toggle::make('auto_renew')->label(__('Automatisch verlengen'))->default(true)->inline(false),
            TextInput::make('yearly_cost')->label(__('Kosten per jaar'))->numeric()->minValue(0),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default('EUR')->required(),
            TextInput::make('login_url')->label(__('Inlogpagina'))->url()->maxLength(500),
            Section::make(__('Inloggegevens (versleuteld)'))->columnSpanFull()->columns(2)
                ->schema(CredentialFields::inputs(static::secrets(), ['notes'])),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('expires_at')
            ->columns([
                TextColumn::make('domain')->label(__('Domeinnaam'))->url(fn (ProjectDomain $r) => 'https://'.$r->domain, true),
                TextColumn::make('registrar')->label(__('Geregistreerd bij'))->placeholder('-'),
                TextColumn::make('expires_at')->label(__('Verloopt op'))->date('d-m-Y')->placeholder('-')
                    ->color(fn (ProjectDomain $r) => $r->expires_at && $r->expires_at->lt(now()->addDays(30)) ? 'danger' : null),
                IconColumn::make('auto_renew')->label(__('Auto'))->boolean(),
                TextColumn::make('yearly_cost')->label(__('Per jaar'))
                    ->formatStateUsing(fn (ProjectDomain $r) => $r->yearly_cost !== null ? $r->currency.' '.number_format((float) $r->yearly_cost, 2, ',', '.') : null)->placeholder('-'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                CredentialFields::showAction(static::secrets())->visible(fn (ProjectDomain $r) => CredentialFields::hasAny($r, array_keys(self::SECRETS)) && (auth()->user()->can('credentials.view') || auth()->user()->can('projects.manage'))),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

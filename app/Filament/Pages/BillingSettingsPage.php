<?php

namespace App\Filament\Pages;

use App\Support\Billing\BillingSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Factuurinstellingen per bedrijf: nummering, bank, teksten, logo. */
class BillingSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 84;

    protected static ?string $slug = 'factuurinstellingen';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Factuurinstellingen');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Beheer');
    }

    public function getTitle(): string
    {
        return __('Factuurinstellingen');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('billing.settings') ?? false;
    }

    public function mount(): void
    {
        $values = BillingSettings::all();
        $values['logo_path'] = $values['logo_path'] ? [$values['logo_path']] : [];
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $placeholders = __('Te gebruiken: {nummer} {bedrag} {vervaldatum} {iban} {rekeninghouder} {naam} {afzender} {bedrijf} {geldig_tot}. In het Engels: {number} {amount} {due_date} {iban} {account_holder} {name} {sender} {company} {valid_until}.');

        return $schema->statePath('data')->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make(__('Algemeen'))->columns(2)->schema([
                    TextEntry::make('company_info')->label(__('Bedrijfsgegevens op de factuur'))->columnSpanFull()
                        ->state(function () {
                            $c = Filament::getTenant();

                            return collect([$c->legal_name ?: $c->name, $c->address_line, trim($c->postal_code.' '.$c->city), $c->country,
                                $c->registration_number ? __('KvK/reg.nr.').' '.$c->registration_number : null,
                                $c->vat_number ? __('Btw-nr.').' '.$c->vat_number : null])->filter()->implode(' · ');
                        })
                        ->helperText(__('Naam, adres, inschrijf- en btw-nummer wijzig je bij Bedrijfsgegevens (menu rechtsboven bij het bedrijf). Basisvaluta en taal ook.')),
                    FileUpload::make('logo_path')->label(__('Logo op de factuur'))->image()->disk('local')->visibility('private')
                        ->directory(fn () => 'companies/'.Filament::getTenant()->slug.'/branding')->maxSize(2048)
                        ->helperText(__('PNG of JPG, liefst liggend.')),
                    TextInput::make('payment_terms_days')->label(__('Standaard betaaltermijn (dagen)'))->numeric()->minValue(0)->required(),
                    TextInput::make('quote_valid_days')->label(__('Offertes geldig (dagen)'))->numeric()->minValue(1)->required(),
                ]),
                Tab::make(__('Nummering'))->columns(3)->schema([
                    TextInput::make('invoice_prefix')->label(__('Voorvoegsel facturen'))->maxLength(20)->required()
                        ->helperText(fn ($state) => __('Voorbeeld: :ex', ['ex' => $state.now()->year.'-0001'])),
                    TextInput::make('credit_prefix')->label(__('Voorvoegsel creditnota\'s'))->maxLength(20)->required(),
                    TextInput::make('quote_prefix')->label(__('Voorvoegsel offertes'))->maxLength(20)->required(),
                    TextInput::make('number_digits')->label(__('Aantal cijfers volgnummer'))->numeric()->minValue(3)->maxValue(8)->required(),
                    TextEntry::make('numbering_note')->hiddenLabel()->columnSpan(2)
                        ->state(__('Nummers lopen per jaar door en worden pas uitgegeven als een factuur definitief wordt, zodat er geen gaten ontstaan.')),
                ]),
                Tab::make(__('Bank'))->columns(2)->schema([
                    TextInput::make('account_holder')->label(__('Rekeninghouder'))->maxLength(255),
                    TextInput::make('bank_name')->label(__('Bank'))->maxLength(255),
                    TextInput::make('iban')->label(__('IBAN / rekeningnummer'))->maxLength(50),
                    TextInput::make('bic')->label(__('BIC / SWIFT'))->maxLength(20),
                ]),
                Tab::make(__('Teksten'))->columns(2)->schema([
                    Textarea::make('payment_text_nl')->label(__('Betaaltekst (NL)'))->rows(3)->helperText($placeholders),
                    Textarea::make('payment_text_en')->label(__('Betaaltekst (EN)'))->rows(3),
                    Textarea::make('footer_nl')->label(__('Extra voettekst (NL)'))->rows(2),
                    Textarea::make('footer_en')->label(__('Extra voettekst (EN)'))->rows(2),
                ]),
                Tab::make(__('E-mail'))->columns(2)->schema([
                    TextInput::make('invoice_email_subject_nl')->label(__('Onderwerp factuur (NL)')),
                    TextInput::make('invoice_email_subject_en')->label(__('Onderwerp factuur (EN)')),
                    Textarea::make('invoice_email_body_nl')->label(__('Tekst factuur (NL)'))->rows(7),
                    Textarea::make('invoice_email_body_en')->label(__('Tekst factuur (EN)'))->rows(7),
                    TextInput::make('quote_email_subject_nl')->label(__('Onderwerp offerte (NL)')),
                    TextInput::make('quote_email_subject_en')->label(__('Onderwerp offerte (EN)')),
                    Textarea::make('quote_email_body_nl')->label(__('Tekst offerte (NL)'))->rows(7)->helperText($placeholders),
                    Textarea::make('quote_email_body_en')->label(__('Tekst offerte (EN)'))->rows(7),
                ]),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Opslaan'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $logo = $data['logo_path'] ?? null;
        $data['logo_path'] = is_array($logo) ? (collect($logo)->first() ?: null) : ($logo ?: null);
        BillingSettings::save($data);
        Notification::make()->success()->title(__('Opgeslagen'))->send();
    }
}

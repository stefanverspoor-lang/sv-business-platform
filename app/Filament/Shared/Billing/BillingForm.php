<?php

namespace App\Filament\Shared\Billing;

use App\Filament\Shared\Currencies;
use App\Models\BillingLine;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ExchangeRate;
use App\Models\VatRate;
use App\Support\Billing\BillingSettings;
use App\Support\Billing\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/** Formulieronderdelen voor offertes, facturen en terugkerende facturen. */
class BillingForm
{
    public static function clientFields(): array
    {
        return [
            Select::make('client_id')->label(__('Klant'))
                ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('contact_id', null)),
            Select::make('contact_id')->label(__('Ter attentie van'))
                ->options(fn (Get $get) => $get('client_id')
                    ? Contact::where('client_id', $get('client_id'))->get()->mapWithKeys(fn ($c) => [$c->id => trim($c->first_name.' '.$c->last_name)])
                    : [])
                ->placeholder(__('Geen')),
        ];
    }

    public static function languageAndCurrency(bool $withRate = true): array
    {
        $base = fn () => BillingSettings::baseCurrency();

        return array_values(array_filter([
            Select::make('locale')->label(__('Taal van het document'))->options(['nl' => 'Nederlands', 'en' => 'English'])->default('nl')->required(),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default($base)->required()->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($base) {
                    $set('exchange_rate', ExchangeRate::lookup((string) $state, $base(), $get('issue_date')) ?? null);
                }),
            $withRate ? TextInput::make('exchange_rate')
                ->label(fn () => __('Wisselkoers naar :base', ['base' => $base()]))
                ->numeric()->minValue(0.00000001)
                ->visible(fn (Get $get) => $get('currency') && $get('currency') !== $base())
                ->required(fn (Get $get) => $get('currency') && $get('currency') !== $base())
                ->helperText(fn (Get $get) => __('1 :cur = ... :base. Wordt ingevuld uit Wisselkoersen als die bekend is.', ['cur' => $get('currency'), 'base' => $base()])) : null,
        ]));
    }

    public static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->label(__('Regels'))
            ->relationship()
            ->orderColumn('sort')
            ->reorderableWithButtons()
            ->defaultItems(1)
            ->addActionLabel(__('Regel toevoegen'))
            ->columns(12)
            ->schema([
                Textarea::make('description')->label(__('Omschrijving'))->rows(2)->required()->columnSpan(['default' => 12, 'lg' => 5]),
                TextInput::make('quantity')->label(__('Aantal'))->numeric()->default(1)->required()->live(onBlur: true)->columnSpan(['default' => 4, 'lg' => 1]),
                Select::make('unit')->label(__('Eenheid'))->options(BillingLine::units())->placeholder('-')->columnSpan(['default' => 4, 'lg' => 1]),
                TextInput::make('unit_price')->label(__('Prijs'))->numeric()->default(0)->required()->live(onBlur: true)->columnSpan(['default' => 4, 'lg' => 2]),
                TextInput::make('discount_percent')->label(__('Korting %'))->numeric()->minValue(0)->maxValue(100)->default(0)->live(onBlur: true)->columnSpan(['default' => 4, 'lg' => 1]),
                Select::make('vat_rate_id')->label(__('Btw'))
                    ->options(fn () => VatRate::active()->pluck('name', 'id'))
                    ->default(fn () => VatRate::defaultId())->required()->live()->columnSpan(['default' => 8, 'lg' => 2]),
            ])
            ->itemLabel(fn (array $state) => isset($state['quantity'], $state['unit_price'])
                ? \Illuminate\Support\Str::limit(strtok((string) ($state['description'] ?? ''), "\n"), 60).' · '.number_format(BillingLine::calculate($state['quantity'], $state['unit_price'], $state['discount_percent'] ?? 0), 2, ',', '.')
                : null)
            ->collapsible()
            ->columnSpanFull();
    }

    /** Lopend totaal onder de regels, berekend uit wat er nu in het formulier staat. */
    public static function liveTotals(): TextEntry
    {
        return TextEntry::make('live_totals')
            ->hiddenLabel()
            ->state(function (Get $get) {
                $lines = collect($get('lines') ?? []);
                $rates = VatRate::query()->get()->keyBy('id');
                $subtotal = 0.0;
                $vat = [];
                foreach ($lines as $line) {
                    $amount = BillingLine::calculate($line['quantity'] ?? 0, $line['unit_price'] ?? 0, $line['discount_percent'] ?? 0);
                    $subtotal += $amount;
                    $rate = $rates[$line['vat_rate_id'] ?? 0] ?? null;
                    $key = $rate?->name ?? __('Btw');
                    $vat[$key] = ['rate' => (float) ($rate?->rate ?? 0), 'base' => ($vat[$key]['base'] ?? 0) + $amount];
                }
                $currency = $get('currency') ?: 'EUR';
                $vatTotal = collect($vat)->sum(fn ($v) => round($v['base'] * $v['rate'] / 100, 2));
                $rows = '<tr><td>'.e(__('Subtotaal')).'</td><td style="text-align:right">'.e(Money::format($subtotal, $currency)).'</td></tr>';
                foreach ($vat as $name => $v) {
                    $rows .= '<tr><td>'.e($name).'</td><td style="text-align:right">'.e(Money::format(round($v['base'] * $v['rate'] / 100, 2), $currency)).'</td></tr>';
                }
                $rows .= '<tr><td style="font-weight:700;padding-top:6px">'.e(__('Totaal')).'</td><td style="text-align:right;font-weight:700;padding-top:6px">'.e(Money::format($subtotal + $vatTotal, $currency)).'</td></tr>';

                return new HtmlString('<table style="margin-left:auto;min-width:280px;font-size:14px">'.$rows.'</table>');
            })
            ->columnSpanFull();
    }

    public static function textFields(): Section
    {
        return Section::make(__('Teksten'))
            ->collapsible()->collapsed()
            ->schema([
                TextInput::make('subject')->label(__('Onderwerp'))->maxLength(255),
                Textarea::make('intro')->label(__('Inleiding (boven de regels)'))->rows(2),
                Textarea::make('notes')->label(__('Opmerkingen (onder de totalen)'))->rows(2),
            ]);
    }
}

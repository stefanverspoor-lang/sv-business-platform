<?php

namespace App\Filament\Shared\Billing;

use App\Enums\DocumentCategory;
use App\Enums\PaymentMethod;
use App\Filament\Shared\Currencies;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\VatRate;
use App\Support\Billing\BillingSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Bedragvelden voor uitgaven en inkomsten. */
class FinanceForm
{
    public static function amountFields(): array
    {
        $base = fn () => BillingSettings::baseCurrency();
        $recalc = function (Get $get, Set $set) {
            $rate = VatRate::find($get('vat_helper'));
            if ($rate && $get('amount_excl') !== null && $get('amount_excl') !== '') {
                $set('vat_amount', round((float) $get('amount_excl') * (float) $rate->rate / 100, 2));
            }
        };

        return [
            TextInput::make('amount_excl')->label(__('Bedrag excl. btw'))->numeric()->required()->live(onBlur: true)->afterStateUpdated($recalc),
            Select::make('vat_helper')->label(__('Btw-tarief'))->options(fn () => VatRate::active()->pluck('name', 'id'))
                ->dehydrated(false)->live()->afterStateUpdated($recalc)->placeholder(__('Zelf invullen')),
            TextInput::make('vat_amount')->label(__('Btw-bedrag'))->numeric()->default(0)->required(),
            Select::make('currency')->label(__('Valuta'))->options(Currencies::options())->default($base)->required()->live()
                ->afterStateUpdated(fn ($state, Get $get, Set $set) => $set('exchange_rate', ExchangeRate::lookup((string) $state, $base(), $get('date')) ?? 1)),
            TextInput::make('exchange_rate')->label(fn () => __('Wisselkoers naar :base', ['base' => $base()]))->numeric()->default(1)->required()
                ->visible(fn (Get $get) => $get('currency') && $get('currency') !== $base()),
        ];
    }

    public static function attachment(): FileUpload
    {
        return FileUpload::make('attachment')
            ->label(__('Bon of factuur (PDF of foto)'))
            ->disk('local')->visibility('private')
            ->directory(fn () => 'companies/'.Filament::getTenant()->slug.'/documents/'.now()->format('Y/m'))
            ->storeFileNamesIn('attachment_name')
            ->acceptedFileTypes(Document::ALLOWED_MIME_TYPES)
            ->maxSize(Document::MAX_SIZE_KB)
            ->dehydrated(false)
            ->columnSpanFull();
    }

    public static function paymentMethod(): Select
    {
        return Select::make('payment_method')->label(__('Betaalwijze'))->options(PaymentMethod::class)->placeholder('-');
    }

    public static function dateField(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)->label($label);
    }

    /** Bijlage uit het formulier als document bij de uitgave/inkomst bewaren. */
    public static function storeAttachment(Model $record, array $raw): void
    {
        $path = is_array($raw['attachment'] ?? null) ? collect($raw['attachment'])->first() : ($raw['attachment'] ?? null);
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return;
        }
        $name = is_array($raw['attachment_name'] ?? null) ? collect($raw['attachment_name'])->first() : ($raw['attachment_name'] ?? basename($path));

        $record->documents()->create([
            'disk' => 'local', 'path' => $path, 'original_name' => $name ?: basename($path),
            'title' => $name ?: basename($path), 'category' => DocumentCategory::Invoice,
            'mime_type' => Storage::disk('local')->mimeType($path) ?: null, 'size' => Storage::disk('local')->size($path),
            'uploaded_by' => auth()->id(),
        ]);
    }
}

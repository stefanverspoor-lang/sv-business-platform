@php
    /** @var \App\Models\Invoice|\App\Models\Quote $document */
    $title = match ($kind) {
        'quote' => __('Offerte'),
        'credit_note' => __('Creditnota'),
        default => __('Factuur'),
    };
    $notes = collect($vat)->pluck('note')->filter()->unique();
    $addr = fn ($a) => collect([
        $a['address_line'] ?? null,
        trim(($a['postal_code'] ?? '').' '.($a['city'] ?? '')),
        $a['country'] ?? null,
    ])->filter()->all();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $document->number }}</title>
<style>
    @page { margin: 22mm 18mm 26mm 18mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1c1917; line-height: 1.45; }
    h1 { font-size: 20pt; margin: 0 0 2mm 0; color: {{ $color }}; font-weight: bold; letter-spacing: .3px; }
    .muted { color: #6b6460; }
    .small { font-size: 8pt; }
    table { border-collapse: collapse; width: 100%; }
    .head td { vertical-align: top; }
    .logo { max-height: 22mm; max-width: 60mm; }
    .company { text-align: right; font-size: 8.5pt; }
    .company strong { font-size: 10pt; }
    .meta td { padding: 1mm 0; vertical-align: top; }
    .meta .label { color: #6b6460; width: 40mm; padding-right: 3mm; }
    .lines { margin-top: 8mm; }
    .lines th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .4px; color: #6b6460;
        border-bottom: 1.2pt solid {{ $color }}; padding: 2mm 1.5mm; }
    .lines td { padding: 2.2mm 1.5mm; border-bottom: .5pt solid #e7e3df; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .lines th.num { text-align: right; }
    .totals { margin-top: 4mm; width: 46%; margin-left: 54%; }
    .totals td { padding: 1.4mm 1.5mm; }
    .totals .grand td { border-top: 1.2pt solid {{ $color }}; font-weight: bold; font-size: 11pt; padding-top: 2.5mm; }
    .box { margin-top: 8mm; padding: 3.5mm 4mm; background: #f6f4f1; border-left: 2pt solid {{ $color }}; }
    .footer { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7.5pt; color: #6b6460; text-align: center;
        border-top: .5pt solid #e7e3df; padding-top: 2mm; }
    .currency { font-size: 8pt; color: #6b6460; margin-top: 2mm; }
</style>
</head>
<body>

<div class="footer">
    {{ collect([$company['name'] ?? null, $company['registration_number'] ? __('KvK/reg.nr.').' '.$company['registration_number'] : null,
        $company['vat_number'] ? __('Btw-nr.').' '.$company['vat_number'] : null,
        $company['iban'] ? 'IBAN '.$company['iban'].($company['bic'] ? ' · BIC '.$company['bic'] : '') : null])->filter()->implode(' · ') }}
    @if ($footer)<br>{{ $footer }}@endif
</div>

<table class="head">
    <tr>
        <td style="width: 50%">
            @if ($logo)
                <img src="{{ $logo }}" class="logo" alt="">
            @else
                <div style="font-size: 15pt; font-weight: bold; color: {{ $color }}">{{ $company['trade_name'] ?? $company['name'] }}</div>
            @endif
        </td>
        <td class="company">
            <strong>{{ $company['name'] }}</strong><br>
            @foreach ($addr($company) as $line){{ $line }}<br>@endforeach
            @if ($company['email']){{ $company['email'] }}<br>@endif
            @if ($company['phone']){{ $company['phone'] }}<br>@endif
            @if ($company['website']){{ $company['website'] }}@endif
        </td>
    </tr>
</table>

<table style="margin-top: 12mm">
    <tr>
        <td style="width: 55%; vertical-align: top">
            <div class="muted small">{{ $kind === 'quote' ? __('Offerte voor') : __('Factuur aan') }}</div>
            <strong>{{ $client['name'] }}</strong><br>
            @if ($client['contact']){{ __('t.a.v.') }} {{ $client['contact'] }}<br>@endif
            @foreach ($addr($client) as $line){{ $line }}<br>@endforeach
            @if ($client['vat_number'])<span class="small">{{ __('Btw-nr.') }} {{ $client['vat_number'] }}</span>@endif
        </td>
        <td style="vertical-align: top">
            <h1>{{ $title }}</h1>
            <table class="meta">
                <tr><td class="label">{{ $kind === 'quote' ? __('Offertenummer') : ($kind === 'credit_note' ? __('Creditnotanummer') : __('Factuurnummer')) }}</td>
                    <td><strong>{{ $document->number ?? __('Concept') }}</strong></td></tr>
                <tr><td class="label">{{ __('Datum') }}</td><td>{{ $date($document->issue_date) }}</td></tr>
                @if ($kind === 'quote')
                    <tr><td class="label">{{ __('Geldig tot') }}</td><td>{{ $date($document->valid_until) }}</td></tr>
                @elseif ($kind === 'invoice')
                    <tr><td class="label">{{ __('Vervaldatum') }}</td><td>{{ $date($document->due_date) }}</td></tr>
                @endif
                @if ($kind === 'credit_note' && $document->creditedInvoice)
                    <tr><td class="label">{{ __('Correctie op') }}</td><td>{{ $document->creditedInvoice->number }}</td></tr>
                @endif
                @if (! empty($document->reference))
                    <tr><td class="label">{{ __('Uw referentie') }}</td><td>{{ $document->reference }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($document->subject)
    <p style="margin-top: 8mm; font-weight: bold">{{ $document->subject }}</p>
@endif
@if ($document->intro)
    <p>{!! nl2br(e($document->intro)) !!}</p>
@endif

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('Omschrijving') }}</th>
            <th class="num">{{ __('Aantal') }}</th>
            <th class="num">{{ __('Prijs') }}</th>
            <th class="num">{{ __('Btw') }}</th>
            <th class="num">{{ __('Bedrag') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{!! nl2br(e($line->description)) !!}
                    @if ((float) $line->discount_percent > 0)<br><span class="small muted">{{ __('Korting') }} {{ $num($line->discount_percent) }}%</span>@endif
                </td>
                <td class="num">{{ $num($line->quantity) }}{{ $line->unit ? ' '.__($line->unit) : '' }}</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $num($line->vat_rate) }}%</td>
                <td class="num">{{ $money($line->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('Subtotaal') }}</td><td class="num">{{ $money($document->subtotal) }}</td></tr>
    @foreach ($vat as $row)
        <tr><td>{{ $row['label'] ? __($row['label']) : __('Btw') }}{{ str_contains((string) $row['label'], '%') || (float) $row['rate'] == 0.0 ? '' : ' '.$num($row['rate']).'%' }} <span class="small muted">{{ __('over') }} {{ $money($row['base']) }}</span></td>
            <td class="num">{{ $money($row['vat']) }}</td></tr>
    @endforeach
    <tr class="grand"><td>{{ __('Totaal') }}</td><td class="num">{{ $money($document->total) }}</td></tr>
</table>
<div class="currency" style="text-align: right">{{ __('Alle bedragen in :currency.', ['currency' => $document->currency]) }}</div>

@foreach ($notes as $note)
    <p class="small" style="margin-top: 4mm"><strong>{{ $note }}</strong></p>
@endforeach

@if ($paymentText && $company['iban'])
    <div class="box">{{ $paymentText }}</div>
@endif

@if ($document->notes)
    <p style="margin-top: 6mm">{!! nl2br(e($document->notes)) !!}</p>
@endif

</body>
</html>

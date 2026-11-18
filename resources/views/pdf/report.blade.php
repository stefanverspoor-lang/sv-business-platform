<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 16mm 14mm 18mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #1c1917; line-height: 1.4; }
    h1 { font-size: 15pt; margin: 0 0 1mm 0; color: {{ $color }}; }
    h2 { font-size: 10pt; margin: 7mm 0 2mm 0; color: {{ $color }}; }
    .muted { color: #6b6460; }
    table { border-collapse: collapse; width: 100%; }
    .summary td { padding: 1.2mm 3mm 1.2mm 0; vertical-align: top; }
    .summary .label { color: #6b6460; width: 60mm; }
    .data th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .3px; color: #6b6460;
        border-bottom: 1pt solid {{ $color }}; padding: 1.5mm 1.5mm; }
    .data td { padding: 1.4mm 1.5mm; border-bottom: .4pt solid #e7e3df; }
    .data td.n, .data th.n { text-align: right; white-space: nowrap; }
    .footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7pt; color: #6b6460; }
</style>
</head>
<body>
<div class="footer">{{ $company }} · {{ __('Gemaakt op :date', ['date' => $generated]) }}</div>

<h1>{{ $title }}</h1>
<div class="muted">{{ $company }} · {{ $period }}</div>

<table class="summary" style="margin-top: 5mm">
    @foreach (array_chunk($summary, 2, true) as $pair)
        <tr>
            @foreach ($pair as $label => $value)
                <td class="label">{{ $label }}</td><td><strong>{{ $value }}</strong></td>
            @endforeach
        </tr>
    @endforeach
</table>

@foreach ($tables as $table)
    <h2>{{ $table['title'] }}</h2>
    @if (empty($table['rows']))
        <p class="muted">{{ __('Geen gegevens in deze periode.') }}</p>
    @else
        @php $numeric = fn ($v) => preg_match('/^[\-A-Z]{0,4}\s?-?[\d.,\s\/%]+$/u', trim((string) $v)) === 1; @endphp
        <table class="data">
            <thead><tr>
                @foreach ($table['headers'] as $i => $h)
                    <th class="{{ $i > 0 ? 'n' : '' }}">{{ $h }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($table['rows'] as $row)
                    <tr>
                        @foreach (array_values($row) as $i => $v)
                            <td class="{{ $i > 0 && $numeric($v) ? 'n' : '' }}">{{ $v }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach
</body>
</html>

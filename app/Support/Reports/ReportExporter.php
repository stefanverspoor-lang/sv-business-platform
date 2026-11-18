<?php

namespace App\Support\Reports;

use App\Support\Imports\Spreadsheet;
use App\Support\Tenancy\TenantManager;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Zet een rapport (samenvatting + tabellen) om naar Excel, CSV of PDF.
 * Werkt met beide rapportvormen: 'tables' => [...] en de oudere
 * perUser/perOutcome/perMonth-indeling van de belrapportages.
 */
class ReportExporter
{
    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'pdf' => 'PDF (.pdf)'];

    /** @return list<array{title: string, headers: array, rows: array}> */
    public static function tables(array $report): array
    {
        if (isset($report['tables'])) {
            return array_values($report['tables']);
        }

        $titles = array_merge(['perUser' => __('Per medewerker'), 'perOutcome' => __('Per gespreksresultaat'), 'perMonth' => __('Per maand')], $report['titles'] ?? []);
        $out = [];
        foreach ($titles as $key => $title) {
            if (isset($report[$key])) {
                $out[] = ['title' => $title, 'headers' => $report[$key]['headers'], 'rows' => $report[$key]['rows']];
            }
        }

        return $out;
    }

    /** Platte rijen voor Excel/CSV. */
    public static function rows(array $report, string $title, string $period): array
    {
        $rows = [[$title], [$period], []];
        foreach ($report['summary'] as $label => $value) {
            $rows[] = [$label, (string) $value];
        }
        foreach (self::tables($report) as $table) {
            $rows[] = [];
            $rows[] = [$table['title']];
            $rows[] = $table['headers'];
            foreach ($table['rows'] as $row) {
                $rows[] = array_map(fn ($v) => (string) $v, array_values($row));
            }
        }

        return $rows;
    }

    public static function write(string $format, string $path, array $report, string $title, string $period): void
    {
        @mkdir(dirname($path), 0775, true);

        match ($format) {
            'csv' => self::csv($path, self::rows($report, $title, $period)),
            'pdf' => file_put_contents($path, self::pdf($report, $title, $period)),
            default => (function () use ($path, $report, $title, $period) {
                $rows = self::rows($report, $title, $period);
                Spreadsheet::write($path, array_shift($rows), $rows);
            })(),
        };
    }

    /** CSV met puntkomma en BOM, zodat Excel het in het Nederlands goed opent. */
    protected static function csv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn ($v) => self::safeCell((string) $v), $row), ';', '"', '');
        }
        fclose($fh);
    }

    /** Voorkomt formule-injectie als iemand de CSV in Excel opent. */
    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric(str_replace([',', '.', ' '], '', ltrim($value, '-')))
            ? "'".$value : $value;
    }

    public static function pdf(array $report, string $title, string $period): string
    {
        $company = app(TenantManager::class)->current();
        $html = view('pdf.report', [
            'title' => $title,
            'period' => $period,
            'summary' => $report['summary'],
            'tables' => self::tables($report),
            'company' => $company?->name,
            'generated' => now($company?->timezone ?: config('app.timezone'))->format('d-m-Y H:i'),
            'color' => $company?->primary_color ?: '#1F4D45',
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [storage_path('app')]);
        foreach (['tempDir' => 'app/tmp', 'fontDir' => 'app/fonts', 'fontCache' => 'app/fonts'] as $key => $dir) {
            if (! is_dir(storage_path($dir))) {
                mkdir(storage_path($dir), 0775, true);
            }
            $options->set($key, storage_path($dir));
        }

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $wide = collect(self::tables($report))->max(fn ($t) => count($t['headers'])) > 6;
        $pdf->setPaper('A4', $wide ? 'landscape' : 'portrait');
        $pdf->render();

        return $pdf->output();
    }
}

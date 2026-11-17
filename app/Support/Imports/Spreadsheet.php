<?php

namespace App\Support\Imports;

use DateTimeInterface;
use Generator;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Lezen en schrijven van Excel (.xlsx) en CSV. CSV-bestanden uit Excel
 * gebruiken in Nederland vaak ; als scheidingsteken en Windows-tekenset:
 * dat wordt automatisch herkend.
 */
class Spreadsheet
{
    public const MAX_ROWS = 20000;

    /** @return Generator<int, array<int, mixed>> regelnummer => waarden */
    public static function rows(string $path, ?string $originalName = null): Generator
    {
        $ext = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx' => self::xlsxRows($path),
            'csv', 'txt' => self::csvRows($path),
            default => throw new RuntimeException(__('Alleen Excel (.xlsx) en CSV-bestanden worden ondersteund.')),
        };
    }

    protected static function xlsxRows(string $path): Generator
    {
        $reader = new XlsxReader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    yield $line => $row->toArray();
                }
                break; // alleen het eerste werkblad
            }
        } finally {
            $reader->close();
        }
    }

    protected static function csvRows(string $path): Generator
    {
        $content = file_get_contents($path);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $line = 0;
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            yield $line => $row;
        }
        fclose($handle);
    }

    /** Schrijft een .xlsx-bestand. Eerste rij is vet (kolomnamen). */
    public static function write(string $path, array $headers, iterable $rows): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers, (new Style)->setFontBold()));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $v instanceof \BackedEnum ? ($v instanceof \Filament\Support\Contracts\HasLabel ? $v->getLabel() : $v->value) : ($v instanceof DateTimeInterface ? $v->format('Y-m-d') : $v), array_values($row))));
        }
        $writer->close();
    }

    public static function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii(trim((string) $value))));
    }

    /** "1.234,56", "1,234.56", "€ 99", 99.5 → 1234.56 enz. */
    public static function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $s = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if ($s === '' || $s === '-') {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    /** Excel-datum, 31-12-2026, 31/12/2026 of 2026-12-31. */
    public static function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }
        if (is_numeric($value) && $value > 20000 && $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value);
        }

        $s = trim((string) $value);
        foreach (['d-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd-m-y', 'd/m/y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $s);
            $errors = \DateTime::getLastErrors();
            if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return Carbon::instance($date);
            }
        }

        return null;
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value; // 612345678.0 → "612345678"
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}

<?php

namespace App\Support\Imports;

use App\Models\DataImport;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Basis voor imports: kolommen herkennen op naam (NL en EN), regel voor
 * regel verwerken en per regel bijhouden wat er mis was.
 */
abstract class Importer
{
    /** @var array<int, string> */
    protected array $messages = [];

    protected int $imported = 0;

    protected int $duplicates = 0;

    protected int $failed = 0;

    protected int $total = 0;

    /** @return array<string, array<int, string>> veld => mogelijke kolomnamen */
    abstract public static function columns(): array;

    /** @return array<int, string> velden die verplicht zijn */
    abstract public static function required(): array;

    /** Kolomnamen en een voorbeeldregel voor het downloadbare template. */
    abstract public static function template(): array;

    abstract protected function importRow(array $data, int $line, DataImport $import): void;

    abstract protected function type(): string;

    protected function before(DataImport $import): void {}

    protected function after(DataImport $import): void {}

    public function run(string $storedPath, string $originalName, array $attributes = []): DataImport
    {
        $import = DataImport::create($attributes + [
            'type' => $this->type(),
            'path' => $storedPath,
            'original_name' => $originalName,
            'status' => 'processing',
            'user_id' => auth()->id(),
        ]);

        $map = null;
        $this->before($import);

        foreach (Spreadsheet::rows(Storage::disk('local')->path($storedPath), $originalName) as $line => $row) {
            if ($map === null) {
                $map = $this->mapHeaders($row);
                continue;
            }
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue; // lege regel
            }
            if (++$this->total > Spreadsheet::MAX_ROWS) {
                $this->messages[] = __('Gestopt na :max regels. Splits het bestand op.', ['max' => Spreadsheet::MAX_ROWS]);
                break;
            }

            $data = ['extra' => []];
            foreach ($row as $index => $value) {
                if (isset($map[$index])) {
                    $data[$map[$index]] = $value;
                } elseif (($header = $this->headers[$index] ?? null) && $value !== null && $value !== '') {
                    $data['extra'][$header] = Spreadsheet::text($value);
                }
            }

            $missing = array_filter(static::required(), fn ($field) => Spreadsheet::text($data[$field] ?? null) === null);
            if ($missing) {
                $this->fail($line, __('ontbreekt: :fields', ['fields' => implode(', ', array_map(fn ($f) => $this->fieldLabel($f), $missing))]));

                continue;
            }

            $this->importRow($data, $line, $import);
        }

        if ($map === null) {
            throw new RuntimeException(__('Het bestand is leeg.'));
        }

        $this->after($import);

        $import->update([
            'status' => 'done',
            'total_rows' => $this->total,
            'imported' => $this->imported,
            'duplicates' => $this->duplicates,
            'failed' => $this->failed,
            'messages' => array_slice($this->messages, 0, 500),
        ]);

        return $import;
    }

    /** @var array<int, string> */
    protected array $headers = [];

    /** @return array<int, string> kolomindex => veld */
    protected function mapHeaders(array $row): array
    {
        $lookup = [];
        foreach (static::columns() as $field => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[Spreadsheet::normalizeHeader($alias)] = $field;
            }
        }

        $map = [];
        foreach ($row as $index => $header) {
            $this->headers[$index] = trim((string) $header);
            $key = Spreadsheet::normalizeHeader($header);
            if (isset($lookup[$key]) && ! in_array($lookup[$key], $map, true)) {
                $map[$index] = $lookup[$key];
            }
        }

        $missing = array_diff(static::required(), $map);
        if ($missing) {
            throw new RuntimeException(__('Kolommen niet gevonden: :fields. Gebruik het voorbeeldbestand.', [
                'fields' => implode(', ', array_map(fn ($f) => $this->fieldLabel($f), $missing)),
            ]));
        }

        return $map;
    }

    protected function fieldLabel(string $field): string
    {
        return static::columns()[$field][0] ?? $field;
    }

    protected function fail(int $line, string $message): void
    {
        $this->failed++;
        $this->messages[] = __('Regel :line: :message', ['line' => $line, 'message' => $message]);
    }

    protected function duplicate(int $line, string $message): void
    {
        $this->duplicates++;
        $this->messages[] = __('Regel :line: :message', ['line' => $line, 'message' => $message]);
    }

    protected function warn(int $line, string $message): void
    {
        $this->messages[] = __('Regel :line: :message', ['line' => $line, 'message' => $message]);
    }
}

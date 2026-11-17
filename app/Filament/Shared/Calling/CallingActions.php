<?php

namespace App\Filament\Shared\Calling;

use App\Filament\Resources\Clients\ClientResource;
use App\Support\Calling\PhoneNumber;
use App\Support\Imports\Importer;
use App\Support\Imports\Spreadsheet;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use App\Models\DataImport;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/** Knoppen die telemarketing en pre-incasso delen. */
class CallingActions
{
    /** BEL NU: gewone telefoonlink; de computer kiest het belprogramma (CloudTalk Desktop). */
    public static function callNow(?Closure $record = null): Action
    {
        return Action::make('callNow')
            ->label(__('BEL NU'))
            ->icon(Heroicon::Phone)
            ->color('success')
            ->url(fn (?Model $r = null) => PhoneNumber::telLink(($record ? $record() : $r)?->phone_normalized))
            ->visible(fn (?Model $r = null) => filled(($record ? $record() : $r)?->phone_normalized));
    }

    /** Direct in CloudTalk Desktop openen, ook als dat niet het standaard belprogramma is. */
    public static function callViaCloudTalk(?Closure $record = null): Action
    {
        return Action::make('callViaCloudTalk')
            ->label(__('Bellen via CloudTalk'))
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->color('gray')
            ->url(fn (?Model $r = null) => PhoneNumber::cloudTalkLink(($record ? $record() : $r)?->phone_normalized))
            ->visible(fn (?Model $r = null) => filled(($record ? $record() : $r)?->phone_normalized));
    }

    public static function assignBulk(string $permission): BulkAction
    {
        return BulkAction::make('assign')
            ->label(__('Toewijzen'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn () => auth()->user()->can($permission))
            ->schema([
                Select::make('assigned_to')->label(__('Toewijzen aan'))
                    ->options(fn () => ClientResource::companyUserOptions())
                    ->placeholder(__('Niemand (vrijgeven)')),
            ])
            ->action(function (Collection $records, array $data) {
                $records->each(fn (Model $r) => $r->forceFill(['assigned_to' => $data['assigned_to'] ?: null])->save());
                Notification::make()->success()->title(trans_choice(':count record toegewezen|:count records toegewezen', $records->count(), ['count' => $records->count()]))->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** @param  class-string<Importer>  $importer */
    public static function downloadTemplate(string $importer, string $filename): Action
    {
        return Action::make('template_'.Str::slug($filename))
            ->label(__('Voorbeeldbestand'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->action(function () use ($importer, $filename) {
                [$headers, $example] = $importer::template();
                $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
                Spreadsheet::write($path, $headers, [$example]);

                return response()->download($path, $filename.'.xlsx')->deleteFileAfterSend();
            });
    }

    /**
     * Upload van een Excel/CSV-bestand dat door een importer wordt verwerkt.
     *
     * @param  array<int, \Filament\Schemas\Components\Component>  $fields  extra velden
     * @param  Closure(array $data, string $path, string $originalName): DataImport  $run
     */
    public static function import(string $name, string $label, array $fields, Closure $run): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalSubmitActionLabel(__('Importeren'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('Excel- of CSV-bestand'))
                    ->disk('local')
                    ->directory(fn () => 'companies/'.Filament::getTenant()->slug.'/imports/'.now()->format('Y/m'))
                    ->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                    ])
                    ->maxSize(20480)
                    ->storeFileNamesIn('original_name')
                    ->required()
                    ->helperText(__('Gebruik het voorbeeldbestand. Kolommen met een andere naam worden als extra informatie bewaard.')),
                ...$fields,
            ])
            ->action(function (array $data) use ($run) {
                try {
                    $import = $run($data, $data['file'], $data['original_name'] ?? basename($data['file']));
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('Import mislukt'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                $body = __(':imported geïmporteerd, :duplicates dubbel overgeslagen, :failed met fouten (van :total regels).', [
                    'imported' => $import->imported, 'duplicates' => $import->duplicates,
                    'failed' => $import->failed, 'total' => $import->total_rows,
                ]);
                $messages = collect($import->messages ?? [])->take(8);
                if ($messages->isNotEmpty()) {
                    $body .= "\n\n".$messages->implode("\n").(count($import->messages) > 8 ? "\n…" : '');
                }

                Notification::make()
                    ->title(__('Import klaar'))
                    ->body(new \Illuminate\Support\HtmlString(nl2br(e($body))))
                    ->{$import->failed || $import->duplicates ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Export naar Excel.
     *
     * @param  Closure(): iterable  $rows
     */
    public static function exportResponse(string $filename, array $headers, iterable $rows)
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        Spreadsheet::write($path, $headers, $rows);

        return response()->download($path, $filename.'-'.now()->format('Ymd-Hi').'.xlsx')->deleteFileAfterSend();
    }
}

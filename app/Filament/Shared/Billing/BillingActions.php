<?php

namespace App\Filament\Shared\Billing;

use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Quote;
use App\Support\Billing\Billing;
use App\Support\Billing\BillingMailer;
use App\Support\Billing\PdfRenderer;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Knoppen die offertes en facturen delen. */
class BillingActions
{
    /** @param  Closure(): (Invoice|Quote)  $document */
    public static function pdf(Closure $document): Action
    {
        return Action::make('pdf')
            ->label(__('PDF'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->action(function () use ($document) {
                $doc = $document();
                $path = app(Billing::class)->pdfPath($doc);

                return Storage::disk('local')->download($path, app(PdfRenderer::class)->filename($doc));
            });
    }

    /** @param  Closure(): (Invoice|Quote)  $document */
    public static function send(Closure $document, ?Closure $after = null): Action
    {
        return Action::make('send')
            ->label(fn () => $document()->sent_at ? __('Opnieuw versturen') : __('Versturen'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => auth()->user()->can('invoices.manage') && auth()->user()->can('emails.send'))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Versturen'))
            ->modalDescription(fn () => $document() instanceof Invoice && $document()->isDraft()
                ? __('De factuur wordt eerst definitief gemaakt en krijgt een nummer. Daarna kan hij niet meer worden gewijzigd.')
                : __('De PDF gaat als bijlage mee.'))
            ->fillForm(fn () => app(BillingMailer::class)->defaults($document()) + [
                'mailbox_id' => Mailbox::where('is_active', true)->orderBy('id')->value('id'),
            ])
            ->schema([
                Select::make('mailbox_id')->label(__('Versturen vanaf'))
                    ->options(fn () => Mailbox::where('is_active', true)->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->name} <{$m->email}>"]))
                    ->required()
                    ->helperText(fn () => Mailbox::where('is_active', true)->exists() ? null : __('Voeg eerst een mailbox toe onder Beheer → Mailboxen.')),
                TagsInput::make('to')->label(__('Aan'))->nestedRecursiveRules(['email'])->required(),
                TagsInput::make('cc')->label(__('Cc'))->nestedRecursiveRules(['email']),
                TextInput::make('subject')->label(__('Onderwerp'))->required(),
                Textarea::make('body')->label(__('Bericht'))->rows(9)->required(),
            ])
            ->action(function (array $data, Action $action) use ($document, $after) {
                try {
                    app(BillingMailer::class)->send($document(), Mailbox::findOrFail($data['mailbox_id']), $data['to'], $data['subject'], $data['body'], $data['cc'] ?? []);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('Versturen mislukt'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
                Notification::make()->success()->title(__('Verstuurd'))->send();
                if ($after) {
                    $after();
                }
            });
    }
}

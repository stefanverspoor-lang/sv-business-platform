<?php

namespace App\Support\Billing;

use App\Enums\QuoteStatus;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Quote;
use App\Support\Mail\EmailSender;
use App\Support\Mail\MessageStore;
use App\Support\Tenancy\TenantManager;

/** Factuur of offerte versturen vanuit het CRM, met de PDF als bijlage. */
class BillingMailer
{
    public function __construct(
        protected Billing $billing,
        protected PdfRenderer $pdf,
        protected EmailSender $sender,
        protected MessageStore $store,
    ) {}

    /** Onderwerp en tekst uit de instellingen, ingevuld voor dit document. */
    public function defaults(Invoice|Quote $document): array
    {
        $locale = $document->locale ?: 'nl';
        $kind = $document instanceof Quote ? 'quote' : 'invoice';
        $settings = BillingSettings::all();
        $company = app(TenantManager::class)->current();
        $contact = $document->contact ?? $document->client?->primaryContact;
        $name = $contact?->first_name ?: ($document->client?->name ?? '');
        $money = Money::format($document->total, $document->currency, $locale);
        $date = fn ($d) => $d?->format($locale === 'en' ? 'j M Y' : 'd-m-Y') ?? '';
        $number = $document->number ?? ($locale === 'en' ? '{number}' : '{nummer}');

        $values = [
            'nummer' => $number, 'number' => $number, 'bedrag' => $money, 'amount' => $money,
            'vervaldatum' => $date($document->due_date ?? null), 'due_date' => $date($document->due_date ?? null),
            'geldig_tot' => $date($document->valid_until ?? null), 'valid_until' => $date($document->valid_until ?? null),
            'naam' => $name, 'name' => $name, 'afzender' => auth()->user()?->name, 'sender' => auth()->user()?->name,
            'bedrijf' => $company?->name, 'company' => $company?->name,
        ];

        return [
            'to' => array_values(array_filter([$contact?->email ?: ($document->client?->primaryContact?->email ?? $document->client?->email)])),
            'subject' => BillingSettings::fill((string) ($settings["{$kind}_email_subject_{$locale}"] ?? ''), $values),
            'body' => BillingSettings::fill((string) ($settings["{$kind}_email_body_{$locale}"] ?? ''), $values),
        ];
    }

    public function send(Invoice|Quote $document, Mailbox $mailbox, array $to, string $subject, string $body, array $cc = []): void
    {
        if ($document instanceof Invoice) {
            $document = $this->billing->finalize($document);
        } else {
            $document = $this->billing->finalizeQuote($document);
        }

        // Onderwerp/tekst kunnen nog "{nummer}" bevatten als het concept geen nummer had
        $subject = str_replace(['{nummer}', '{number}'], $document->number, $subject);
        $body = str_replace(['{nummer}', '{number}'], $document->number, $body);

        $path = $this->billing->pdfPath($document);
        $email = $this->sender->send($mailbox, $to, $subject, $body, $cc,
            attachmentPaths: [$this->pdf->filename($document) => $path],
            linkTo: array_filter([$document->client]));
        $this->store->link($email, $document);

        $document->forceFill(['sent_at' => now()])->saveQuietly();
        if ($document instanceof Quote && $document->status === QuoteStatus::Draft) {
            $document->forceFill(['status' => QuoteStatus::Sent])->saveQuietly();
        }
    }
}

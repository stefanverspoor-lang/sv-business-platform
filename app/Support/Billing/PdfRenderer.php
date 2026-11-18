<?php

namespace App\Support\Billing;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Tenancy\TenantManager;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/** Maakt de PDF van een factuur, creditnota of offerte (NL of EN). */
class PdfRenderer
{
    public function render(Invoice|Quote $document): string
    {
        $company = app(TenantManager::class)->current();
        $locale = $document->locale ?: 'nl';
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view('pdf.billing-document', $this->data($document, $company, $locale))->render();
        } finally {
            app()->setLocale($previous);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [storage_path('app')]);
        $options->set('tempDir', storage_path('app/tmp'));
        $options->set('fontDir', storage_path('app/fonts'));
        $options->set('fontCache', storage_path('app/fonts'));
        foreach (['app/tmp', 'app/fonts'] as $dir) {
            if (! is_dir(storage_path($dir))) {
                mkdir(storage_path($dir), 0775, true);
            }
        }

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    /** Rendert en bewaart de PDF; geeft het pad op de lokale schijf terug. */
    public function store(Invoice|Quote $document): string
    {
        $company = app(TenantManager::class)->current();
        $folder = $document instanceof Quote ? 'quotes' : 'invoices';
        $path = "companies/{$company->slug}/{$folder}/".($document->issue_date?->format('Y') ?? now()->format('Y')).'/'
            .str_replace(['/', '\\', ' '], '-', $document->number ?? 'concept-'.$document->id).'.pdf';

        Storage::disk('local')->put($path, $this->render($document));
        $document->forceFill(['pdf_path' => $path])->saveQuietly();

        return $path;
    }

    public function filename(Invoice|Quote $document): string
    {
        $kind = match (true) {
            $document instanceof Quote => $document->locale === 'en' ? 'Quote' : 'Offerte',
            $document->isCreditNote() => $document->locale === 'en' ? 'Credit-note' : 'Creditnota',
            default => $document->locale === 'en' ? 'Invoice' : 'Factuur',
        };

        return $kind.'-'.str_replace(['/', '\\', ' '], '-', $document->number ?? 'concept-'.$document->id).'.pdf';
    }

    protected function data(Invoice|Quote $document, ?Company $company, string $locale): array
    {
        $settings = BillingSettings::all();
        $lines = $document->lines()->with('vatRateModel')->get();
        $client = $document->client_snapshot ?: ClientSnapshot::make($document->client, $document->contact);
        $companyData = ($document instanceof Invoice ? $document->company_snapshot : null) ?: CompanySnapshot::make($company, $settings);

        $logo = null;
        if ($settings['logo_path'] && Storage::disk('local')->exists($settings['logo_path'])) {
            $mime = Storage::disk('local')->mimeType($settings['logo_path']) ?: 'image/png';
            $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($settings['logo_path']));
        }

        $money = fn ($amount) => Money::format($amount, $document->currency, $locale);
        $date = fn ($d) => $d?->format($locale === 'en' ? 'j M Y' : 'd-m-Y');

        $paymentText = null;
        if ($document instanceof Invoice && ! $document->isCreditNote() && (float) $document->total > 0) {
            $text = $settings['payment_text_'.$locale] ?? $settings['payment_text_nl'];
            $paymentText = BillingSettings::fill((string) $text, [
                'bedrag' => $money($document->outstanding() ?: $document->total), 'amount' => $money($document->outstanding() ?: $document->total),
                'vervaldatum' => $date($document->due_date), 'due_date' => $date($document->due_date),
                'iban' => $companyData['iban'] ?? '', 'rekeninghouder' => $companyData['account_holder'] ?? '', 'account_holder' => $companyData['account_holder'] ?? '',
                'nummer' => $document->number, 'number' => $document->number,
            ]);
        }

        return [
            'document' => $document,
            'kind' => $document instanceof Quote ? 'quote' : ($document->isCreditNote() ? 'credit_note' : 'invoice'),
            'lines' => $lines,
            'vat' => $document->vatBreakdown($lines),
            'client' => $client,
            'company' => $companyData,
            'logo' => $logo,
            'color' => $company?->primary_color ?: '#1F4D45',
            'money' => $money,
            'date' => $date,
            'num' => fn ($v) => Money::number($v, 3, $locale),
            'paymentText' => $paymentText,
            'footer' => $settings['footer_'.$locale] ?? null,
            'locale' => $locale,
        ];
    }
}

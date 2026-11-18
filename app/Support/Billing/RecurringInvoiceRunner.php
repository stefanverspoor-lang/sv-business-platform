<?php

namespace App\Support\Billing;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Mailbox;
use App\Models\RecurringInvoice;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Dagelijks (via crm:tick): facturen maken uit terugkerende facturen waarvan
 * de datum is bereikt. Concept, definitief of meteen gemaild, zoals ingesteld.
 */
class RecurringInvoiceRunner
{
    public function __construct(protected Billing $billing, protected BillingMailer $mailer) {}

    public function run(Company $company): int
    {
        $made = 0;
        $due = RecurringInvoice::query()->where('is_active', true)->whereDate('next_date', '<=', today())->limit(50)->get();

        foreach ($due as $recurring) {
            try {
                $invoice = $this->billing->generateFromRecurring($recurring);
                $made++;
                $status = __('concept klaargezet');

                if ($recurring->auto_finalize) {
                    $invoice = $this->billing->finalize($invoice);
                    $status = __('definitief gemaakt');

                    if ($recurring->auto_send && ($mailbox = Mailbox::where('is_active', true)->orderBy('id')->first())) {
                        $mail = $this->mailer->defaults($invoice);
                        if ($mail['to']) {
                            $this->mailer->send($invoice, $mailbox, $mail['to'], $mail['subject'], $mail['body']);
                            $status = __('verstuurd naar :to', ['to' => implode(', ', $mail['to'])]);
                        } else {
                            $status = __('niet verstuurd: geen e-mailadres bij de klant');
                        }
                    }
                }

                $this->notify($company, __('Terugkerende factuur ":name": :status', ['name' => $recurring->name, 'status' => $status]),
                    InvoiceResource::getUrl('view', ['record' => $invoice], tenant: $company));
            } catch (Throwable $e) {
                report($e);
                $this->notify($company, __('Terugkerende factuur ":name" mislukt: :error', ['name' => $recurring->name, 'error' => $e->getMessage()]), null, true);
            }
        }

        return $made;
    }

    protected function notify(Company $company, string $title, ?string $url, bool $error = false): void
    {
        foreach (User::where('is_active', true)->where('is_superadmin', true)->get() as $user) {
            $n = Notification::make()->title($title)->icon('heroicon-o-document-text');
            $error ? $n->danger() : $n->success();
            if ($url) {
                $n->actions([Action::make('open')->label(__('Openen'))->url($url)]);
            }
            $n->sendToDatabase($user);
        }
    }
}

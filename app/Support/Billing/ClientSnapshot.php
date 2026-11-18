<?php

namespace App\Support\Billing;

use App\Models\Client;
use App\Models\Contact;

/** Klantgegevens zoals ze op de factuur komen; vastgelegd bij definitief maken. */
class ClientSnapshot
{
    public static function make(?Client $client, ?Contact $contact = null): array
    {
        return [
            'name' => $client?->legal_name ?? $client?->name,
            'contact' => $contact ? trim($contact->first_name.' '.$contact->last_name) : null,
            'address_line' => $client?->address_line,
            'postal_code' => $client?->postal_code,
            'city' => $client?->city,
            'country' => $client?->country,
            'vat_number' => $client?->vat_number,
            'registration_number' => $client?->registration_number,
            'email' => $contact?->email ?: $client?->email,
        ];
    }
}

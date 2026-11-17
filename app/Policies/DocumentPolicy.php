<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Een document mag je zien als je het onderdeel mag zien waar het aan hangt. */
    public function view(User $user, Document $document): bool
    {
        $owner = $document->documentable;
        if ($owner instanceof \App\Models\Lead || $owner instanceof \App\Models\CollectionFile) {
            return $user->can('view', $owner);
        }
        $client = match (true) {
            $owner instanceof Client => $owner,
            $owner instanceof Contact, $owner instanceof Contract => $owner->client,
            default => null,
        };

        return $client ? $user->can('view', $client) : $user->can('documents.upload');
    }

    public function create(User $user): bool
    {
        return $user->can('documents.upload');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('documents.upload') && $this->view($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can('documents.delete') && $this->view($user, $document);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('documents.delete');
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->can('documents.delete');
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return false;
    }
}

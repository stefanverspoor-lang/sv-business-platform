<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMessage extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = [
        'mailbox_id', 'folder', 'uid', 'direction', 'message_id', 'in_reply_to', 'thread_key',
        'subject', 'from_email', 'from_name', 'to', 'cc', 'sent_at', 'body_text', 'body_html',
        'is_read', 'has_attachments', 'assigned_to', 'sent_by', 'is_spam',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'sent_at' => 'datetime',
            'is_read' => 'boolean',
            'is_spam' => 'boolean',
            'has_attachments' => 'boolean',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(EmailLink::class);
    }

    /** Alle berichten in dezelfde conversatie. */
    public function thread(): HasMany
    {
        return $this->hasMany(self::class, 'thread_key', 'thread_key')->orderBy('sent_at');
    }

    public function linkedClients(): Builder
    {
        return Client::query()->whereIn('id', $this->links()->where('linkable_type', (new Client)->getMorphClass())->pluck('linkable_id'));
    }

    /** Zonder emails.view_all: alleen aan jou toegewezen of door jou verstuurde mail. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('emails.view_all')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('assigned_to', $user->getKey())->orWhere('sent_by', $user->getKey()));
    }

    public function assigneeName(): ?string
    {
        return $this->assigned_to ? User::find($this->assigned_to)?->name : null;
    }

    public function counterparty(): ?string
    {
        if ($this->direction === 'in') {
            return $this->from_name ?: $this->from_email;
        }

        return collect($this->to ?? [])->map(fn ($r) => $r['name'] ?? $r['email'] ?? '')->filter()->implode(', ');
    }

    /** Alle e-mailadressen van de andere partij(en). */
    public function counterpartyAddresses(): array
    {
        $addresses = $this->direction === 'in'
            ? [$this->from_email]
            : collect(array_merge($this->to ?? [], $this->cc ?? []))->pluck('email')->all();

        return array_values(array_unique(array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), $addresses))));
    }

    public static function threadKeyFor(?string $subject, array $addresses): string
    {
        $normalized = strtolower(trim(preg_replace('/^((re|fw|fwd|aw|wg|antw|doorst)\s*:\s*)+/i', '', (string) $subject)));
        sort($addresses);

        return hash('sha256', $normalized.'|'.implode(',', $addresses));
    }
}

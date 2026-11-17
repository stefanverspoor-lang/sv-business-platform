<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mailbox extends Model
{
    use Auditable, BelongsToCompanyDatabase;

    protected $fillable = [
        'name', 'email', 'username', 'password', 'imap_host', 'imap_port', 'imap_encryption',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'sent_folder', 'sync_since', 'is_active',
        'default_assignee', 'sync_state', 'last_synced_at', 'last_error',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_active' => 'boolean',
            'sync_since' => 'date',
            'sync_state' => 'array',
            'last_synced_at' => 'datetime',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function unreadCount(): int
    {
        return $this->messages()->where('direction', 'in')->where('is_read', false)->count();
    }
}

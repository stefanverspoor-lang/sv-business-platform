<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLink extends Model
{
    use BelongsToCompanyDatabase;

    protected $fillable = ['email_message_id', 'linkable_type', 'linkable_id', 'is_automatic', 'linked_by'];

    protected function casts(): array
    {
        return ['is_automatic' => 'boolean'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}

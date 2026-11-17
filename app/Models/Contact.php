<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use Auditable, BelongsToCompanyDatabase, HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'first_name', 'last_name', 'job_title', 'email', 'phone',
        'mobile', 'preferred_locale', 'is_primary', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function emails(): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        return $this->morphToMany(EmailMessage::class, 'linkable', 'email_links')->withPivot('is_automatic');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}

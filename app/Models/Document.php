<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompanyDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Bestand gekoppeld aan een klant, contactpersoon of contract (later meer).
 * Bestanden staan buiten de publieke map, per bedrijf in een eigen map.
 */
class Document extends Model
{
    use Auditable, BelongsToCompanyDatabase, SoftDeletes;

    /** Toegestane bestandstypes (MIME). */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/csv', 'text/plain', 'application/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    public const MAX_SIZE_KB = 20480; // 20 MB

    protected $fillable = [
        'documentable_type', 'documentable_id', 'title', 'category', 'disk', 'path',
        'original_name', 'mime_type', 'size', 'notes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['category' => DocumentCategory::class, 'size' => 'integer'];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function humanSize(): string
    {
        $size = $this->size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024) {
                return round($size, 1).' '.$unit;
            }
            $size /= 1024;
        }

        return round($size, 1).' TB';
    }

    protected static function booted(): void
    {
        // Bij definitief verwijderen ook het bestand verwijderen.
        static::forceDeleted(fn (Document $doc) => Storage::disk($doc->disk)->delete($doc->path));
    }
}

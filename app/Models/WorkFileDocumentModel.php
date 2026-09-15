<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document scanned against a file.
 *
 * Several per file, because documents get revised: a corrected form is a new
 * upload and not an overwrite, so the record of what was sent at the time
 * survives. The customer is offered the newest, which is the one that supersedes
 * the rest.
 */
class WorkFileDocumentModel extends Model
{
    public $table = 'work_file_document';

    use HasFactory;

    public function file(): BelongsTo
    {
        return $this->belongsTo(WorkFileModel::class, 'work_file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The newest document on a file, or null.
     *
     * Ordered by id and not by created_at: several uploaded in one save share a
     * timestamp to the second, and "the latest" has to be one document rather
     * than whichever of them the database happened to return first.
     */
    public static function latestFor(int $fileId): ?self
    {
        return self::where('work_file_id', $fileId)->orderByDesc('id')->first();
    }

    /**
     * How large it is, said the way a person would.
     *
     * Bytes are the honest number and the useless one: nobody deciding whether
     * to open something on a phone is helped by 2,411,724.
     */
    public function sizeText(): string
    {
        $bytes = (int) $this->size;

        if ($bytes <= 0) {
            return '';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document scanned against a file.
 *
 * Several per file: a folder's papers are an RC, a Form 29, an NOC, each its own
 * scan. A corrected form is a new upload rather than an overwrite, so the record
 * of what was sent at the time survives. The customer is offered all of them,
 * each under the name the office gave it.
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

    /** The longest name the office can give one. Matches the column. */
    public const TITLE_MAX = 120;

    /**
     * What to call it on screen.
     *
     * The name the office gave it, or — for anything uploaded before names
     * existed — the name it arrived with, less the ".pdf" every one of them
     * shares. Never blank: a row with nothing to click on is a row nobody opens.
     */
    public function displayName(): string
    {
        $title = trim((string) $this->title);

        if ($title !== '') {
            return $title;
        }

        $arrived = preg_replace('/\.pdf$/i', '', trim((string) $this->original_name));

        return $arrived !== '' ? $arrived : 'Document';
    }

    /**
     * What it is saved as on the reader's computer.
     *
     * The display name plus .pdf, with the characters no file system accepts
     * taken out. A customer who downloads four documents should end up with
     * "RC.pdf" and "Form 29.pdf", not four files called scan_00123.pdf — and a
     * name typed with a slash in it ("Form 29/30") must not become a path.
     */
    public function downloadName(): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', $this->displayName());
        $name = trim(preg_replace('/\s+/u', ' ', $name), " .");

        return ($name !== '' ? mb_substr($name, 0, self::TITLE_MAX) : 'Document').'.pdf';
    }

    /**
     * The same name in plain ASCII, for the browsers that cannot read the other.
     *
     * A Content-Disposition header carries both. Symfony insists the fallback is
     * ASCII with no slash and no percent sign, and a name written entirely in
     * Hindi transliterates to something or to nothing — so nothing becomes a
     * name that still says what the file is.
     */
    public function downloadFallback(): string
    {
        $ascii = str_replace(['%', '/', '\\'], '', \Illuminate\Support\Str::ascii($this->downloadName()));
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii));

        return ($ascii === '' || strcasecmp($ascii, '.pdf') === 0) ? 'document.pdf' : $ascii;
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

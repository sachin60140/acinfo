<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkFileStatusLogModel extends Model
{
    public $table = 'work_file_status_log';

    use HasFactory;

    public function file(): BelongsTo
    {
        return $this->belongsTo(WorkFileModel::class, 'work_file_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * A note added without moving the file along.
     */
    public function isNoteOnly(): bool
    {
        return $this->from_status === $this->to_status;
    }

    /**
     * The opening entry, written when the file was received.
     */
    public function isOpening(): bool
    {
        return $this->from_status === null;
    }

    public function fromLabel(): ?string
    {
        return $this->from_status ? (WorkFileModel::STATUSES[$this->from_status] ?? $this->from_status) : null;
    }

    public function toLabel(): string
    {
        return WorkFileModel::STATUSES[$this->to_status] ?? $this->to_status;
    }
}

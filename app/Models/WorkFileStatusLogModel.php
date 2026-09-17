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
     *
     * Not a handover, which also moves nothing but is something that happened
     * rather than something somebody said.
     */
    public function isNoteOnly(): bool
    {
        return $this->from_status === $this->to_status && $this->event === null;
    }

    /** The papers went back to the customer. */
    public function isHandover(): bool
    {
        return $this->event === WorkFileModel::HANDED_OVER;
    }

    /** A handover recorded by mistake, taken back. */
    public function isHandoverUndone(): bool
    {
        return $this->event === WorkFileModel::HANDOVER_UNDONE;
    }

    /** What the office history calls this entry: a move, a note, a receipt or a handover. */
    public function kind(): string
    {
        return match (true) {
            $this->isOpening() => 'opening',
            $this->isHandover() => 'handover',
            $this->isHandoverUndone() => 'handover_undone',
            $this->isNoteOnly() => 'note',
            default => 'move',
        };
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

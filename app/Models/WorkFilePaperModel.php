<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One paper on one file's checklist.
 *
 * A single line however many works need it — one RC serves the hypothecation
 * removal, the transfer and the new hypothecation alike. Which works it was
 * checked for is recorded beside it, so a pending paper holds only those, and
 * cancelling one work drops exactly the papers that only it needed.
 */
class WorkFilePaperModel extends Model
{
    public $table = 'work_file_paper';

    use HasFactory;

    public const RECEIVED = 'received';

    public const PENDING = 'pending';

    public const NOT_NEEDED = 'not_needed';

    public const STATES = [
        self::RECEIVED => 'Received',
        self::PENDING => 'Pending',
        self::NOT_NEEDED => 'Not needed',
    ];

    protected $casts = [
        'required' => 'boolean',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(WorkFileModel::class, 'work_file_id');
    }

    public function paperType(): BelongsTo
    {
        return $this->belongsTo(PaperTypeModel::class, 'paper_type_id');
    }

    /** The works on the file this paper was checked for. */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(WorkFileItemModel::class, 'work_file_paper_item', 'work_file_paper_id', 'work_file_item_id');
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money the office paid out on one file.
 *
 * Office cash, and cost only: it went out of the till rather than to a vendor or
 * on to a customer, so it writes to no party ledger. What it does is raise what
 * the file cost, which is the whole reason it is recorded — every margin this
 * application showed before this existed was too high by exactly the amount
 * nobody was tracking.
 */
class WorkFileExpenseModel extends Model
{
    public $table = 'work_file_expense';

    use HasFactory;

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(WorkFileModel::class, 'work_file_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseTypeModel::class, 'expense_type_id');
    }

    /**
     * What each of a set of files has been spent on, in one query.
     *
     * @param  array<int, int>  $fileIds
     * @return array<int, float>
     */
    public static function totalsFor(array $fileIds): array
    {
        if (! $fileIds) {
            return [];
        }

        return self::query()
            ->whereIn('work_file_id', $fileIds)
            ->groupBy('work_file_id')
            ->selectRaw('work_file_id, COALESCE(SUM(amount), 0) as spent')
            ->pluck('spent', 'work_file_id')
            ->map(fn ($spent) => (float) $spent)
            ->all();
    }
}

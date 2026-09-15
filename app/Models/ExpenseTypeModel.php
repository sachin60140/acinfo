<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of money the office pays out on a file.
 *
 * A transfer challan, an affidavit, a notary's fee. Reference data, exactly like
 * work_type — the office adds its own and retires the ones it stops using,
 * without any of them meaning anything special to the code.
 */
class ExpenseTypeModel extends Model
{
    public $table = 'expense_type';

    use HasFactory;

    /**
     * The ones an expense may be recorded under, with the retired ones kept in
     * when a file already uses them.
     *
     * A type is retired rather than deleted, so an expense entered last March
     * still says what it was for — but the retired name must not be offered
     * again on a file that is not already carrying it.
     */
    public static function selectList($includeId = null)
    {
        return self::query()
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', 1);

                if ($includeId) {
                    $q->orWhereIn('id', (array) $includeId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'default_amount', 'is_active']);
    }
}

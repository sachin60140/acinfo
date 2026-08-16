<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkTypeModel extends Model
{
    public $table = 'work_type';

    use HasFactory;

    /**
     * Work types with how much work has been booked against each.
     *
     * The counts are what make a type safe to retire: a type nothing was ever
     * booked against can be removed outright, one with files behind it can only
     * be deactivated, because deleting it would orphan those files.
     */
    public static function withUsage()
    {
        return DB::table('work_type')
            ->leftJoin('work_file', 'work_file.work_type_id', '=', 'work_type.id')
            ->select(
                'work_type.id',
                'work_type.name',
                'work_type.default_rate',
                'work_type.default_vendor_rate',
                'work_type.is_active',
                DB::raw('COUNT(work_file.id) as file_count'),
                DB::raw('COALESCE(SUM(work_file.customer_amount), 0) as billed_total')
            )
            // ONLY_FULL_GROUP_BY: every non-aggregate column has to be listed.
            ->groupBy('work_type.id', 'work_type.name', 'work_type.default_rate', 'work_type.default_vendor_rate', 'work_type.is_active')
            ->orderBy('work_type.name', 'asc')
            ->get();
    }

    /**
     * Active types for the file form, each carrying its rate so picking a type
     * can pre-fill the amount without a second request.
     *
     * $includeId keeps a retired type in the list for the one file that already
     * uses it, so editing that file does not force the type to be changed.
     */
    public static function selectList($includeId = null)
    {
        return DB::table('work_type')
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', 1);

                if ($includeId) {
                    $q->orWhere('id', $includeId);
                }
            })
            ->select('id', 'name', 'default_rate', 'default_vendor_rate', 'is_active')
            ->orderBy('name', 'asc')
            ->get();
    }
}

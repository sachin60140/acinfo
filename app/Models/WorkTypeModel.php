<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkTypeModel extends Model
{
    public $table = 'work_type';

    use HasFactory;

    /*
     * What a work of this type asks for when a file is received, for the file
     * and its sticker: an HPT names its lender, an HPA its financer, a TR (a
     * transfer of ownership, TO) its seller and buyer. Set on the Work Types
     * screen; null asks for nothing.
     */
    public const ASKS_LENDER = 'lender';

    public const ASKS_FINANCER = 'financer';

    public const ASKS_PARTIES = 'parties';

    /** Each choice as the Work Types screen offers it. */
    public const ASKS = [
        self::ASKS_LENDER => 'Lender (as for HPT)',
        self::ASKS_FINANCER => 'Financer (as for HPA)',
        self::ASKS_PARTIES => 'Seller and buyer (as for TR, transfer)',
    ];

    /**
     * Work types with how much work has been booked against each.
     *
     * The counts are what make a type safe to retire: a type nothing was ever
     * booked against can be removed outright, one with files behind it can only
     * be deactivated, because deleting it would orphan those files.
     */
    public static function withUsage()
    {
        /*
         * Counted against the works, not the files.
         *
         * A folder holding a transfer and a hypothecation addition points at
         * only the first of them, so counting files credited the transfer with
         * the whole folder and left the hypothecation addition reading as work
         * nobody had ever booked — which is exactly the answer that makes a
         * type look safe to retire when it is not.
         *
         * Cancelled work is left in. This is a record of what a type has been
         * used for, and a type used once and struck off is still a type that
         * has been used.
         */
        return DB::table('work_type')
            ->leftJoin('work_file_item', 'work_file_item.work_type_id', '=', 'work_type.id')
            ->select(
                'work_type.id',
                'work_type.name',
                'work_type.default_rate',
                'work_type.default_vendor_rate',
                'work_type.asks_for',
                'work_type.is_active',
                DB::raw('COUNT(work_file_item.id) as file_count'),
                DB::raw('COALESCE(SUM(work_file_item.customer_amount), 0) as billed_total')
            )
            // ONLY_FULL_GROUP_BY: every non-aggregate column has to be listed.
            ->groupBy('work_type.id', 'work_type.name', 'work_type.default_rate', 'work_type.default_vendor_rate', 'work_type.asks_for', 'work_type.is_active')
            ->orderBy('work_type.name', 'asc')
            ->get();
    }

    /**
     * Active types for the file form, each carrying its rate so picking a type
     * can pre-fill the amount without a second request.
     *
     * $includeId keeps a retired type in the list for the one file that already
     * uses it, so editing that file does not force the type to be changed. One
     * id or several: a folder of several works has a type for each, and any of
     * them may be the one that was switched off.
     */
    public static function selectList($includeId = null)
    {
        return DB::table('work_type')
            ->where(function ($q) use ($includeId) {
                $q->where('is_active', 1);

                if ($includeId) {
                    $q->orWhereIn('id', (array) $includeId);
                }
            })
            ->select('id', 'name', 'default_rate', 'default_vendor_rate', 'asks_for', 'is_active')
            ->orderBy('name', 'asc')
            ->get();
    }
}

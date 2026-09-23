<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A figure the office sets for itself, and every figure it set before.
 *
 * Written to and never updated: the value in force is the newest row for its
 * key. Read the migration for why — a cap on money given away is the kind of
 * setting somebody asks about months later.
 */
class OfficeSettingModel extends Model
{
    protected $table = 'office_setting';

    public $timestamps = false;

    protected $guarded = [];

    /** The most that may be written off at one time, and against one file. */
    public const WRITEOFF_CAP = 'writeoff_cap';

    /**
     * Whether the office can set figures yet.
     *
     * The table arrives with a migration, and a deploy here is a git pull that
     * does not run one; until it has, everything behaves as it did before.
     */
    public static function available(): bool
    {
        static $known = null;

        return $known ??= Schema::hasTable('office_setting');
    }

    /** The figure in force, or null where none was ever set. */
    public static function current(string $key): ?string
    {
        if (! self::available()) {
            return null;
        }

        return DB::table('office_setting')->where('key', $key)->orderByDesc('id')->value('value');
    }

    /** The same as a number, with a blank or a missing row reading as nothing. */
    public static function amount(string $key): float
    {
        return round((float) self::current($key), 2);
    }

    /**
     * A new figure, which becomes the one in force.
     *
     * Refused while the table is not there: a deploy is a git pull that does
     * not run a migration, and a screen that throws says nothing about why.
     */
    public static function put(string $key, ?string $value, ?int $by = null): bool
    {
        if (! self::available()) {
            return false;
        }

        DB::table('office_setting')->insert([
            'key' => $key,
            'value' => $value,
            'created_by' => $by,
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * What this figure has been, newest first, with who set it.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function history(string $key, int $limit = 20)
    {
        if (! self::available()) {
            return collect();
        }

        return DB::table('office_setting as s')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.key', $key)
            ->orderByDesc('s.id')
            ->limit($limit)
            ->get(['s.value', 's.created_at', 'u.name as by']);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A paper the office deals in: the RC, Form 35, a bank NOC.
 *
 * Reference data, like work types and expense types — added, corrected, and
 * retired rather than deleted once a file has been checked against it. Which
 * works need it lives in work_type_paper, edited from the same screen.
 */
class PaperTypeModel extends Model
{
    public $table = 'paper_type';

    use HasFactory;

    /** How a work type needs a paper, as the Paper Types form asks it. */
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const NEED_LABELS = [
        self::REQUIRED => 'Required',
        self::OPTIONAL => 'If applicable',
        '' => 'Not needed',
    ];

    /**
     * What each active work type needs of this paper: work_type_id => 'required'
     * or 'optional'. A work type that does not need it is absent.
     *
     * @return array<int, string>
     */
    public function needs(): array
    {
        if (! $this->exists) {
            return [];
        }

        return DB::table('work_type_paper')
            ->where('paper_type_id', $this->id)
            ->pluck('required', 'work_type_id')
            ->map(fn ($required) => $required ? self::REQUIRED : self::OPTIONAL)
            ->all();
    }

    /**
     * Record what each of the given work types needs of this paper.
     *
     * Only the work types named are touched. The form lists active ones, and a
     * retired work type's list is left exactly as it was — it may come back.
     *
     * @param  array<int, string>  $needs  work_type_id => 'required' | 'optional' | ''
     */
    public function setNeeds(array $needs): void
    {
        $now = now();

        foreach ($needs as $workTypeId => $need) {
            $workTypeId = (int) $workTypeId;

            if ($need === self::REQUIRED || $need === self::OPTIONAL) {
                DB::table('work_type_paper')->updateOrInsert(
                    ['work_type_id' => $workTypeId, 'paper_type_id' => $this->id],
                    ['required' => $need === self::REQUIRED ? 1 : 0, 'updated_at' => $now, 'created_at' => $now]
                );
            } else {
                DB::table('work_type_paper')
                    ->where('work_type_id', $workTypeId)
                    ->where('paper_type_id', $this->id)
                    ->delete();
            }
        }
    }

    /** How many file checklists carry this paper. A paper in use is retired, not deleted. */
    public function timesUsed(): int
    {
        return $this->exists
            ? DB::table('work_file_paper')->where('paper_type_id', $this->id)->count()
            : 0;
    }
}

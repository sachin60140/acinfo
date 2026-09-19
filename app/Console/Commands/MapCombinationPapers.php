<?php

namespace App\Console\Commands;

use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives the retired combination work types the papers their parts need.
 *
 * Before a file could hold several works, one work type stood for all of them:
 * HPT + TR + HPA was a single type on a single file. Those types were retired,
 * but the files booked under them were not, and they are still in the office.
 *
 * Nobody ever mapped papers to them, so those files cannot be audited — which
 * left the ones already sitting in Paper Pendency with no way out and no screen
 * to appear on. The answer was never a special case in the code: an HPT + TR +
 * HPA file needs the papers of an HPT, a TR and an HPA, which the office has
 * already decided. This reads the name, finds those three types, and gives the
 * combination the union of their papers.
 *
 * A paper is required on the combination if it is required on any part of it:
 * a form the transfer cannot go without is not optional because the
 * hypothecation could manage.
 *
 * Reports and changes nothing unless --apply is passed, because it writes to
 * the lists the dispatch gate enforces.
 */
class MapCombinationPapers extends Command
{
    protected $signature = 'papers:map-combinations
        {--apply : Write the mapping. Without this, it only says what it would do}';

    protected $description = 'Give the retired combination work types the papers of their parts';

    public function handle(): int
    {
        $types = WorkTypeModel::orderBy('name')->get();
        $byName = $types->keyBy(fn ($type) => $this->key($type->name));

        // What each type already asks for, so a part with no list of its own is
        // reported rather than quietly contributing nothing.
        $papers = DB::table('work_type_paper')
            ->get(['work_type_id', 'paper_type_id', 'required'])
            ->groupBy('work_type_id');

        $planned = 0;
        $skipped = [];

        foreach ($types as $type) {
            if (! str_contains($type->name, '+') || $papers->has($type->id)) {
                continue;
            }

            $parts = collect(explode('+', $type->name))
                ->map(fn ($part) => trim($part))
                ->filter();

            $missing = $parts->reject(fn ($part) => $byName->has($this->key($part)));

            if ($missing->isNotEmpty()) {
                $skipped[] = $type->name.' — no work type called '.$missing->implode(', ');

                continue;
            }

            /*
             * Required wins. A paper the transfer cannot go without is not
             * optional on a folder that also holds a hypothecation.
             */
            $wanted = [];

            foreach ($parts as $part) {
                foreach ($papers->get($byName[$this->key($part)]->id, collect()) as $row) {
                    $wanted[$row->paper_type_id] = ($wanted[$row->paper_type_id] ?? 0) || $row->required;
                }
            }

            if (! $wanted) {
                $skipped[] = $type->name.' — its parts have no papers either';

                continue;
            }

            $files = WorkFileModel::whereHas('items', fn ($q) => $q->where('work_type_id', $type->id))->count();

            $this->line(sprintf(
                '%s  ←  %s  (%d %s, %d %s)',
                $type->name,
                $parts->implode(' + '),
                count($wanted),
                str('paper')->plural(count($wanted)),
                $files,
                str('file')->plural($files)
            ));

            $planned += count($wanted);

            if ($this->option('apply')) {
                DB::table('work_type_paper')->insert(collect($wanted)->map(fn ($required, $paperId) => [
                    'work_type_id' => $type->id,
                    'paper_type_id' => $paperId,
                    'required' => $required ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->values()->all());
            }
        }

        foreach ($skipped as $why) {
            $this->warn('skipped '.$why);
        }

        if (! $planned) {
            $this->info('Nothing to map: every combination type already has its papers.');

            return self::SUCCESS;
        }

        if ($this->option('apply')) {
            $this->info($planned.' paper '.str('line')->plural($planned).' written. Those files are now on Paper Audit, waiting to be checked.');

            return self::SUCCESS;
        }

        $this->info($planned.' paper '.str('line')->plural($planned).' would be written. Run again with --apply to do it.');

        return self::SUCCESS;
    }

    /** Names are matched as people type them: "hpt", "HPT " and "Hpt" are one type. */
    private function key(string $name): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim($name)));
    }
}

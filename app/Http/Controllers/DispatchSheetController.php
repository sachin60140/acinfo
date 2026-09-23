<?php

namespace App\Http\Controllers;

use App\Models\PartyModel;
use App\Models\WorkFileModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The paper a vendor signs for what they were handed.
 *
 * One vendor, one day: that is what a hand-over is, and it is how the works
 * record it — vendor_id and vendor_date, written together by Give to Vendor.
 * The sheet lists the folders and the works on them, leaves a column for the
 * vendor to write on, and ends with a line for their signature. One copy is
 * signed and kept; the other goes with them.
 *
 * No money on it. A rate is between the office and that vendor for that job,
 * and a sheet is a thing that travels: it is handed over, photographed, left on
 * a counter. Nor any customer's name, for the same reason it is nowhere else a
 * vendor can read — see WorkFileModel::worksFor.
 *
 * Printed from the browser rather than built as a PDF here: the host has no
 * composer, so a library cannot arrive with a pull, and every browser prints to
 * PDF anyway.
 */
class DispatchSheetController extends Controller
{
    public function index(Request $req)
    {
        $req->validate([
            'vendor' => 'nullable|integer|exists:party,id',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $vendorId = $req->query('vendor');
        $date = $req->query('date');

        $vendor = $vendorId ? PartyModel::where('party_type', 'vendor')->find($vendorId) : null;
        $files = ($vendor && $date) ? self::handedOver((int) $vendor->id, $date) : collect();

        return view('admin.work.dispatch-sheet', [
            'vendor' => $vendor,
            'date' => $date,
            'dateText' => $date ? date('d-m-Y', strtotime($date)) : null,
            'files' => $files,
            'works' => $files->sum(fn ($file) => count($file->works)),
            /*
             * Whom the office has given anything to, and when: the two boxes
             * above the sheet, so it can be printed again months later. Every
             * vendor, working with the office or not — a sheet from last year
             * belongs to whoever signed it.
             */
            'vendors' => PartyModel::where('party_type', 'vendor')->orderBy('name')->get(['id', 'name']),
            'days' => $vendor ? self::daysFor((int) $vendor->id) : collect(),
        ]);
    }

    /**
     * The folders one vendor was given on one day, with the works on each.
     *
     * By the works, not by the folder: half a folder can go to one vendor and
     * half to another, and the sheet must say what this vendor actually took.
     * A work struck off since is not on it — nobody signs for work that is not
     * being done.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function handedOver(int $vendorId, string $date)
    {
        $items = DB::table('work_file_item as i')
            ->join('work_file as f', 'f.id', '=', 'i.work_file_id')
            ->leftJoin('work_type as t', 't.id', '=', 'i.work_type_id')
            ->where('i.vendor_id', $vendorId)
            ->whereDate('i.vendor_date', $date)
            ->where('i.status', '<>', WorkFileModel::CANCELLED)
            ->orderBy('f.file_no')
            ->orderBy('i.id')
            ->get(['f.id', 'f.file_no', 'f.registration_no', 't.name as work'])
            ->groupBy('id')
            ->map(fn ($rows) => (object) [
                'file_no' => $rows->first()->file_no,
                'registration_no' => $rows->first()->registration_no,
                'works' => $rows->pluck('work')->filter()->unique()->values()->all(),
            ]);

        /*
         * And the folders that hold no works of their own. Give to Vendor
         * hands those over on the folder itself, so nothing about them is in
         * the works at all — and the office was offered a sheet for them that
         * said nothing had been given.
         */
        $folders = DB::table('work_file as f')
            ->leftJoin('work_type as t', 't.id', '=', 'f.work_type_id')
            ->where('f.vendor_id', $vendorId)
            ->whereDate('f.vendor_date', $date)
            ->where('f.status', '<>', WorkFileModel::CANCELLED)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('work_file_item as x')->whereColumn('x.work_file_id', 'f.id'))
            ->get(['f.id', 'f.file_no', 'f.registration_no', 't.name as work'])
            ->keyBy('id')
            ->map(fn ($row) => (object) [
                'file_no' => $row->file_no,
                'registration_no' => $row->registration_no,
                'works' => array_values(array_filter([$row->work])),
            ]);

        return $items->merge($folders)->sortBy('file_no')->values();
    }

    /**
     * The days this vendor was given anything, newest first, so a sheet can be
     * found again without remembering the date.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function daysFor(int $vendorId)
    {
        $byWork = DB::table('work_file_item as i')
            ->where('i.vendor_id', $vendorId)
            ->whereNotNull('i.vendor_date')
            ->where('i.status', '<>', WorkFileModel::CANCELLED)
            ->groupBy('i.vendor_date')
            ->get([
                'i.vendor_date as day',
                DB::raw('COUNT(DISTINCT i.work_file_id) as files'),
            ]);

        // The folders that hold no works, counted the same way.
        $byFolder = DB::table('work_file as f')
            ->where('f.vendor_id', $vendorId)
            ->whereNotNull('f.vendor_date')
            ->where('f.status', '<>', WorkFileModel::CANCELLED)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('work_file_item as x')->whereColumn('x.work_file_id', 'f.id'))
            ->groupBy('f.vendor_date')
            ->get(['f.vendor_date as day', DB::raw('COUNT(*) as files')]);

        // Added together, so a day with both kinds is one line with one count.
        $days = [];

        foreach ($byWork->concat($byFolder) as $row) {
            $day = substr((string) $row->day, 0, 10);
            $days[$day] = ($days[$day] ?? 0) + (int) $row->files;
        }

        krsort($days);

        // Every day, newest first: an old sheet is reprinted months later, and
        // a window of the last sixty would have hidden it.
        return collect($days)->map(fn ($files, $day) => (object) ['day' => $day, 'files' => $files])->values();
    }
}

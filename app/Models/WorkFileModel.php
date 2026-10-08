<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkFileModel extends Model
{
    public $table = 'work_file';

    use HasFactory;

    public const CANCELLED = 'cancelled';

    public const APPROVED = 'approval_done';

    /**
     * Some jobs on the file are through and some are not. Derived, never set by
     * hand, and still work in hand — which is why it is an open status.
     */
    public const PARTLY_APPROVED = 'partly_approved';

    public const RETURNED = 'paper_returned';

    /*
     * Events on the status log that are not moves. See the migration that added
     * the column: a handover changes no status, and telling one apart from a
     * note by its wording would make the history depend on nobody rewording it.
     */
    public const HANDED_OVER = 'handed_over';

    public const HANDOVER_UNDONE = 'handover_undone';

    /** The files list's view of approved work whose papers are still here. */
    public const AWAITING_HANDOVER = 'awaiting_handover';

    /** A checklist was saved or a paper came in. See the status log's event column. */
    public const PAPERS = 'papers';

    /**
     * A file given to a vendor before its papers were complete, and why.
     * The office's record: kept off every customer page.
     */
    public const PAPERS_OVERRIDE = 'papers_override';

    /**
     * A price that was already agreed, changed, and why.
     *
     * The office's own note. Why a rate moved is between the office and its
     * vendor — or its own margin — and the customer's page shows what they are
     * charged, never the reasoning behind it.
     */
    public const PRICE = 'price';

    /** Events a customer never reads, whatever they say. */
    public const OFFICE_ONLY_EVENTS = [self::PAPERS_OVERRIDE, self::PRICE];

    /** The files list's views of the paper checklist. */
    public const AWAITING_AUDIT = 'awaiting_audit';

    public const PAPERS_PENDING = 'papers_pending';

    /**
     * A live work on this file whose papers are still to be checked, as SQL.
     *
     * Live meaning not finished: approved, returned and cancelled work has
     * nothing left to be checked for. Only papers still being asked for count —
     * a work type with no list, or a list of retired papers, has nothing to
     * check and is not held up by an audit that could never find anything.
     */
    public const NEEDS_AUDIT = "EXISTS (SELECT 1 FROM work_file_item nai
        JOIN work_type_paper nat ON nat.work_type_id = nai.work_type_id
        JOIN paper_type nap ON nap.id = nat.paper_type_id AND nap.is_active = 1
        WHERE nai.work_file_id = work_file.id
          AND nai.papers_audited_at IS NULL
          AND nai.status NOT IN ('approval_done', 'paper_returned', 'cancelled'))";

    /**
     * A paper this file is still waiting for, for work that is not finished.
     */
    public const PENDING_PAPERS = "EXISTS (SELECT 1 FROM work_file_paper ppp
        JOIN work_file_paper_item ppi ON ppi.work_file_paper_id = ppp.id
        JOIN work_file_item ppw ON ppw.id = ppi.work_file_item_id
        WHERE ppp.work_file_id = work_file.id
          AND ppp.state = 'pending'
          AND ppw.status NOT IN ('approval_done', 'paper_returned', 'cancelled'))";

    /** The same, named: "Form 30, Form 34" — for a list with no room for a query per row. */
    public const PENDING_PAPER_NAMES = "(SELECT GROUP_CONCAT(DISTINCT ppt.name ORDER BY ppt.sort, ppt.name SEPARATOR ', ')
        FROM work_file_paper ppn
        JOIN paper_type ppt ON ppt.id = ppn.paper_type_id
        JOIN work_file_paper_item ppni ON ppni.work_file_paper_id = ppn.id
        JOIN work_file_item ppnw ON ppnw.id = ppni.work_file_item_id
        WHERE ppn.work_file_id = work_file.id
          AND ppn.state = 'pending'
          AND ppnw.status NOT IN ('approval_done', 'paper_returned', 'cancelled'))";

    /**
     * How long a line on a file's history can be: work_file_status_log.remark is
     * a varchar(255), counted in characters rather than bytes under utf8mb4.
     */
    public const REMARK_LIMIT = 255;

    public const IN_OFFICE = 'in_office';

    public const DISPATCHED = 'file_dispatch';

    /** Set and cleared by the paper checklist, never chosen by hand. */
    public const PAPER_PENDENCY = 'paper_pendency';

    /** What the vendor filter calls work nobody was given. */
    public const IN_HOUSE = 'none';

    public const STATUSES = [
        'in_office' => 'In Office',
        'paper_pendency' => 'Paper Pendency',
        'file_dispatch' => 'File Dispatch',
        'part_pesi_required' => 'Part Pesi Required',
        'under_verification' => 'Under Verification',
        'partly_approved' => 'Partly Approved',
        'approval_done' => 'Approval Done',
        'paper_returned' => 'Paper Returned to Customer',
        'cancelled' => 'Cancelled',
    ];

    /**
     * The states a single job can be put into from the board.
     *
     * Partly approved is missing on purpose: it describes a folder whose jobs
     * disagree with each other, and a single job never disagrees with itself.
     *
     * Returning papers is here for the ordinary file, which holds one job: it
     * is how a return has always been recorded and it still reads true. On a
     * folder holding several works it is refused — papers go back in one
     * envelope, so a return is agreed for the folder, on the return screen,
     * where the refund figure can be seen and edited down.
     *
     * @see returnableJobStatuses()
     */
    public const JOB_STATUSES = [
        'in_office' => 'In Office',
        'paper_pendency' => 'Paper Pendency',
        'file_dispatch' => 'File Dispatch',
        'part_pesi_required' => 'Part Pesi Required',
        'under_verification' => 'Under Verification',
        'approval_done' => 'Approval Done',
        'paper_returned' => 'Paper Returned to Customer',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Work still in hand: the file is with us and something remains to be done.
     * Everything else is an end state — approved, given back, or written off.
     */
    public const OPEN_STATUSES = ['in_office', 'paper_pendency', 'file_dispatch',
        'part_pesi_required', 'under_verification', 'partly_approved'];

    /**
     * The same nine states, said to the person whose file it is.
     *
     * STATUSES is office vocabulary — "File Dispatch" and "Part Pesi Required"
     * mean something to whoever runs the counter and nothing to a customer.
     * More than clarity is at stake in one of them: dispatch is the moment the
     * papers go to a vendor, and naming that here would give away who does the
     * work. It says where the file has got to, not who is holding it.
     *
     * Kept as a separate map rather than derived, so changing what the office
     * calls a state cannot quietly change what a customer is told.
     */
    public const CUSTOMER_STATUSES = [
        'in_office' => 'In our office',
        'paper_pendency' => 'Documents pending from you',
        'file_dispatch' => 'Submitted at the RTO',
        'part_pesi_required' => 'Part payment required',
        'under_verification' => 'Under verification',
        'partly_approved' => 'Partly approved',
        'approval_done' => 'Approved',
        'paper_returned' => 'Returned to you',
        'cancelled' => 'Cancelled',
    ];

    /**
     * What to tell a customer a file is doing.
     *
     * Falls back to the plainest true thing rather than to the raw key: an
     * unmapped state leaking "part_pesi_required" onto a customer's screen is
     * worse than saying it is in progress.
     */
    public static function customerStatus(?string $status): string
    {
        return self::CUSTOMER_STATUSES[$status] ?? 'In progress';
    }

    /**
     * How a customer's status badge should be coloured.
     *
     * The office badge takes the raw status as its data-state, which is fine on
     * a screen the office reads. On the portal it would put the office's own
     * shorthand into the page source — "file_dispatch" says the papers were
     * sent somewhere, which is the one thing the wording above is careful not
     * to say. So the portal colours on a tone instead, and the tone says how
     * the file feels rather than what the office calls it.
     */
    public static function customerTone(?string $status): string
    {
        return match ($status) {
            'paper_pendency', 'part_pesi_required' => 'needs-you',
            'file_dispatch', 'under_verification', 'partly_approved' => 'moving',
            self::APPROVED => 'approved',
            self::RETURNED => 'closed',
            self::CANCELLED => 'cancelled',
            default => 'waiting',
        };
    }

    /**
     * Bootstrap contextual class per status, for the badge on the list.
     */
    public const STATUS_BADGES = [
        'in_office' => 'bg-secondary',
        'paper_pendency' => 'bg-warning text-dark',
        'file_dispatch' => 'bg-info text-dark',
        'part_pesi_required' => 'bg-warning text-dark',
        'under_verification' => 'bg-primary',
        'partly_approved' => 'bg-info text-dark',
        'approval_done' => 'bg-success',
        'paper_returned' => 'bg-dark',
        'cancelled' => 'bg-danger',
    ];

    /**
     * Approval is the one status that has to be evidenced, so it cannot be set
     * without a screenshot of the approval on file.
     */
    public function requiresScreenshot(): bool
    {
        return $this->status === self::APPROVED;
    }

    /**
     * A cancelled file keeps its record and its number but stops counting: it
     * charges nobody, owes nobody, and contributes nothing to any total.
     *
     * This is for a file entered in error. A file whose papers went back to the
     * customer is a different thing — see isReturned().
     */
    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * The papers went back to the customer, so what they were charged goes back
     * with them.
     *
     * Unlike cancelling, the original debit is left standing and a matching
     * credit is added beside it. The work was received and later returned, and a
     * statement that shows both is the only one that says so — erasing the debit
     * would leave the customer's own records disagreeing with yours.
     */
    public function isReturned(): bool
    {
        return $this->status === self::RETURNED;
    }

    /**
     * Nothing further is expected of this file.
     *
     * Approved is done, returned went back and cancelled charged nobody — the
     * same test a single work uses, for the same reason. Papers arriving after
     * any of those are a new file, not more work on a settled one.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::APPROVED, self::RETURNED, self::CANCELLED], true);
    }

    /**
     * Payment mode stamped on the entries a file generates. A file is work
     * booked on account, not money moving, so it is never Cash.
     */
    private const LEDGER_MODE = 'Credit / Invoice';

    /**
     * Stamp the return date the first time a file is marked returned, and clear
     * it if that status is undone. Done on save rather than at the three call
     * sites so no route can set the status without the date following.
     */
    protected static function booted(): void
    {
        static::saving(function (self $file) {
            if ($file->isReturned()) {
                $file->returned_on = $file->returned_on ?: now()->toDateString();
            } elseif (! $file->isCancelled()) {
                // Genuinely no longer returned, so the figure goes with it and a
                // later return starts fresh rather than from a stale part one.
                $file->returned_on = null;
                $file->returned_amount = null;
            }

            /*
             * Cancelling keeps them, frozen. syncLedger withdraws every entry a
             * cancelled file has, so they change nothing while it stays
             * cancelled — but clearing them meant un-cancelling restored a FULL
             * refund dated today instead of the part refund actually agreed. A
             * customer who owed 3,000 came back owing nothing, from an undo.
             */

            /*
             * A folder not back has nothing reversed — unless its works say
             * part of it is: one vendor's handed back and another's still
             * out. That figure is the works', rolled up; see rollUp().
             */
            if (! $file->vendor_returned_on && $file->vendor_returned_amount !== null
                && ! (WorkFileItemModel::partReversals() && $file->exists
                    && $file->items()->whereNotNull('vendor_id')->exists())) {
                $file->vendor_returned_amount = null;
            }
        });

    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkTypeModel::class, 'work_type_id');
    }

    /**
     * What a customer is told when their work is approved, one notice per file.
     *
     * Built from the works approved in one save, so a board that approves a
     * transfer on one file and a hypothecation addition on another offers two
     * messages, each to its own customer. Each says which works came through
     * and when, what is still in progress on the same file, whether the papers
     * are ready to collect, and what the account owes.
     *
     * Never a vendor — a customer is not told who did the work — and never a
     * remark: what was typed on the approval is the office's own note.
     *
     * @param  array<int, int>  $itemIds  works that moved into Approval Done in this save
     * @return array<int, array<string, mixed>>
     */
    public static function approvalNotices(array $itemIds): array
    {
        if (! $itemIds) {
            return [];
        }

        $approved = WorkFileItemModel::with('workType')
            ->whereIn('id', $itemIds)
            ->where('status', self::APPROVED)
            ->orderBy('id')
            ->get()
            ->groupBy('work_file_id');

        if ($approved->isEmpty()) {
            return [];
        }

        return self::with(['customer', 'items.workType'])
            ->whereIn('id', $approved->keys()->all())
            ->orderBy('id')
            ->get()
            ->map(function ($file) use ($approved) {
                // What is still being worked on: not finished, not struck off.
                $pending = $file->items->reject(fn ($item) => in_array(
                    $item->status,
                    [self::APPROVED, self::CANCELLED, self::RETURNED],
                    true
                ));

                $customer = $file->customer;

                return [
                    'id' => (int) $file->id,
                    'fileNo' => (string) $file->file_no,
                    'vehicle' => (string) $file->registration_no,
                    'customer' => (string) ($customer?->name ?? ''),
                    // Their WhatsApp number when one is saved, as everywhere.
                    'mobile' => (string) ($customer?->whatsapp ?: $customer?->mobile),
                    'works' => $approved[$file->id]->map(fn ($item) => [
                        'work' => $item->workType?->name ?? 'Work',
                        'on' => $item->approved_on ? date('d-m-Y', strtotime($item->approved_on)) : '',
                    ])->values()->all(),
                    'pending' => $pending->map(fn ($item) => $item->workType?->name ?? 'Work')->values()->all(),
                    'papersReady' => $pending->isEmpty() && ! $file->handed_over_on,
                    'balance' => $customer ? round(PartyLedgerModel::currentBalance($customer->id), 2) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'vendor_id');
    }

    /**
     * The jobs this file is for.
     *
     * Every file has at least one. Papers brought in for a transfer and a
     * hypothecation addition at once are one folder with two jobs, each
     * priced and approved on its own.
     */
    public function items(): HasMany
    {
        return $this->hasMany(WorkFileItemModel::class, 'work_file_id')->orderBy('id');
    }

    public function statusLog(): HasMany
    {
        return $this->hasMany(WorkFileStatusLogModel::class, 'work_file_id')->orderBy('id', 'desc');
    }

    /**
     * Record a move, or a note added without one.
     *
     * Called explicitly rather than from a model event, because the remark comes
     * from the request and an event has no way to reach it.
     *
     * @param  string|null  $from  the status before the change; null when the file
     *                             was just received
     */
    public function logStatus(?string $from, ?string $remark = null, ?int $itemId = null, ?string $event = null): WorkFileStatusLogModel
    {
        $log = new WorkFileStatusLogModel;
        $log->work_file_id = $this->id;
        // Which job this is about, on a folder that holds several. Null when it
        // is about the folder itself.
        $log->work_file_item_id = $itemId;
        $log->from_status = $from;
        $log->to_status = $this->status;
        /*
         * Never allowed to fail the save it is describing.
         *
         * The column is 255 characters. Remarks somebody types are held to
         * less by the form that takes them, but a remark the application
         * writes for itself is built from whatever the data happens to hold —
         * and when one ran long, the database refused the line and took the
         * operator's paper checklist down with it. Shortened here as a last
         * resort, with an ellipsis so it reads as cut rather than as finished.
         * papersRemark() should mean this never happens; this is what makes
         * sure the next one cannot either.
         */
        $log->remark = ($remark === null || $remark === '')
            ? null
            : (mb_strlen($remark) > self::REMARK_LIMIT
                ? rtrim(mb_substr($remark, 0, self::REMARK_LIMIT - 1)).'…'
                : $remark);
        // Set only for something that happened without a move: a handover.
        $log->event = $event;
        $log->user_id = Auth::id();
        $log->save();

        return $log;
    }

    // ---------------------------------------------------------- the paper checklist

    public function papers(): HasMany
    {
        return $this->hasMany(WorkFilePaperModel::class, 'work_file_id');
    }

    /**
     * Every paper this file needs, for the work on it that is not finished.
     *
     * A work not yet checked asks for what its work type needs today. A work
     * already checked asks for exactly what it was checked against — the lines
     * recorded for it — so editing the master list later never rewrites a file
     * that has been done. A paper several works need is one line, naming all of
     * them, carrying whatever state it already has: a work added after the RC
     * came in does not ask for the RC again.
     *
     * @return array<int, array{paper_type_id: int, name: string, sort: int, required: bool, state: ?string, note: ?string, office_note: ?string, received_on: ?string, works: array<int, string>, line_id: ?int}>
     */
    public function paperChecklist(): array
    {
        $items = $this->items()->with('workType')->get()->reject(fn ($item) => $item->isSettled());

        if ($items->isEmpty()) {
            return [];
        }

        $rows = $this->papers()->with('paperType', 'items')->get()->keyBy('paper_type_id');

        // What each unchecked work's type needs, in one query.
        $fresh = $items->whereNull('papers_audited_at');
        $needs = $fresh->isEmpty() ? collect() : DB::table('work_type_paper')
            ->join('paper_type', 'paper_type.id', '=', 'work_type_paper.paper_type_id')
            ->whereIn('work_type_paper.work_type_id', $fresh->pluck('work_type_id')->unique()->all())
            ->where('paper_type.is_active', 1)
            ->get(['work_type_paper.work_type_id', 'work_type_paper.required', 'paper_type.id', 'paper_type.name', 'paper_type.sort'])
            ->groupBy('work_type_id');

        $lines = [];

        $add = function (int $paperId, string $name, int $sort, bool $required, $item) use (&$lines) {
            $lines[$paperId] ??= [
                'paper_type_id' => $paperId,
                'name' => $name,
                'sort' => $sort,
                'required' => false,
                'works' => [],
            ];

            $lines[$paperId]['required'] = $lines[$paperId]['required'] || $required;
            $lines[$paperId]['works'][$item->id] = $item->workType?->name ?? 'Work';
        };

        foreach ($items as $item) {
            if ($item->papers_audited_at === null) {
                foreach ($needs->get($item->work_type_id, []) as $need) {
                    $add((int) $need->id, $need->name, (int) $need->sort, (bool) $need->required, $item);
                }

                continue;
            }

            foreach ($rows as $row) {
                if ($row->items->contains('id', $item->id)) {
                    $add((int) $row->paper_type_id, $row->paperType->name, (int) $row->paperType->sort, (bool) $row->required, $item);
                }
            }
        }

        foreach ($lines as $paperId => &$line) {
            $row = $rows->get($paperId);

            $line['line_id'] = $row?->id;
            $line['state'] = $row?->state;
            $line['note'] = $row?->note;
            $line['office_note'] = $row?->office_note;
            $line['received_on'] = $row?->received_on;
        }
        unset($line);

        uasort($lines, fn ($a, $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);

        return $lines;
    }

    /**
     * Save a checked list: one state per paper, and the notes.
     *
     * Validation is the caller's — every line needs a state, and a required
     * paper marked not needed needs a reason. This writes what it is given,
     * attaches each line to the works it was checked for, stamps those works
     * as checked, moves them in or out of Paper Pendency, and writes one entry
     * on the file's history saying what changed.
     *
     * @param  array<int, array{state: string, note?: ?string, office_note?: ?string}>  $input  keyed by paper_type_id
     * @return array{pending: array<int, string>, changed: bool}
     */
    public function savePaperChecklist(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $checklist = $this->paperChecklist();
            $firstLook = $this->items()->whereNull('papers_audited_at')->whereNotIn('status', [self::APPROVED, self::RETURNED, self::CANCELLED])->pluck('id');
            $today = now()->toDateString();
            $folderWas = $this->status;

            $moved = ['received' => [], 'pending' => [], 'not_needed' => []];

            foreach ($checklist as $paperId => $line) {
                $in = $input[$paperId];
                $clean = fn ($value) => ($value = trim((string) $value)) !== '' ? mb_substr($value, 0, 200) : null;

                $row = WorkFilePaperModel::firstOrNew(['work_file_id' => $this->id, 'paper_type_id' => $paperId]);
                $was = $row->exists ? $row->state : null;

                $row->state = $in['state'];
                $row->required = $line['required'];
                $row->note = $clean($in['note'] ?? null);
                $row->office_note = $clean($in['office_note'] ?? null);

                if ($row->state === WorkFilePaperModel::RECEIVED) {
                    $row->received_on = $was === WorkFilePaperModel::RECEIVED ? ($row->received_on ?: $today) : $today;
                } else {
                    $row->received_on = null;
                }

                if (! $row->exists || $row->isDirty()) {
                    $row->updated_by = Auth::id();
                    $row->save();
                }

                if ($was !== $row->state) {
                    $moved[$row->state][] = $line['name'];
                }

                $row->items()->syncWithoutDetaching(array_keys($line['works']));
            }

            if ($firstLook->isNotEmpty()) {
                WorkFileItemModel::whereIn('id', $firstLook->all())->update(['papers_audited_at' => now()]);
            }

            $this->syncPaperPendency();

            $pending = collect($this->paperChecklist())
                ->where('state', WorkFilePaperModel::PENDING)
                ->pluck('name')
                ->values()
                ->all();

            $changed = $firstLook->isNotEmpty() || array_filter($moved);

            if ($changed) {
                $this->logStatus($folderWas, self::papersRemark($firstLook->isNotEmpty(), $moved, $pending), null, self::PAPERS);
            }

            return ['pending' => $pending, 'changed' => (bool) $changed];
        });
    }

    /**
     * A file's papers, as its customer may read them.
     *
     * What is still needed — for work that is not finished, with the works it
     * is for and the note written for the customer — and what has come in.
     * The office note is not selected at all, so it cannot reach a customer's
     * page by any route through this.
     *
     * @param  array<int, array{0: string, 1: string, 2?: int}>|null  $marks  vendorMarksFor() the file's customer
     * @return array{needed: array<int, array{name: string, works: array<int, string>, note: ?string}>, received: array<int, string>}
     */
    public static function customerPapers(int $fileId, ?array $marks = null): array
    {
        $live = fn ($q) => $q->select(DB::raw(1))
            ->from('work_file_paper_item as cpi')
            ->join('work_file_item as cpw', 'cpw.id', '=', 'cpi.work_file_item_id')
            ->whereColumn('cpi.work_file_paper_id', 'cp.id')
            ->where('cpw.status', '<>', self::CANCELLED);

        $lines = DB::table('work_file_paper as cp')
            ->join('paper_type as cpt', 'cpt.id', '=', 'cp.paper_type_id')
            ->where('cp.work_file_id', $fileId)
            ->whereIn('cp.state', [WorkFilePaperModel::PENDING, WorkFilePaperModel::RECEIVED])
            ->whereExists($live)
            ->orderBy('cpt.sort')
            ->orderBy('cpt.name')
            ->get(['cp.id', 'cp.state', 'cp.note', 'cpt.name']);

        // Which unfinished works each pending paper is holding, in one query.
        $pendingIds = $lines->where('state', WorkFilePaperModel::PENDING)->pluck('id')->all();

        $holding = $pendingIds ? DB::table('work_file_paper_item as hpi')
            ->join('work_file_item as hpw', 'hpw.id', '=', 'hpi.work_file_item_id')
            ->leftJoin('work_type as hpt', 'hpt.id', '=', 'hpw.work_type_id')
            ->whereIn('hpi.work_file_paper_id', $pendingIds)
            ->whereNotIn('hpw.status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            ->orderBy('hpw.id')
            ->get(['hpi.work_file_paper_id', 'hpt.name'])
            ->groupBy('work_file_paper_id') : collect();

        $needed = [];

        foreach ($lines->where('state', WorkFilePaperModel::PENDING) as $line) {
            $works = $holding->get($line->id, collect())->pluck('name')->filter()->unique()->values()->all();

            // A paper pending only for work already finished holds nothing up.
            if (! $works) {
                continue;
            }

            // The note is written for the customer, but typed by hand: no vendor in it.
            $needed[] = ['name' => $line->name, 'works' => $works, 'note' => self::redactVendors($line->note ?: null, $marks ??= self::vendorMarks())];
        }

        return [
            'needed' => $needed,
            'received' => $lines->where('state', WorkFilePaperModel::RECEIVED)->pluck('name')->values()->all(),
        ];
    }

    /**
     * Mark pending papers received, across however many files they are on.
     *
     * The counter's quickest action: the customer walks in with Form 30 for one
     * file and an NOC for another. Only lines still pending are touched, so a
     * page left open cannot mark something another person already changed.
     *
     * @param  array<int, int>  $lineIds  work_file_paper ids
     * @return array<int, array{file_no: string, papers: array<int, string>}> keyed by work_file_id
     */
    public static function receivePapers(array $lineIds, ?string $on = null): array
    {
        return DB::transaction(function () use ($lineIds, $on) {
            $lines = WorkFilePaperModel::with('paperType')
                ->whereIn('id', array_map('intval', $lineIds))
                ->where('state', WorkFilePaperModel::PENDING)
                ->lockForUpdate()
                ->get()
                ->groupBy('work_file_id');

            $done = [];

            foreach ($lines as $fileId => $group) {
                $file = self::find($fileId);

                if (! $file) {
                    continue;
                }

                $folderWas = $file->status;

                foreach ($group as $line) {
                    $line->state = WorkFilePaperModel::RECEIVED;
                    $line->received_on = $on ?: now()->toDateString();
                    $line->updated_by = Auth::id();
                    $line->save();
                }

                $file->syncPaperPendency();

                $names = $group->map(fn ($line) => $line->paperType->name)->all();
                $still = collect($file->paperChecklist())->where('state', WorkFilePaperModel::PENDING)->pluck('name')->all();

                $file->logStatus($folderWas, self::papersRemark(false, ['received' => $names], $still), null, self::PAPERS);

                $done[$fileId] = ['file_no' => $file->file_no, 'papers' => $names];
            }

            return $done;
        });
    }

    /**
     * Which of these files cannot go out yet, and why: file id => reason.
     *
     * Papers not yet checked, or checked and still waiting on something. Asked
     * of the database rather than of the page, because the page may have been
     * open since before a paper arrived — or went missing.
     *
     * @param  array<int, int>  $fileIds
     * @return array<int, string>
     */
    /**
     * The papers still to come on each file, named and nothing else.
     *
     * papersNotReady() answers the dispatch gate's question — may this file go
     * out — and says "papers not checked yet" about one nobody has audited,
     * which is an answer to that question and not a list of papers. Screens
     * that want to name what is missing ask this instead.
     *
     * @param  array<int, int>  $fileIds
     * @return array<int, string>  "Form 30, NOC"
     */
    public static function pendingPaperNames(array $fileIds): array
    {
        if (! $fileIds) {
            return [];
        }

        return self::query()
            ->whereIn('id', $fileIds)
            ->whereRaw(self::PENDING_PAPERS)
            ->select('id')
            ->selectRaw(self::PENDING_PAPER_NAMES.' as pending_papers')
            ->get()
            ->mapWithKeys(fn ($file) => [$file->id => $file->pending_papers])
            ->filter()
            ->all();
    }

    public static function papersNotReady(array $fileIds, array $jobIds = []): array
    {
        if (! $fileIds) {
            return [];
        }

        /*
         * Narrowed to the works going out, when some of them are.
         *
         * A folder can hold a transfer whose papers are complete and a
         * hypothecation addition still waiting on a form. Sending the
         * transfer is not sending that form out incomplete, and a refusal
         * about work that is staying here is one people learn to click past.
         */
        $only = $jobIds ? ' AND ppw.id IN ('.implode(',', array_map('intval', $jobIds)).')' : '';
        $pending = str_replace('AND ppw.status NOT IN', $only.' AND ppw.status NOT IN', self::PENDING_PAPERS);
        $names = str_replace('AND ppnw.status NOT IN',
            ($jobIds ? ' AND ppnw.id IN ('.implode(',', array_map('intval', $jobIds)).')' : '').' AND ppnw.status NOT IN',
            self::PENDING_PAPER_NAMES);
        $audit = str_replace('AND nai.status NOT IN',
            ($jobIds ? ' AND nai.id IN ('.implode(',', array_map('intval', $jobIds)).')' : '').' AND nai.status NOT IN',
            self::NEEDS_AUDIT);

        return self::query()
            ->whereIn('id', $fileIds)
            ->where(fn ($q) => $q->whereRaw($audit)->orWhereRaw($pending))
            ->select('id')
            ->selectRaw($audit.' as needs_audit')
            ->selectRaw($names.' as pending_papers')
            ->get()
            ->mapWithKeys(fn ($file) => [$file->id => $file->needs_audit
                ? 'papers not checked yet'
                : 'papers pending: '.$file->pending_papers])
            ->all();
    }

    /**
     * The same question asked of each work rather than of the folder.
     *
     * The handover screen ticks works one at a time now, so the warning has to
     * sit where the tick is: a folder whose transfer is ready and whose
     * hypothecation addition is still waiting on a form should say so against
     * the addition, not across the whole row. Asked of the folder, the screen
     * would demand a reason the server no longer wants — papersNotReady()
     * narrows to the works going out, and the two have to agree, or the
     * operator is made to explain something nobody objected to.
     *
     * Returns [work id => ['why' => 'to_check'|'pending', 'papers' => ?string]],
     * holding only the works that are not ready. Kept in pieces rather than as a
     * sentence: the screen writes one wording into a badge and another into the
     * tooltip beside it, and splitting a sentence back up in a template is how
     * the two drift apart. Finished work is left out — approved, returned and
     * cancelled work has nothing left to be checked for.
     *
     * @param  array<int, int>  $fileIds
     * @return array<int, array{why: string, papers: ?string}>
     */
    public static function papersNotReadyByJob(array $fileIds): array
    {
        if (! $fileIds) {
            return [];
        }

        $finished = [self::APPROVED, self::RETURNED, self::CANCELLED];

        /*
         * Not looked at yet, and with something to look for. A work type with
         * no paper list, or one whose papers have all been retired, is not held
         * up by an audit that could never find anything — the same exemption
         * NEEDS_AUDIT makes.
         */
        $toCheck = DB::table('work_file_item as i')
            ->whereIn('i.work_file_id', $fileIds)
            ->whereNull('i.papers_audited_at')
            ->whereNotIn('i.status', $finished)
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('work_type_paper as t')
                ->join('paper_type as p', 'p.id', '=', 't.paper_type_id')
                ->where('p.is_active', 1)
                ->whereColumn('t.work_type_id', 'i.work_type_id'))
            ->pluck('i.id');

        /*
         * Still waiting on a paper. One line serves every work that needs it —
         * an RC covers the hypothecation removal and the transfer alike — so
         * the pivot is what says which works a pending paper actually holds up.
         */
        $pending = DB::table('work_file_paper as p')
            ->join('paper_type as pt', 'pt.id', '=', 'p.paper_type_id')
            ->join('work_file_paper_item as pi', 'pi.work_file_paper_id', '=', 'p.id')
            ->join('work_file_item as i', 'i.id', '=', 'pi.work_file_item_id')
            ->whereIn('p.work_file_id', $fileIds)
            ->where('p.state', WorkFilePaperModel::PENDING)
            ->whereNotIn('i.status', $finished)
            ->groupBy('pi.work_file_item_id')
            // Selected and aliased rather than plucked: a raw expression given to
            // pluck() is read back as a column of that name, and the name is the
            // SQL.
            ->selectRaw('pi.work_file_item_id as item_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT pt.name ORDER BY pt.sort, pt.name SEPARATOR ', ') as names")
            ->get();

        $why = [];

        foreach ($pending as $row) {
            $why[(int) $row->item_id] = ['why' => 'pending', 'papers' => $row->names];
        }

        /*
         * Written after, and over, what is missing. The pending list on a work
         * nobody has checked yet is not the whole answer — there may be papers
         * on it that have not been asked for at all.
         */
        foreach ($toCheck as $itemId) {
            $why[(int) $itemId] = ['why' => 'to_check', 'papers' => null];
        }

        return $why;
    }

    /**
     * Move each unfinished work in or out of Paper Pendency to match its papers.
     *
     * A work with a paper pending is in Paper Pendency. A work in Paper
     * Pendency with nothing pending goes back to where it would be without the
     * papers holding it: with its vendor if the file has been given to one, in
     * the office otherwise. Finished work is not touched.
     *
     * Returns whether anything moved; the folder is rolled up and saved if so.
     */
    public function syncPaperPendency(): bool
    {
        $pendingFor = DB::table('work_file_paper_item')
            ->join('work_file_paper', 'work_file_paper.id', '=', 'work_file_paper_item.work_file_paper_id')
            ->where('work_file_paper.work_file_id', $this->id)
            ->where('work_file_paper.state', WorkFilePaperModel::PENDING)
            ->pluck('work_file_paper_item.work_file_item_id')
            ->unique();

        $moved = false;
        $items = $this->items()->get();

        /*
         * Where a work goes when its papers are in: to File Dispatch if it is
         * out with a vendor — its own, or on an older folder the folder's —
         * and back to the office if not. Found on 2026-09-28: asked of the
         * folder's vendor, work the office kept on a folder a vendor had part
         * of went to File Dispatch, which nobody had it for; and so did work
         * the vendor had already handed back (found in review).
         */
        $older = self::isOlderFolder($items);
        $isOut = fn ($item) => ($item->vendor_id && ! $item->vendor_returned_on)
            || ($older && $this->vendor_id && ! $this->vendor_returned_on);

        foreach ($items as $item) {
            if ($item->isSettled()) {
                continue;
            }

            $pending = $pendingFor->contains($item->id);

            /*
             * Only work still on the desk is moved into pendency.
             *
             * A missing paper is a reason not to send a file out. It is not a
             * description of a file that has already gone: work given to a
             * vendor on an override was being dragged straight back out of File
             * Dispatch, and work at the RTO would have been pulled back from
             * under verification by a paper ticked in the office. Where the
             * work has got to and which papers are in are two different
             * questions, and the checklist only answers the second.
             */
            if ($pending && $item->status === self::IN_OFFICE) {
                $item->status = self::PAPER_PENDENCY;
            } elseif (! $pending && $item->status === self::PAPER_PENDENCY) {
                $item->status = $isOut($item) ? self::DISPATCHED : self::IN_OFFICE;
            } else {
                continue;
            }

            $item->save();
            $moved = true;
        }

        if ($moved) {
            $this->load('items');
            $this->rollUp();
            $this->save();
            $this->syncLedger();
        }

        return $moved;
    }

    /**
     * What a checklist save says on the file's history — and on the customer's.
     * Paper names only: the notes belong to their lines.
     *
     * Short enough to fit, always. It used to list every name, into a column of
     * 255 characters, and a transfer with eleven papers still to come ran to
     * nearly three hundred: the database refused the line, and the save it was
     * describing went down with it. The checklist the office had just filled in
     * was lost because the note about it ran long.
     *
     * So a list that will not fit names what it can and counts the rest — "and 8
     * more" — trying a roomier version first and a tighter one only when it has
     * to. The whole list is still one click away on the checklist itself, which
     * is where anybody who needs all eleven names goes to read them.
     */
    private static function papersRemark(bool $firstLook, array $moved, array $stillPending): string
    {
        $build = function (int $room) use ($firstLook, $moved, $stillPending): string {
            $parts = [];

            if ($firstLook) {
                $parts[] = 'Papers checked.';
            }

            if (! empty($moved['received']) && ! $firstLook) {
                $parts[] = 'Received: '.self::listedWithin($moved['received'], $room).'.';
            }

            if (! empty($moved['not_needed']) && ! $firstLook) {
                $parts[] = 'Not needed: '.self::listedWithin($moved['not_needed'], $room).'.';
            }

            $parts[] = $stillPending
                ? 'Pending: '.self::listedWithin($stillPending, $room).'.'
                : 'All papers received.';

            return implode(' ', $parts);
        };

        // From roomiest to tightest in small steps, so the line keeps every name
        // it has space for rather than jumping straight to a stub.
        foreach (range(self::REMARK_LIMIT, 30, -15) as $room) {
            $remark = $build($room);

            if (mb_strlen($remark) <= self::REMARK_LIMIT) {
                return $remark;
            }
        }

        // Three long lists at once and still over: logStatus() has the last word.
        return $remark;
    }

    /**
     * As many of these names as fit in $room characters, and a count of the rest.
     *
     * Always at least one name, so a line never reads "Pending: and 11 more".
     * In the order given, which is the checklist's own order — the first names
     * on it are the ones the office looks for first.
     */
    private static function listedWithin(array $names, int $room): string
    {
        $names = array_values($names);
        $shown = [];

        foreach ($names as $i => $name) {
            $left = count($names) - $i - 1;
            $tail = $left ? ' and '.$left.' more' : '';
            $trying = implode(', ', [...$shown, $name]);

            if ($shown && mb_strlen($trying.$tail) > $room) {
                break;
            }

            $shown[] = $name;
        }

        $more = count($names) - count($shown);

        return implode(', ', $shown).($more ? ' and '.$more.' more' : '');
    }

    // ------------------------------------------------------------- handing over

    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    /** Whether the papers have gone back to the customer. */
    public function isHandedOver(): bool
    {
        return $this->handed_over_on !== null;
    }

    /**
     * Approved files whose papers are still in the office.
     *
     * Fully approved only. On a partly approved file the work still pending
     * usually still needs the papers, and handing them over would be recording
     * that the office no longer has what it is working from.
     */
    public function scopeAwaitingHandover($query)
    {
        return $query
            ->where('work_file.status', self::APPROVED)
            ->whereNull('work_file.handed_over_on');
    }

    /** For the Hand Over Papers screen: longest-waiting first, like any list of things to chase. */
    public static function readyForHandover()
    {
        return self::query()
            ->with('workType', 'customer', 'items.workType')
            ->awaitingHandover()
            ->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Record the papers going back to the customer.
     *
     * No money moves and no status changes. Approved work is finished work the
     * customer pays for in full; handing back what it was done on is a
     * delivery, not a refund — which is the whole reason this is not Return to
     * Customer. So there is no ledger call here, and a test holds it to that.
     */
    public function handOver(string $on, ?string $collectedBy = null, ?string $remark = null): void
    {
        $this->handed_over_on = $on;
        $this->handed_over_by = Auth::id();
        $this->collected_by = ($collectedBy = trim((string) $collectedBy)) !== '' ? $collectedBy : null;
        $this->save();

        $this->logStatus($this->status, $remark, null, self::HANDED_OVER);
    }

    /**
     * Take back a handover recorded by mistake.
     *
     * Kept on the office's history, with the reason, because a record that
     * silently changed is worse than one that says it was corrected. The
     * customer's history drops both — see withoutUndoneHandovers().
     */
    public function undoHandover(string $remark): void
    {
        $this->handed_over_on = null;
        $this->handed_over_by = null;
        $this->collected_by = null;
        $this->save();

        $this->logStatus($this->status, $remark, null, self::HANDOVER_UNDONE);
    }

    /**
     * Prices on this file that the form would change, written out.
     *
     * Only figures already agreed count. Filling in a blank — or a nought,
     * which is how this application says "not priced yet" — is agreeing a
     * price for the first time, not changing one, and asking the office to
     * justify it would make the box noise to be clicked past.
     *
     * @param  array<int|string, array<string, mixed>>  $corrections  items[<id>] from the form
     * @return array<int, string>  "TR charged 5,000.00 → 6,000.00"
     */
    public function priceChanges(array $corrections, $customerAmount = null, $vendorAmount = null): array
    {
        $said = [];

        $moved = function ($was, $now): bool {
            // Not agreed yet: a blank or a nought is not a price to change.
            if ($was === null || (float) $was <= 0) {
                return false;
            }

            $now = ($now === '' || $now === null) ? null : (float) $now;

            return $now === null || abs((float) $was - $now) > 0.005;
        };

        $written = fn ($value) => ($value === '' || $value === null)
            ? 'nothing'
            : number_format((float) $value, 2, '.', ',');

        $items = $this->items()->with('workType')->get();

        if ($corrections) {
            foreach ($items as $item) {
                $correction = $corrections[$item->id] ?? null;

                if (! $correction) {
                    continue;
                }

                $work = $item->workType?->name ?? 'work';

                if ($moved($item->customer_amount, $correction['customer_amount'] ?? null)) {
                    $said[] = $work.' charged '.$written($item->customer_amount).' → '.$written($correction['customer_amount'] ?? null);
                }

                if ($moved($item->vendor_amount, $correction['vendor_amount'] ?? null)) {
                    $said[] = $work.' vendor rate '.$written($item->vendor_amount).' → '.$written($correction['vendor_amount'] ?? null);
                }
            }

            return $said;
        }

        /*
         * A file of one work is priced in the boxes above the table, which write
         * through to it. The same two questions, asked of the folder.
         */
        if ($moved($this->customer_amount, $customerAmount)) {
            $said[] = 'Charged '.$written($this->customer_amount).' → '.$written($customerAmount);
        }

        if ($moved($this->vendor_amount, $vendorAmount)) {
            $said[] = 'Vendor rate '.$written($this->vendor_amount).' → '.$written($vendorAmount);
        }

        return $said;
    }

    /**
     * How long a file has been out, counted from the day it was given to the
     * vendor — the question the office asks of anything sitting at the RTO.
     *
     * Counted only while the work is still out. On a file that is approved,
     * returned or cancelled the number would keep climbing after the thing it
     * measured had finished, which is not an age but a mistake waiting to be
     * read as one.
     */
    public static function daysOut($vendorDate, ?string $status = null): ?int
    {
        if (! $vendorDate || in_array($status, [self::APPROVED, self::RETURNED, self::CANCELLED], true)) {
            return null;
        }

        $days = (int) floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($vendorDate)))) / 86400);

        return max(0, $days);
    }

    /**
     * Work the office is doing itself and has not finished, oldest first.
     *
     * One row per work rather than per folder, because in-house is a fact about
     * a work: a folder can have its transfer with a vendor and its termination
     * being done at this counter, and only the second belongs on this list.
     *
     * Only work somebody has said is in-house — kept_in_house_on — and never
     * work that merely has no vendor. Most of that is waiting to be given out,
     * which is what Give to Vendor lists; putting it here too would fill the
     * office's own to-do list with work it is about to hand to somebody else.
     *
     * Oldest first by the day the papers came in, because that is how long the
     * customer has been waiting, and so the order the work wants doing in.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function inHouseWork()
    {
        return DB::table('work_file_item as i')
            ->join('work_file as f', 'f.id', '=', 'i.work_file_id')
            ->join('work_type as t', 't.id', '=', 'i.work_type_id')
            ->join('party as c', 'c.id', '=', 'f.customer_id')
            ->whereNotNull('i.kept_in_house_on')
            /*
             * Not given to anybody since. Changing a folder's vendor on the
             * edit screen reaches every work on it and leaves this mark where
             * it was, so the vendor is what decides it.
             */
            ->whereNull('i.vendor_id')
            ->whereNotIn('i.status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            // A cancelled folder takes its work with it.
            ->where('f.status', '<>', self::CANCELLED)
            ->orderBy('f.received_date')
            ->orderBy('f.id')
            ->orderBy('i.id')
            ->get([
                'i.id',
                'i.status',
                'i.customer_amount',
                'i.kept_in_house_on',
                'i.approved_on',
                'i.approval_screenshot',
                'f.id as file_id',
                'f.file_no',
                'f.registration_no',
                'f.received_date',
                't.name as work',
                'c.id as customer_id',
                'c.name as customer',
            ])
            ->map(function ($work) {
                $days = max(0, (int) floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($work->received_date)))) / 86400));

                return [
                    'id' => (int) $work->id,
                    'file_id' => (int) $work->file_id,
                    'file_no' => $work->file_no,
                    'edit_url' => route('workfile.edit', $work->file_id),
                    'registration_no' => $work->registration_no,
                    'customer' => $work->customer,
                    'customer_id' => (int) $work->customer_id,
                    'customer_url' => route('party.statement', $work->customer_id),
                    'work' => $work->work,
                    'received' => date('d-m-Y', strtotime($work->received_date)),
                    // Sorted on rather than shown, for the reason every date is.
                    'received_raw' => date('Y-m-d', strtotime($work->received_date)),
                    'days' => $days,
                    'days_text' => $days === 0 ? 'today' : ($days === 1 ? '1 day' : $days.' days'),
                    'kept_on' => date('d-m-Y', strtotime($work->kept_in_house_on)),
                    'kept_raw' => date('Y-m-d', strtotime($work->kept_in_house_on)),
                    'kept_text' => 'kept in-house '.date('d-m-Y', strtotime($work->kept_in_house_on)),
                    'status' => self::STATUSES[$work->status] ?? $work->status,
                    // Coloured the way the status board colours it.
                    'status_key' => $work->status,
                    'charged' => (float) $work->customer_amount,

                    /*
                     * For the Update dialog, which is the Work Report's: whose
                     * file it is for its heading, and the one work this row is.
                     * Only this one — the rest of the folder may be with a
                     * vendor, and is moved from where that is looked after.
                     */
                    'party_name' => $work->customer,
                    'items' => [[
                        'id' => (int) $work->id,
                        'work_type' => $work->work,
                        'status' => $work->status,
                        'status_label' => self::STATUSES[$work->status] ?? $work->status,
                        'approved_on_iso' => $work->approved_on ? date('Y-m-d', strtotime($work->approved_on)) : null,
                        // Whether there is evidence already, never where it is.
                        'has_screenshot' => (bool) $work->approval_screenshot,
                    ]],
                    'update' => 'Update',
                ];
            });
    }

    /**
     * Files that are done and not paid for, oldest first.
     *
     * The rows behind a customer's outstanding balance. Which files a payment
     * covered is nowhere on the receipt, so PartyLedgerModel::outstandingByFile
     * settles the oldest charge first and this reads the answer back against
     * the files themselves.
     *
     * Finished work only unless asked for everything: a file received
     * yesterday is not money nobody collected, it is work in progress. A
     * cancelled file is charged nothing and never appears.
     */
    public static function uncollected($customerId = null, bool $includeRunning = false)
    {
        $customers = PartyModel::where('party_type', 'customer')
            ->when($customerId, fn ($q) => $q->where('id', $customerId))
            ->pluck('id')
            ->all();

        $owed = PartyLedgerModel::outstandingByFile($customers);

        $fileIds = [];

        foreach ($owed as $files) {
            $fileIds = array_merge($fileIds, array_keys($files));
        }

        if (! $fileIds) {
            return collect();
        }

        $files = DB::table('work_file')
            ->join('party as customer', 'customer.id', '=', 'work_file.customer_id')
            ->whereIn('work_file.id', $fileIds)
            ->when(! $includeRunning, fn ($q) => $q->whereIn('work_file.status', [self::APPROVED, self::RETURNED]))
            ->select(
                'work_file.id',
                'work_file.file_no',
                'work_file.registration_no',
                'work_file.status',
                'work_file.customer_amount',
                'work_file.received_date',
                'work_file.handed_over_on',
                DB::raw(self::FINISHED_ON.' as finished_on'),
                'customer.id as customer_id',
                'customer.name as customer_name',
                'customer.mobile as customer_mobile',
                'customer.whatsapp as customer_whatsapp'
            )
            ->get();

        /*
         * The works on each file by name, for the message a customer is sent
         * from this report: "BR01AB1234 — TR, HPA" is how they know it. A
         * cancelled work was charged nothing and is not what they are owed for.
         */
        $works = DB::table('work_file_item as i')
            ->join('work_type as t', 't.id', '=', 'i.work_type_id')
            ->whereIn('i.work_file_id', $files->pluck('id')->all())
            ->where('i.status', '<>', self::CANCELLED)
            ->orderBy('i.id')
            ->get(['i.work_file_id', 't.name'])
            ->groupBy('work_file_id');

        return $files->map(function ($file) use ($owed, $works) {
            $outstanding = (float) ($owed[$file->customer_id][$file->id] ?? 0);

            /*
             * Counted from the day the work finished, which is when the office
             * can fairly ask to be paid. Work still running is counted from the
             * day the papers came in, because nothing else has happened yet.
             */
            $since = $file->finished_on ?: $file->received_date;
            $days = max(0, (int) floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($since)))) / 86400));

            return [
                'id' => (int) $file->id,
                'file_no' => $file->file_no,
                'edit_url' => route('workfile.edit', $file->id),
                'registration_no' => $file->registration_no,
                'customer' => $file->customer_name,
                'customer_id' => (int) $file->customer_id,
                'customer_url' => route('party.statement', $file->customer_id),
                // Where a reminder is sent. Blank WhatsApp means "the mobile",
                // as it does on the statement.
                'customer_mobile' => $file->customer_whatsapp ?: $file->customer_mobile,
                'works' => ($works[$file->id] ?? collect())->pluck('name')->implode(', '),
                'finished' => $file->finished_on ? date('d-m-Y', strtotime($file->finished_on)) : null,
                // Sorted on rather than shown, for the reason every date is.
                'finished_raw' => $file->finished_on ? date('Y-m-d', strtotime($file->finished_on)) : null,
                'days' => $days,
                'days_text' => $days === 0 ? 'today' : ($days === 1 ? '1 day' : $days.' days'),
                'handed_over' => $file->handed_over_on ? date('d-m-Y', strtotime($file->handed_over_on)) : 'With the office',
                'handed_over_raw' => $file->handed_over_on ? date('Y-m-d', strtotime($file->handed_over_on)) : '',
                'charged' => (float) $file->customer_amount,
                'outstanding' => $outstanding,
                // A file half paid for reads as unpaid unless the row says so.
                'part_paid' => $outstanding < (float) $file->customer_amount - 0.005
                    ? 'part paid'
                    : null,
                'status' => $file->status,
            ];
        })
            // Longest owed first: the list is read to decide who to ring.
            ->sortByDesc('days')
            ->values();
    }
    /**
     * Each vendor, and how long their work takes.
     *
     * The office hands a batch of files to whoever it trusts to be quick, and
     * until now "who is quick" was whatever somebody remembered. This counts
     * it: how much of their work is still out, how long the oldest of it has
     * been waiting, and how long the work they finished actually took.
     *
     * The period is the day the work was given out, so a row follows one batch
     * of work through rather than mixing files sent this month with files
     * finished this month.
     *
     * Cancelled work is in the file count and in neither of the others: it
     * never finished, and it is not waiting either. In-house work has no vendor
     * to judge and is left out altogether.
     *
     * Counted per work, a vendor's part of each folder at a time. Asked for by
     * the owner on 2026-09-28, with the rule that anything vendor-wise counts
     * only the works given to that vendor: counted by the folder, a folder a
     * vendor held part of stayed "out" with them while the office finished its
     * own work, and took them until the office's last approval; a folder split
     * between two, naming neither, was nobody's. Now:
     *
     *  - a folder counts once under each vendor with work on it (their "part");
     *  - it is out with them while any work of theirs is still at a stage, sent,
     *    and not handed back — the oldest such work is how long they are waiting;
     *  - it is finished when nothing of theirs is out and some of it ended while
     *    they had it: approved, or back to the customer with the folder;
     *  - a work handed back undone is not their finish, as on Approval Time — nor
     *    is a given work later cancelled; both count in their files only;
     *  - they took from the first of those works going out to the last ending;
     *  - the period is the day the first of their works on it went out;
     *  - on a folder none of whose works ever named a vendor, the folder's own
     *    vendor has all of it, dated from the folder.
     *
     * Nothing correlated: the older test is a derived table joined in, so the
     * grouping is MariaDB-safe at every level.
     */
    public static function vendorPerformance(?string $from = null, ?string $to = null)
    {
        $open = "'".implode("','", self::OPEN_STATUSES)."'";
        $approved = self::APPROVED;
        $returned = self::RETURNED;

        // Folders on which some work, cancelled included, names a vendor.
        $carrying = DB::table('work_file_item')->whereNotNull('vendor_id')->distinct()->select('work_file_id');
        $older = 'carrying.work_file_id IS NULL';
        // A folder with no works stands for itself.
        $status = 'COALESCE(i.status, work_file.status)';

        // One row per work given to a vendor, and per work of an older folder.
        $works = DB::table('work_file')
            ->leftJoin('work_file_item as i', 'i.work_file_id', '=', 'work_file.id')
            ->leftJoinSub($carrying, 'carrying', 'carrying.work_file_id', '=', 'work_file.id')
            ->where(fn ($q) => $q->whereNotNull('i.vendor_id')
                ->orWhere(fn ($old) => $old->whereNull('carrying.work_file_id')->whereNotNull('work_file.vendor_id')))
            ->selectRaw("CASE WHEN $older THEN work_file.vendor_id ELSE i.vendor_id END as vendor_id")
            ->selectRaw('work_file.id as work_file_id')
            ->selectRaw("$status as status")
            ->selectRaw("CASE WHEN $older THEN work_file.vendor_date ELSE i.vendor_date END as vendor_date")
            ->selectRaw("CASE WHEN $older THEN work_file.vendor_returned_on ELSE i.vendor_returned_on END as vendor_returned_on")
            ->selectRaw("CASE WHEN $status = '$approved' THEN i.approved_on
                              WHEN $status = '$returned' AND work_file.status = '$returned' THEN work_file.returned_on END as ended_on");

        $out = "w.status IN ($open) AND w.vendor_date IS NOT NULL AND w.vendor_returned_on IS NULL";
        $done = 'w.ended_on IS NOT NULL AND (w.vendor_returned_on IS NULL OR w.vendor_returned_on >= w.ended_on)';

        // One row per vendor per folder: their part of it.
        $shares = DB::query()->fromSub($works, 'w')
            ->select('w.vendor_id', 'w.work_file_id')
            ->selectRaw('MIN(w.vendor_date) as first_sent')
            ->selectRaw("MAX(CASE WHEN $out THEN 1 ELSE 0 END) as is_out")
            ->selectRaw("MIN(CASE WHEN $out THEN w.vendor_date END) as out_since")
            ->selectRaw("MIN(CASE WHEN $done THEN w.vendor_date END) as sent")
            ->selectRaw("MAX(CASE WHEN $done THEN w.ended_on END) as ended")
            ->groupBy('w.vendor_id', 'w.work_file_id');

        /*
         * Counted from the finishing day where there is one, and never where it
         * falls before the day the work went out — papers dated backwards are a
         * typo, and averaging one in would quietly drag a vendor's figure down.
         */
        $took = 'CASE WHEN s.is_out = 0 AND DATEDIFF(s.ended, s.sent) >= 0 THEN DATEDIFF(s.ended, s.sent) END';
        $stillOut = 'CASE WHEN s.is_out = 1 THEN DATEDIFF(CURDATE(), s.out_since) END';

        // One row per vendor.
        return DB::query()->fromSub($shares, 's')
            ->join('party as vendor', 'vendor.id', '=', 's.vendor_id')
            // Booked but never sent is not out with anybody.
            ->whereNotNull('s.first_sent')
            ->when($from, fn ($q) => $q->whereDate('s.first_sent', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('s.first_sent', '<=', $to))
            ->select('s.vendor_id', 'vendor.name as vendor_name')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('SUM(s.is_out) as out_now')
            ->selectRaw("MAX($stillOut) as longest_out")
            ->selectRaw("COUNT($took) as finished")
            ->selectRaw("ROUND(AVG($took)) as average_days")
            ->selectRaw("MAX($took) as slowest")
            ->groupBy('s.vendor_id', 'vendor.name')
            // Whoever has most still out is who the office is waiting on.
            ->orderByDesc(DB::raw("MAX($stillOut)"))
            ->orderBy('vendor.name')
            ->get();
    }
    /**
     * The day this file's work finished, worked out in PHP.
     *
     * The twin of FINISHED_ON, for the screens that hold Eloquent rows rather
     * than a listing query. It reads the jobs already loaded with the file
     * where it can, so a board of fifty files is still one query.
     */
    public function finishedOn(): ?string
    {
        if ($this->status === self::RETURNED) {
            return $this->returned_on;
        }

        if ($this->status !== self::APPROVED) {
            return null;
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return $items->max('approved_on');
    }

    /**
     * Who the folder's work was given to, in the space a list gives: each
     * vendor's name in work order — "Sharma, Shailendra" — and "+ in-house"
     * where a live work was given to nobody. Null where none is out with
     * anybody. An older folder, none of whose works ever named a vendor, is
     * its own vendor's.
     *
     * Asked for by the owner on 2026-09-28, with the rule that anything
     * vendor-wise counts only the works given to that vendor: the folder's
     * vendor named a folder a vendor held part of as wholly theirs, and a
     * folder split between two — naming neither — as In-house.
     *
     * @param  array<int, string>  $names  vendor id => name
     */
    public static function givenTo($works, $folderVendorId, ?string $folderVendorName, array $names): ?string
    {
        $works = collect($works);

        if (self::isOlderFolder($works)) {
            return $folderVendorId ? $folderVendorName : null;
        }

        $standing = self::standing($works);
        $ids = $standing->pluck('vendor_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return null;
        }

        $named = $ids->map(fn ($id) => $names[$id] ?? 'Unknown vendor')->implode(', ');

        return $standing->contains(fn ($work) => ! $work->vendor_id) ? $named.' + in-house' : $named;
    }

    /**
     * The day the folder's work first went out: the earliest of its live
     * given works, or, on an older folder, the folder's own. Null where
     * nothing is out with anybody — a given work since cancelled has no day.
     */
    public static function givenOutOn($works, $folderVendorDate): ?string
    {
        $works = collect($works);

        if (self::isOlderFolder($works)) {
            return $folderVendorDate ?: null;
        }

        return self::standing($works)->filter(fn ($work) => $work->vendor_id)->pluck('vendor_date')->filter()->min() ?: null;
    }

    /** The live works — or, struck off whole, all of them, so it still says who had it. */
    private static function standing($works)
    {
        $live = collect($works)->reject(fn ($work) => $work->status === self::CANCELLED);

        return $live->isEmpty() ? collect($works) : $live;
    }

    /**
     * What the board's heading says of this folder for one holder — or, for
     * none, of the folder: where it stands, who has it, when it went out and
     * when it was through.
     *
     * @return array{status: string, vendor: ?string, given_on: ?string, finished_on: ?string}
     */
    public function partFor(?int $holder): array
    {
        if ($holder === null) {
            return [
                'status' => $this->status,
                'vendor' => self::givenTo($this->items, $this->vendor_id, $this->vendor?->name,
                    $this->items->mapWithKeys(fn ($item) => $item->vendor_id ? [(int) $item->vendor_id => $item->vendor?->name] : [])->all()),
                'given_on' => self::givenOutOn($this->items, $this->vendor_date),
                'finished_on' => $this->finishedOn(),
            ];
        }

        $mine = $this->worksHeldBy($holder);
        $status = self::boardStatus($mine, $this->status);

        return [
            'status' => $status,
            'vendor' => $holder ? ($mine->first()?->vendor?->name ?? $this->vendor?->name) : null,
            'given_on' => $holder
                ? (self::isOlderFolder($this->items) ? $this->vendor_date : self::standing($mine)->pluck('vendor_date')->filter()->min())
                : null,
            'finished_on' => match ($status) {
                self::APPROVED => $mine->pluck('approved_on')->filter()->max(),
                self::RETURNED => $this->returned_on,
                default => null,
            },
        ];
    }

    /**
     * How long the work took, for a file that is over.
     *
     * The same span the office watches while a file is out, stopped on the day
     * it finished. It is the number a vendor is judged on, and it is the reason
     * these columns stop being blank once the work is done.
     */
    public static function turnaround($vendorDate, $finishedOn): ?int
    {
        if (! $vendorDate || ! $finishedOn) {
            return null;
        }

        $days = (int) floor((strtotime(date('Y-m-d', strtotime($finishedOn)))
            - strtotime(date('Y-m-d', strtotime($vendorDate)))) / 86400);

        // Papers dated before they were sent are a typo, not a negative
        // turnaround. Nothing is claimed about a file that says so.
        return $days < 0 ? null : $days;
    }

    /**
     * The days line under a dispatch date: how long it has been out, or how
     * long it took.
     *
     * Both are the same span and both belong in the same place, so the reader
     * is told which one they are looking at rather than being left to work it
     * out from the status: "6 days" is still running, "took 6 days" is done.
     */
    public static function daysOutText($vendorDate, ?string $status = null, $finishedOn = null): ?string
    {
        $days = self::daysOut($vendorDate, $status);

        if ($days === null) {
            $took = self::turnaround($vendorDate, $finishedOn);

            return match (true) {
                $took === null => null,
                $took === 0 => 'took the same day',
                $took === 1 => 'took 1 day',
                default => 'took '.$took.' days',
            };
        }

        return match (true) {
            $days === 0 => 'today',
            $days === 1 => '1 day',
            default => $days.' days',
        };
    }

    /** "Papers handed over 16-09-2026", or null. */
    public static function handoverText($handedOverOn): ?string
    {
        return $handedOverOn ? 'Papers handed over '.date('d-m-Y', strtotime($handedOverOn)) : null;
    }

    /**
     * Log rows as the customer should read them.
     *
     * A handover recorded by mistake and taken back never happened, as far as
     * the customer is concerned — so the handover goes, and so does the entry
     * taking it back, whose reason ("wrong file") is the office's business.
     * Paired per file, in order: handed over, undone, handed over again leaves
     * the second standing.
     *
     * @param  iterable<object>  $rows  each with work_file_id and event, oldest first
     * @return array<int, object>
     */
    private static function withoutUndoneHandovers(iterable $rows): array
    {
        $kept = [];
        $standing = [];

        foreach ($rows as $row) {
            $file = $row->work_file_id;

            // Why the office sent a file out early, or moved a price, is the
            // office's business.
            if (in_array($row->event, self::OFFICE_ONLY_EVENTS, true)) {
                continue;
            }

            if ($row->event === self::HANDOVER_UNDONE) {
                if (isset($standing[$file])) {
                    unset($kept[$standing[$file]]);
                    unset($standing[$file]);
                }

                continue;
            }

            $kept[] = $row;

            if ($row->event === self::HANDED_OVER) {
                $standing[$file] = array_key_last($kept);
            }
        }

        return array_values($kept);
    }

    /**
     * The most recent remark against this file, for showing inline on a list
     * without loading the whole timeline.
     */
    public function latestRemark(): ?string
    {
        return $this->statusLog()->whereNotNull('remark')->value('remark');
    }

    /**
     * Latest remark per file for a set of files, in one query.
     *
     * @param  array<int, int>  $fileIds
     * @return array<int, string>
     */
    public static function latestRemarks(array $fileIds): array
    {
        if (! $fileIds) {
            return [];
        }

        // The newest logged remark for each file: rank by id descending within
        // the file, then keep the first. Doing this per row would be one query
        // per file on a board that routinely shows dozens.
        $rows = DB::table('work_file_status_log as log')
            ->whereIn('log.work_file_id', $fileIds)
            ->whereNotNull('log.remark')
            ->whereRaw('log.id = (select max(inner_log.id) from work_file_status_log as inner_log
                where inner_log.work_file_id = log.work_file_id and inner_log.remark is not null)')
            ->pluck('log.remark', 'log.work_file_id');

        return $rows->all();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusBadge(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'bg-secondary';
    }

    /**
     * What this file earned: what the customer was charged less what the vendor
     * is owed for doing it. A file with no vendor is all margin.
     */
    public function margin(): float
    {
        return self::netCustomer($this->status, $this->customer_amount, $this->returned_amount)
            - self::netVendor($this->status, $this->vendor_amount, $this->isReturnedByVendor(), $this->vendor_returned_amount)
            // What the office paid out of its own till on this file. Read from
            // the database rather than from a loaded relation, so a margin is
            // never quietly too high because nobody eager-loaded the expenses.
            - $this->paidOut();
    }

    /** The office's own outlay on this file. */
    public function paidOut(): float
    {
        if (! $this->exists) {
            return 0.0;
        }

        return (float) WorkFileExpenseModel::where('work_file_id', $this->id)->sum('amount');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(WorkFileExpenseModel::class, 'work_file_id');
    }

    /** Papers scanned against this file, newest last. */
    public function documents(): HasMany
    {
        return $this->hasMany(WorkFileDocumentModel::class, 'work_file_id');
    }

    /**
     * What a file actually earns from its customer, once its status is taken
     * into account. Cancelled never charged them; returned charged them and gave
     * it straight back. Either way the business is left with nothing.
     */
    public static function netCustomer(?string $status, $amount, $returnedAmount = null): float
    {
        if ($status === self::CANCELLED) {
            return 0.0;
        }

        // A part refund leaves the business holding the difference.
        if ($status === self::RETURNED) {
            return (float) $amount - self::returnedPortion($returnedAmount, $amount);
        }

        return (float) $amount;
    }

    /**
     * What a file actually costs in vendor charges.
     *
     * A customer return is not excluded here: giving the papers back to the
     * customer does not undo work a vendor has already been booked for, so that
     * cost stands and the file shows a loss — which is the true position.
     *
     * A vendor return is different. The vendor handed the file back undone, so
     * the booking was reversed and the file costs nothing.
     */
    public static function netVendor(?string $status, $amount, bool $returnedByVendor = false, $reversedAmount = null): float
    {
        if ($status === self::CANCELLED) {
            return 0.0;
        }

        // A part reversal leaves the vendor still owed the difference.
        if ($returnedByVendor) {
            return (float) $amount - self::returnedPortion($reversedAmount, $amount);
        }

        // Part of it back and the rest still out: what has come back so far.
        if ($reversedAmount !== null && $reversedAmount !== '') {
            return (float) $amount - min((float) $reversedAmount, (float) $amount);
        }

        return (float) $amount;
    }

    /**
     * Where approval screenshots live, relative to public/.
     *
     * Under public/ rather than storage/app/public because symlink() is disabled
     * on the production host, so php artisan storage:link cannot run and nothing
     * under storage/ would ever be reachable by a browser.
     */
    public const UPLOAD_DIR = 'uploads/approvals';

    /**
     * Papers scanned against a file, as opposed to the evidence that one work
     * on it came through. Kept apart so a listing of either is a listing of
     * one kind of thing.
     */
    public const DOC_DIR = 'uploads/documents';

    /**
     * Store an approval screenshot against this file, replacing any earlier one.
     *
     * The stored name is derived from the file number and a hash, never from the
     * uploaded name — a browser-supplied name is attacker-controlled and would
     * otherwise decide where in the filesystem this lands.
     */
    /**
     * Put an uploaded document where approvals live, and return its path.
     *
     * Shared by files and by the jobs on them, because approval evidence now
     * belongs to the job — two approvals days apart, a document each — and
     * both need the same care about extensions and about not deleting what
     * they replace until the row that replaced it has committed.
     *
     * @param  string|null  $previous  a path this one replaces
     */
    public static function storeUpload(UploadedFile $upload, ?string $previous = null, string $prefix = 'item', string $dir = self::UPLOAD_DIR): string
    {
        $directory = public_path($dir);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Guessed from the content, never taken from the browser: this
        // directory is web-served and what lands in it should not depend on a
        // validation rule elsewhere staying exactly as it is.
        $extension = strtolower($upload->extension() ?: 'bin');
        // The prefix is stripped of anything that is not plainly a file name,
        // because it comes from data even if that data is ours.
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix) ?: 'item';
        $name = $safe.'-'.substr(md5(uniqid('', true)), 0, 12).'.'.$extension;

        $upload->move($directory, $name);
        $stored = $dir.'/'.$name;

        // The one it replaces goes only once the row pointing at the new path
        // has safely committed, or a rollback leaves evidence deleted and a
        // row still naming it.
        if ($previous && $previous !== $stored) {
            DB::afterCommit(function () use ($previous) {
                $path = public_path($previous);

                if (is_file($path)) {
                    unlink($path);
                }
            });
        }

        return $stored;
    }

    public function storeScreenshot(UploadedFile $upload): void
    {
        $directory = public_path(self::UPLOAD_DIR);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        /*
         * The extension is guessed from the file's actual content, never taken
         * from the browser. The validation rule does cross-check the two today,
         * so a .php name is already rejected — but this directory is web-served,
         * and what lands in it should not depend on a validation rule elsewhere
         * staying exactly as it is.
         */
        $extension = strtolower($upload->extension() ?: 'bin');

        $name = $this->file_no.'-'.substr(md5((string) $this->id.microtime()), 0, 8).'.'.$extension;

        $previous = $this->approval_screenshot;

        $upload->move($directory, $name);

        $this->approval_screenshot = self::UPLOAD_DIR.'/'.$name;

        /*
         * The file it replaces goes only once the row that replaced it has
         * safely committed.
         *
         * Deleting it inline meant a transaction that rolled back afterwards
         * left the row pointing at a path that no longer existed — the approval
         * evidence for that file simply gone, with the database none the wiser.
         * Outside a transaction afterCommit runs immediately, so this is
         * correct on either path.
         */
        if ($previous && $previous !== $this->approval_screenshot) {
            DB::afterCommit(function () use ($previous) {
                $path = public_path($previous);

                if (is_file($path)) {
                    unlink($path);
                }
            });
        }
    }

    /**
     * Remove the stored screenshot file, if there is one. Only ever called for a
     * path this application wrote.
     */
    public function deleteScreenshot(): void
    {
        if (! $this->approval_screenshot) {
            return;
        }

        $path = public_path($this->approval_screenshot);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /*
     * There was a screenshotUrl() here, returning url($this->approval_screenshot)
     * — the path under public/, which any browser will serve to anyone holding
     * it, signed in or not.
     *
     * It is gone rather than left unused. Every screen now asks for
     * route('workfile.approval') or route('customer.file.approval'), both of
     * which decide who is asking; a helper sitting here handing out the
     * unguarded address is the one that gets reached for next time.
     */

    /**
     * The running number shown to the user. Derived from the id rather than a
     * counter of its own, so it can never collide or leave gaps that look like
     * missing files.
     */
    public function generateFileNo(): string
    {
        return 'F-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * What this file is called on a statement.
     *
     * Every work it is still charged for, then the vehicle those papers are
     * for. Naming it by the folder's own work type is how an eleven thousand
     * rupee entry covering a hypothecation termination, a transfer and a
     * hypothecation addition came to read "HPA" — true of a fifth of it, and
     * no help at all to a customer checking what they were billed.
     *
     * Cancelled work is left out, because it is not in the figure beside it.
     *
     * Read through a fresh query rather than the loaded relation: an edit that
     * changed the work leaves the cached copy saying what it used to be.
     */
    /**
     * @param  array<int, string>|null  $only  the works this line is for, where
     *                                         it is for some of them: a vendor
     *                                         given the transfer and not the
     *                                         hypothecation is owed for the
     *                                         transfer, and his statement has
     *                                         to say so or two lines against
     *                                         one file number are the same line
     *                                         twice.
     */
    public function ledgerParticular(?array $only = null, bool $withDetails = true): string
    {
        $works = $only ? implode(', ', $only) : $this->items()->with('workType')->get()
            ->reject(fn ($item) => $item->status === self::CANCELLED)
            ->map(fn ($item) => $item->workType?->name)
            ->filter()
            ->unique()
            ->implode(', ');

        // A file with nothing left to charge for still has to be called
        // something, and its own type is all it has.
        $parts = [$works !== '' ? $works : ($this->workType()->value('name') ?? 'Work')];

        /*
         * The registration, then anything else written on the file. Skipped
         * when it repeats what is already there: files taken in before there
         * was a registration field have the number in the description, and
         * "BR01DD1234 - BR01DD1234" helps nobody.
         */
        foreach ([$this->registration_no, $withDetails ? $this->description : null] as $detail) {
            $detail = trim((string) $detail);

            if ($detail !== '' && ! in_array($detail, $parts, true)) {
                $parts[] = $detail;
            }
        }

        return implode(' - ', $parts);
    }

    /**
     * What a vendor's line for this file says: the works and the vehicle, and
     * nothing typed on the file.
     *
     * The owner's rule is that a vendor is never told a customer's name or
     * money, and a vendor's statement is printed and sent to them. The file's
     * details are typed at the counter — the form asks for the party's name —
     * so they stay on the customer's line and never reach the vendor's. Found
     * in the vendor-side sweep: they did, word for word.
     *
     * @param  array<int, string>|null  $only  the works this vendor was given
     */
    public function vendorParticular(?array $only = null): string
    {
        return $this->ledgerParticular($only, false);
    }

    /**
     * What this file's line on one vendor's statement says, as syncVendors()
     * writes it: the whole folder's works for a vendor with all of it, only
     * their own for a vendor with part of a split folder.
     */
    public function vendorParticularFor(int $vendorId): string
    {
        $share = $this->vendorShares()[$vendorId] ?? null;

        return $share && ! $share['all'] ? $this->vendorParticular($share['works']) : $this->vendorParticular();
    }
    /**
     * Bring this file's ledger entries in line with the file as it now stands.
     *
     * Called after every save. Rather than appending a correction, it updates the
     * entries the file already owns, so editing a mistyped amount leaves one
     * correct entry instead of a pair that has to be read together to work out
     * what was actually charged.
     */
    public function syncLedger(): void
    {
        $particular = $this->ledgerParticular();

        // Cancelling withdraws every side. Passing nulls rather than short-circuiting
        // means the same removal path runs as when a vendor is unassigned, and
        // un-cancelling puts the entries back with no separate restore logic.
        $cancelled = $this->isCancelled();

        // The customer is charged for the work: debit.
        $this->syncSide(
            'customer',
            'debit',
            $cancelled ? null : $this->customer_id,
            $this->customer_amount,
            $this->received_date,
            $particular
        );

        /*
         * The vendors are owed for doing the work: a credit each. Dated from
         * when their part of it was handed over, falling back to the day the
         * file came in.
         *
         * One line per vendor, because a folder is one job to the customer and
         * several to the office, and the office does not send them all to the
         * same person. A folder that did go to one vendor writes the one line
         * it always wrote, to the same party, with the same words on it.
         */
        $this->syncVendors();

        // Papers returned: give the customer their money back, in full, as a
        // credit that sits beside the original charge. Setting any other status
        // removes it again, so correcting a mis-set status needs no undo.
        $this->syncSide(
            'customer_return',
            'credit',
            $this->isReturned() ? $this->customer_id : null,
            $this->refundToCustomer(),
            $this->returned_on ?: $this->received_date,
            $particular.' - papers returned'
        );

        // The mirror image on the vendor side — they handed the file back, so
        // what was booked to them is reversed with a debit beside the credit —
        // is written by syncVendors above, vendor by vendor.
    }

    /**
     * Who has this folder, in the space a list gives for one name.
     *
     * A folder split between vendors has no single one, and its own column is
     * null — so a list would call it in-house work, which is the one thing it
     * is not.
     */
    public function vendorLabel(): ?string
    {
        if ($this->vendor_id) {
            return $this->vendor?->name;
        }

        $vendors = $this->items()->whereNotNull('vendor_id')
            ->where('status', '<>', self::CANCELLED)
            ->distinct()->count('vendor_id');

        return $vendors > 1 ? $vendors.' vendors' : null;
    }

    /**
     * A folder from before works carried a vendor of their own: none of its
     * works, cancelled ones included, ever named one. Only then is the
     * folder's own vendor the vendor of all of it.
     *
     * Asked of every work, never the live ones alone. Found on 2026-09-28: a
     * vendor whose one given work was cancelled read, by the live works, as
     * the vendor of an older folder — and was credited the rate of the work
     * the office kept, named on its statement, and chased for its price.
     *
     * The one definition; see also olderFolderSql() and OLDER_FOLDER.
     */
    public static function isOlderFolder($works): bool
    {
        return collect($works)->every(fn ($work) => ! $work->vendor_id);
    }

    /** isOlderFolder(), as SQL, of the row called work_file. */
    public const OLDER_FOLDER = 'NOT EXISTS (SELECT 1 FROM work_file_item AS ever_given
        WHERE ever_given.work_file_id = work_file.id AND ever_given.vendor_id IS NOT NULL)';

    /** isOlderFolder(), as SQL, of a folder by another name. */
    public static function olderFolderSql(string $folder = 'work_file'): string
    {
        return str_replace('work_file.id', $folder.'.id', self::OLDER_FOLDER);
    }

    /**
     * Who the folder is with, as its works say it — or null where they cannot
     * say, and what is written on the folder stands.
     *
     * One vendor while the live works given out agree, none while they do not.
     * The date is the earliest of theirs; the folder is back when every one of
     * them is, on the day the last came.
     *
     * With no live work given out: an older folder, or one struck off whole,
     * keeps what is written — who had it. One whose given work was cancelled,
     * the rest never given to anybody, is with nobody now.
     *
     * @return array{vendor_id: ?int, vendor_date: ?string, vendor_returned_on: ?string}|null
     */
    public static function vendorFromWorks($works): ?array
    {
        $works = collect($works);
        $live = $works->reject(fn ($work) => $work->status === self::CANCELLED);
        $out = $live->filter(fn ($work) => $work->vendor_id);

        if ($out->isEmpty()) {
            return $live->isNotEmpty() && ! self::isOlderFolder($works)
                ? ['vendor_id' => null, 'vendor_date' => null, 'vendor_returned_on' => null]
                : null;
        }

        $vendors = $out->pluck('vendor_id')->unique();

        return [
            'vendor_id' => $vendors->count() === 1 ? (int) $vendors->first() : null,
            'vendor_date' => $out->pluck('vendor_date')->filter()->min() ?: null,
            'vendor_returned_on' => $out->contains(fn ($work) => ! $work->vendor_returned_on)
                ? null
                : $out->pluck('vendor_returned_on')->filter()->max(),
        ];
    }

    /**
     * What each vendor on this folder is owed for it, and when.
     *
     * The money follows the work: a vendor with two of the three jobs is owed
     * for two of them, dated from the day those two went out. Cancelled work
     * counts for nobody, exactly as it counts for nothing on the customer side.
     *
     * Where their works have come back: the day the last of them did, how
     * much of their rates came back with them, and whether all of theirs is
     * back. A vendor who handed back one work and still has another is owed
     * for the one they still have — not, as when a part could only be written
     * on the folder, for both until the second came back too.
     *
     * @return array<int, array{amount: float, date: ?string, works: array<int, string>, all: bool, returned_on: ?string, reversed: float, all_back: bool}>
     */
    public function vendorShares(): array
    {
        $live = $this->items()->with('workType')->get()
            ->reject(fn ($item) => $item->status === self::CANCELLED);

        $shares = [];

        foreach ($live as $item) {
            if (! $item->vendor_id) {
                continue;
            }

            $vendor = (int) $item->vendor_id;
            $shares[$vendor] ??= ['amount' => 0.0, 'date' => null, 'works' => [], 'all' => false, 'returned_on' => null, 'reversed' => 0.0, 'all_back' => false, 'back_works' => []];

            $shares[$vendor]['amount'] += (float) $item->vendor_amount;
            $shares[$vendor]['works'][] = $item->workType?->name ?? 'work';

            // The day their part of it went out: the earliest, where a vendor
            // was given two works on different days.
            if ($item->vendor_date && (! $shares[$vendor]['date'] || $item->vendor_date < $shares[$vendor]['date'])) {
                $shares[$vendor]['date'] = $item->vendor_date;
            }

            if ($item->vendor_returned_on) {
                if ($item->vendor_returned_on > (string) $shares[$vendor]['returned_on']) {
                    $shares[$vendor]['returned_on'] = $item->vendor_returned_on;
                }

                $shares[$vendor]['reversed'] += self::returnedPortion(
                    WorkFileItemModel::partReversals() ? $item->vendor_returned_amount : null,
                    $item->vendor_amount
                );
                $shares[$vendor]['back_works'][] = $item->workType?->name ?? 'work';
            }
        }

        foreach ($shares as $vendor => $share) {
            $theirs = $live->where('vendor_id', $vendor);

            $shares[$vendor]['all'] = $theirs->count() === $live->count();
            $shares[$vendor]['works'] = array_values(array_unique($share['works']));
            $shares[$vendor]['all_back'] = $theirs->every(fn ($item) => (bool) $item->vendor_returned_on);
            $shares[$vendor]['reversed'] = round($share['reversed'], 2);
            $shares[$vendor]['back_works'] = array_values(array_unique($share['back_works']));
        }

        return $shares;
    }

    /**
     * The lines this folder should have on its vendors' statements: one
     * credit per vendor, and one reversal each where their work has come back.
     *
     * What syncVendors() writes, and what files:audit and files:resync-vendors
     * check the ledger against — one answer, so the three cannot disagree.
     *
     * An older folder — no work ever named a vendor — writes its own vendor,
     * exactly as before. Any other folder writes what its live works say, and
     * nothing where no live work is given out: a vendor whose one given work
     * was cancelled is owed nothing for the work the office kept.
     *
     * @return array{vendor: array<int, array{amount: float, date: ?string, says: string}>, vendor_return: array<int, array{amount: float, date: ?string, says: string}>}
     */
    public function vendorLines(): array
    {
        $lines = ['vendor' => [], 'vendor_return' => []];

        if ($this->isCancelled()) {
            return $lines;
        }

        // Never the file's typed details; see vendorParticular().
        $particular = $this->vendorParticular();

        if (self::isOlderFolder($this->items()->get())) {
            $folder = $this->vendor_id ? (int) $this->vendor_id : null;

            if ($folder && (float) $this->vendor_amount > 0) {
                $lines['vendor'][$folder] = [
                    'amount' => (float) $this->vendor_amount,
                    'date' => $this->vendor_date ?: $this->received_date,
                    'says' => $particular,
                ];
            }

            if ($folder && $this->vendor_returned_on && $this->reversedToVendor() > 0) {
                $lines['vendor_return'][$folder] = [
                    'amount' => $this->reversedToVendor(),
                    'date' => $this->vendor_returned_on,
                    'says' => $particular.' - returned by vendor',
                ];
            }

            return $lines;
        }

        $shares = $this->vendorShares();

        /*
         * A rate agreed on the folder and never written down onto its works.
         *
         * The screens all write the rate to the work and let the folder sum it,
         * so this is the older shape rather than a current one — but the folder
         * is what the ledger read until now, and a file carrying its rate only
         * there would have its vendor's credit silently drop to nothing. Only
         * where the whole folder is one vendor's: found on 2026-09-28, a vendor
         * holding part of it was credited, under his own work's name, the rate
         * typed on a work the office kept.
         */
        $fromFolder = false;

        if (count($shares) === 1) {
            $only = array_key_first($shares);

            if ($shares[$only]['all'] && $shares[$only]['amount'] <= 0 && (float) $this->vendor_amount > 0) {
                $shares[$only]['amount'] = (float) $this->vendor_amount;
                $fromFolder = true;
            }
        }

        foreach ($shares as $vendor => $share) {
            /*
             * Named when they have only part of the folder: a vendor's
             * statement has to say which job the money is for, or two lines
             * against one file number are indistinguishable. A vendor with all
             * of it reads exactly as it always did.
             */
            $says = $share['all'] ? $particular : $this->vendorParticular($share['works']);

            if ($share['amount'] > 0) {
                $lines['vendor'][$vendor] = [
                    'amount' => (float) $share['amount'],
                    'date' => $share['date'] ?: $this->received_date,
                    'says' => $says,
                ];
            }

            /*
             * What came back of their works' rates, work by work — a part
             * reversal typed on the take-back screen is written on the works
             * it was for. A rate agreed on the folder alone comes back as the
             * folder's figure, once all of theirs is back.
             *
             * Until the works can carry a part (the migration has not run), as
             * before: the folder's figure for a vendor with all of it, their
             * whole share for one with part, once all of theirs is back.
             */
            if ($share['returned_on']) {
                $reversed = match (true) {
                    ! WorkFileItemModel::partReversals() => $share['all_back']
                        ? ($share['all'] ? $this->reversedToVendor() : (float) $share['amount'])
                        : 0.0,
                    $fromFolder => $share['all_back'] ? $this->reversedToVendor() : 0.0,
                    default => $share['reversed'],
                };

                if ($reversed > 0) {
                    $lines['vendor_return'][$vendor] = [
                        'amount' => $reversed,
                        'date' => $share['returned_on'],
                        // Naming the works that came back, where some of
                        // theirs has not: found in review, a statement read
                        // as though work they still had was handed back.
                        'says' => ($share['all_back'] ? $says : $this->vendorParticular($share['back_works'])).' - returned by vendor',
                    ];
                }
            }
        }

        return $lines;
    }

    /**
     * Writes vendorLines() to the ledger: the lines it names, updated in
     * place, and every other vendor line of this folder's cleared — a line on
     * a statement nobody is owed is money the office thinks it has to pay.
     *
     * Public so files:resync-vendors can put a folder's vendor lines right
     * without touching its customer's.
     */
    public function syncVendors(): void
    {
        $lines = $this->vendorLines();

        foreach (['vendor' => 'credit', 'vendor_return' => 'debit'] as $role => $side) {
            $this->clearRole($role, array_keys($lines[$role]));

            foreach ($lines[$role] as $vendor => $line) {
                $this->syncSide($role, $side, $vendor, $line['amount'], $line['date'], $line['says'], true);
            }
        }
    }

    /**
     * Entries written under a role to parties this folder no longer owes.
     *
     * A work moved from one vendor to another leaves the first one's line
     * behind, and a line on a statement nobody is owed is money the office
     * thinks it has to pay.
     *
     * @param  array<int, int|null>  $keep
     */
    private function clearRole(string $role, array $keep): void
    {
        PartyLedgerModel::where('work_file_id', $this->id)
            ->where('file_role', $role)
            ->whereNotIn('party_id', array_filter($keep) ?: [0])
            ->delete();
    }

    /**
     * How much goes back to the customer when the papers are returned.
     *
     * Null means the whole charge, which is both the commonest case and what
     * every file recorded before part refunds existed meant.
     */
    public function refundToCustomer(): float
    {
        return self::returnedPortion($this->returned_amount, $this->customer_amount);
    }

    /**
     * How much of the vendor's booking is reversed when they hand the file back.
     */
    public function reversedToVendor(): float
    {
        return self::returnedPortion($this->vendor_returned_amount, $this->vendor_amount);
    }

    /**
     * A return amount worth storing, or null for "all of it": the forms
     * pre-fill the whole figure, and storing it would be a change where
     * nothing changed.
     */
    public static function partOrAll($value, $whole): ?float
    {
        if ($value === null || $value === '' || (float) $value >= (float) $whole) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * A part reversal typed for one vendor's works, shared over them: the
     * works not yet approved first — the ones that came back undone — then
     * the rest, each up to its own rate. Null on a work is all of its rate.
     *
     * @return array<int, ?float> work id => the part of its rate reversed
     */
    public static function reversalOnWorks($works, $typed): array
    {
        $works = collect($works);
        $booked = $works->sum(fn ($work) => (float) $work->vendor_amount);

        if ($typed === null || $typed === '' || (float) $typed >= $booked) {
            return $works->mapWithKeys(fn ($work) => [(int) $work->id => null])->all();
        }

        $left = round((float) $typed, 2);
        $parts = [];

        foreach ($works->sortBy(fn ($work) => [$work->status === self::APPROVED ? 1 : 0, (int) $work->id]) as $work) {
            $rate = max(0.0, (float) $work->vendor_amount);
            $take = round(min($left, $rate), 2);
            $left = round($left - $take, 2);

            $parts[(int) $work->id] = abs($take - $rate) < 0.005 ? null : $take;
        }

        return $parts;
    }

    /**
     * The folder's own part reversal, as its works say it: what came back of
     * the rates of its given works that were handed back.
     *
     * Once the folder is back, null is all of its rate and a figure is that
     * much — a rate typed on work the office kept is not the vendor's, and is
     * not reversed with theirs. While some of it is still out, a figure is
     * what has come back so far, and null is nothing yet.
     *
     * A rate agreed on the folder alone, its works carrying none, keeps the
     * folder's own figure; see vendorLines().
     */
    public function reversalFromWorks($items = null): ?float
    {
        $back = collect($items ?? $this->items()->get())
            ->reject(fn ($work) => $work->status === self::CANCELLED)
            ->filter(fn ($work) => $work->vendor_id && $work->vendor_returned_on);

        if ($back->isEmpty()) {
            return null;
        }

        $reversed = round($back->sum(fn ($work) => self::returnedPortion($work->vendor_returned_amount, $work->vendor_amount)), 2);

        if ($this->vendor_returned_on) {
            /*
             * Only where the rate lives on the folder alone: one vendor has
             * every live work and none of them carries a rate. Found in review:
             * a vendor handing back work nobody had priced took the rate typed
             * on the office's own work back with it — the folder read all of
             * its rate reversed, and a file that cost 800 cost nothing.
             */
            $live = collect($items ?? $this->items()->get())->reject(fn ($work) => $work->status === self::CANCELLED);
            $folderRate = $live->isNotEmpty()
                && $live->every(fn ($work) => $work->vendor_id && (int) $work->vendor_id === (int) $live->first()->vendor_id)
                && $live->sum(fn ($work) => (float) $work->vendor_amount) <= 0;

            if ($folderRate) {
                return $this->vendor_returned_amount === null ? null : (float) $this->vendor_returned_amount;
            }

            return self::partOrAll($reversed, $this->vendor_amount);
        }

        return $reversed > 0 ? $reversed : null;
    }

    /**
     * What each vendor still has of this folder: their live works not yet
     * handed back, nor gone back to the customer. On an older folder, the
     * folder's vendor has all of it until the folder is back.
     *
     * @return array<int, \Illuminate\Support\Collection> vendor id => works
     */
    public function heldByVendor(): array
    {
        $live = $this->items->reject(fn ($work) => $work->status === self::CANCELLED)->values();

        if (self::isOlderFolder($this->items)) {
            return $this->vendor_id && ! $this->vendor_returned_on ? [(int) $this->vendor_id => $live] : [];
        }

        return $live
            ->filter(fn ($work) => $work->vendor_id && ! $work->vendor_returned_on && $work->status !== self::RETURNED)
            ->groupBy(fn ($work) => (int) $work->vendor_id)
            ->map(fn ($works) => $works->values())
            ->all();
    }

    /**
     * The most a take-back of these works can reverse: their rates — or the
     * folder's, on an older folder, or where the rate was agreed on the
     * folder alone and they are all of it; see vendorLines().
     */
    public function bookedFor(int $vendorId, $works): ?float
    {
        $works = collect($works);

        if (self::isOlderFolder($this->items)) {
            return $this->vendor_amount === null ? null : (float) $this->vendor_amount;
        }

        $priced = $works->filter(fn ($work) => $work->vendor_amount !== null);
        $rates = round($priced->sum(fn ($work) => (float) $work->vendor_amount), 2);
        $live = $this->items->reject(fn ($work) => $work->status === self::CANCELLED);

        if ($rates <= 0 && (float) $this->vendor_amount > 0 && $live->every(fn ($work) => (int) $work->vendor_id === $vendorId)) {
            return (float) $this->vendor_amount;
        }

        return $priced->isEmpty() ? null : $rates;
    }

    /**
     * A part return, or the whole amount when none was specified. Never more
     * than was charged in the first place — the forms reject that, and this
     * makes a stale or hand-edited row safe too.
     */
    private static function returnedPortion($portion, $whole): float
    {
        if ($portion === null || $portion === '') {
            return (float) $whole;
        }

        return min((float) $portion, (float) $whole);
    }

    /**
     * The papers came back from the vendor without the work being done.
     */
    public function isReturnedByVendor(): bool
    {
        return (bool) $this->vendor_returned_on;
    }

    /**
     * Files currently out with a vendor and not yet returned — what the return
     * screen offers, and the same conditions are re-applied on save so a stale
     * page cannot return a file twice.
     */
    public static function withVendor(?array $ids = null)
    {
        return self::query()
            ->with('workType', 'customer', 'vendor', 'items.workType', 'items.vendor')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            /*
             * A folder with any work still out, rather than a folder with a
             * vendor. A folder split between two of them has no vendor of its
             * own, and half of it coming back does not bring the other half.
             *
             * Or the folder itself, when that is where the vendor is written. A
             * folder with no works at all is handed over whole and has nowhere
             * else to carry one, and the screens that still set a vendor on the
             * folder direct leave its works empty-handed — asked of the works
             * alone, both would drop off this screen with the vendor still
             * holding the papers. Only an older folder, though: one whose only
             * given work was cancelled has nothing out with anybody.
             */
            ->where(fn ($outer) => $outer
                ->whereHas('items', fn ($q) => $q->whereNotNull('vendor_id')
                    ->whereNull('vendor_returned_on')
                    ->whereNotIn('status', [self::CANCELLED, self::RETURNED]))
                ->orWhere(fn ($own) => $own->whereNotNull('vendor_id')
                    ->whereNull('vendor_returned_on')
                    ->whereRaw(self::OLDER_FOLDER)))
            /*
             * Everything the vendor is still holding, whatever state the work
             * is in. An approved file is the one that comes back — they got the
             * approval and are handing the papers over — and it used to be the
             * single status this would not offer.
             *
             * Cancelled is out: the file charges nobody and is owed for by
             * nobody, so there is no booking to reverse. So is a file already
             * returned to its customer — the papers passed through this desk on
             * their way there, so they came back from the vendor first.
             */
            ->whereNotIn('status', [self::CANCELLED, self::RETURNED])
            /*
             * Newest out first, at the office's request. It was the oldest —
             * the one the vendor has had longest — and the days beside each
             * date now say that outright, which is what the order stood for.
             */
            ->orderBy('vendor_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * One side of the file's ledger footprint.
     *
     * Keyed on file_role, not entry_type: a returned file credits its customer
     * and a vendor-assigned file credits its vendor, so entry_type alone can no
     * longer say which entry is which. The role also survives the party changing,
     * which is what lets an edit move a file to a different vendor by updating
     * the existing entry instead of stranding it.
     */
    private function syncSide(string $role, string $entryType, $partyId, $amount, $date, string $particular, bool $perParty = false): void
    {
        /*
         * A role with one party is found by the role alone, so a file moved to
         * another customer rewrites the line it already has rather than leaving
         * the old one behind. A folder split between two vendors owes both of
         * them under the role "vendor", so those are found by the party too —
         * and the ones no longer owed are cleared by clearRole first.
         */
        $entry = PartyLedgerModel::where('work_file_id', $this->id)
            ->where('file_role', $role)
            ->when($perParty && $partyId, fn ($q) => $q->where('party_id', $partyId))
            ->first();

        // No party, or nothing to charge yet: a vendor can be assigned before the
        // price is agreed, and that must not put a zero-value line on a statement.
        if (! $partyId || (float) $amount <= 0) {
            if ($entry) {
                $entry->delete();
            }

            return;
        }

        if (! $entry) {
            $entry = new PartyLedgerModel;
            $entry->work_file_id = $this->id;
            $entry->file_role = $role;
            $entry->entry_type = $entryType;
        }

        $entry->party_id = $partyId;
        $entry->txn_date = $date;
        $entry->amount = (float) $amount;
        $entry->payment_mode = self::LEDGER_MODE;
        $entry->ref_no = $this->file_no;
        $entry->particular = $particular;
        $entry->save();
    }

    /**
     * What a file earned, as SQL.
     *
     * The arms mirror netCustomer(): a file that charged nobody must not
     * inflate anything, and a returned one earned the part that was not given
     * back. LEAST() mirrors returnedPortion()'s cap — a refund can never exceed
     * what was charged, however the row got that way.
     */
    public const EARNED = "CASE
        WHEN work_file.status = 'cancelled' THEN 0
        WHEN work_file.status = 'paper_returned'
            THEN work_file.customer_amount
                 - LEAST(COALESCE(work_file.returned_amount, work_file.customer_amount), work_file.customer_amount)
        ELSE work_file.customer_amount END";

    /**
     * What the office paid out of its own till on a file.
     *
     * Added to the cost wherever cost is asked for, so every margin in this
     * application counts it without each screen remembering to.
     *
     * Not zeroed on a cancelled file, which is the one place it parts company
     * with the vendor figure above. A cancelled file charged nobody and owes no
     * vendor, so both of those are nothing — but a challan paid before it was
     * cancelled is money that actually left, and a report that quietly forgets
     * it is the report this whole feature exists to stop.
     */
    public const PAID_OUT = "COALESCE((SELECT SUM(work_file_expense.amount)
        FROM work_file_expense
        WHERE work_file_expense.work_file_id = work_file.id), 0)";

    /**
     * The day the work on a file finished, or null while it is still running.
     *
     * An approved file finished on the day its last job was approved — a folder
     * of three is not through until the third one is. A returned file finished
     * when it came back. A cancelled one has no such day: it stopped rather
     * than finished, and dressing that up as a turnaround would put a number
     * against work nobody did.
     */
    public const FINISHED_ON = "(CASE
        WHEN work_file.status = 'paper_returned' THEN work_file.returned_on
        WHEN work_file.status = 'approval_done' THEN (SELECT MAX(fin.approved_on)
            FROM work_file_item fin WHERE fin.work_file_id = work_file.id)
        ELSE NULL END)";

    /** And what it cost, mirroring netVendor() the same way. */
    public const SPENT = "(CASE
        WHEN work_file.status = 'cancelled' THEN 0
        WHEN work_file.vendor_returned_on IS NOT NULL
            THEN COALESCE(work_file.vendor_amount, 0)
                 - LEAST(COALESCE(work_file.vendor_returned_amount, COALESCE(work_file.vendor_amount, 0)),
                         COALESCE(work_file.vendor_amount, 0))
        WHEN work_file.vendor_returned_amount IS NOT NULL
            THEN COALESCE(work_file.vendor_amount, 0)
                 - LEAST(work_file.vendor_returned_amount, COALESCE(work_file.vendor_amount, 0))
        ELSE COALESCE(work_file.vendor_amount, 0) END
        + ".self::PAID_OUT.')';

    /**
     * A work on this file (as vrs) that was given to a vendor and has no rate
     * for it yet, as the condition on it. The one definition every screen that
     * chases a vendor's rate asks.
     *
     * Given to a vendor means it names one — or, on a file from before works
     * carried their own vendor, none of its works names one and the folder
     * does. A work nobody was given, done in-house, has no rate to agree and
     * never will. Found by the owner on 2026-09-28: asked of every work on a
     * folder with a vendor, one done in-house kept an approved file, every
     * price on it agreed, under Awaiting Price with no margin for good. And
     * asked only of a folder's own vendor, a folder split between two — which
     * names neither — was never chased for the rate one of them lacked.
     *
     * The older file is asked of every work, cancelled ones included; see
     * isOlderFolder(). Asked of the live ones, a folder whose one given work
     * was cancelled was chased for a vendor's rate on the work the office kept.
     *
     * Not a work its vendor handed back: nothing was agreed for it, and
     * nothing will be (found in review, 2026-09-29) — it sat under Awaiting
     * Price for good, with no margin.
     */
    public const VENDOR_WORK_UNPRICED = "vrs.work_file_id = work_file.id
        AND vrs.status <> 'cancelled'
        AND vrs.vendor_returned_on IS NULL
        AND (vrs.vendor_amount IS NULL OR vrs.vendor_amount <= 0)
        AND (vrs.vendor_id IS NOT NULL
             OR (work_file.vendor_id IS NOT NULL AND ".self::OLDER_FOLDER.'))';

    /**
     * Whether the file is still waiting on a vendor's rate, as SQL: the
     * folder names a vendor and has no figure — and is not back from them —
     * or a work given to one has none.
     */
    public const VENDOR_UNPRICED = "((work_file.vendor_id IS NOT NULL
            AND work_file.vendor_returned_on IS NULL
            AND (work_file.vendor_amount IS NULL OR work_file.vendor_amount <= 0))
        OR EXISTS (SELECT 1 FROM work_file_item AS vrs WHERE ".self::VENDOR_WORK_UNPRICED."))";

    /**
     * Whether a file's margin can be known yet, as SQL.
     *
     * Mirrors awaitingPrice(): settled files are not outstanding, and a folder
     * is asked about along with every live work on it, because a folder half
     * priced totals more than zero and reads as settled.
     */
    public const OUTSTANDING = "(work_file.status NOT IN ('cancelled', 'paper_returned') AND (
        work_file.customer_amount IS NULL OR work_file.customer_amount <= 0
        OR EXISTS (SELECT 1 FROM work_file_item
            WHERE work_file_item.work_file_id = work_file.id
              AND work_file_item.status <> 'cancelled'
              AND (work_file_item.customer_amount IS NULL OR work_file_item.customer_amount <= 0))
        OR ".self::VENDOR_UNPRICED."))";

    /**
     * The ways the profit report can be cut, and what each row is called.
     */
    public const PROFIT_GROUPS = [
        'month' => 'Month',
        'year' => 'Year',
        'work_type' => 'Work type',
        'vendor' => 'Vendor',
        'customer' => 'Customer',
    ];

    /**
     * What was billed, what it cost and what is left, grouped whichever way is
     * being asked.
     *
     * Every figure answers from the expressions above, so this cannot disagree
     * with the dashboard or the files list about the same file — which is what
     * happened twice when each page did its own subtraction.
     *
     * Files still waiting on a price are counted but kept out of the margin: a
     * difference between a figure and a blank is not a margin. Each row says
     * how many it left out, so a total is never read as covering more than it
     * does.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function profitBy(string $group, ?string $from = null, ?string $to = null)
    {
        if ($group === 'work_type') {
            return self::profitByWorkType($from, $to);
        }

        if ($group === 'vendor') {
            return self::profitByVendor($from, $to);
        }

        [$key, $label, $order] = match ($group) {
            'year' => ["DATE_FORMAT(work_file.received_date, '%Y')", "DATE_FORMAT(work_file.received_date, '%Y')", 'group_key desc'],
            'customer' => ['customer.id', 'customer.name', 'billed desc'],
            default => ["DATE_FORMAT(work_file.received_date, '%Y-%m')", "DATE_FORMAT(work_file.received_date, '%b %Y')", 'group_key desc'],
        };

        /*
         * Every file worked out on its own, before anything is grouped.
         *
         * The outstanding test is a correlated subquery against this file, and
         * inside an aggregate under a GROUP BY that is something MySQL allows
         * and MariaDB — which is what the live server runs — refuses. Here
         * there is no grouping for it to sit under, and the grouping below has
         * nothing correlated left in it.
         */
        $each = DB::table('work_file')
            ->selectRaw("$key as group_key")
            ->selectRaw("$label as group_label")
            ->selectRaw(self::EARNED.' as billed')
            ->selectRaw(self::SPENT.' as cost')
            ->selectRaw('CASE WHEN '.self::OUTSTANDING.' THEN 0 ELSE '.self::EARNED.' - ('.self::SPENT.') END as margin')
            ->selectRaw('CASE WHEN '.self::OUTSTANDING.' THEN 1 ELSE 0 END as unpriced');

        if ($group === 'customer') {
            $each->join('party as customer', 'customer.id', '=', 'work_file.customer_id');
        }

        self::betweenDates($each, $from, $to);

        $rows = DB::query()
            ->fromSub($each, 'each_file')
            ->select('group_key', 'group_label')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('COALESCE(SUM(billed), 0) as billed')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->selectRaw('COALESCE(SUM(margin), 0) as margin')
            ->selectRaw('COALESCE(SUM(unpriced), 0) as unpriced')
            ->groupBy('group_key', 'group_label')
            ->orderByRaw($order)
            ->get();

        return self::withGivenUp($rows, $from, $to, $group);
    }

    /**
     * A folder whose live works more than one holder has — two vendors, or a
     * vendor and the office — as SQL, for a WHERE. The office, work given to
     * nobody, counts as one holder; an older folder, whose works carry no
     * vendor, is the folder's vendor's alone.
     */
    private const SHARED_HOLDERS = "(SELECT COUNT(DISTINCT COALESCE(sh.vendor_id, 0))
        FROM work_file_item AS sh
        WHERE sh.work_file_id = work_file.id AND sh.status <> 'cancelled') > 1";

    /**
     * Who has all of a folder that is not shared, as SQL: the folder's own
     * vendor on an older folder, or on one struck off whole — who had it —
     * and otherwise the one vendor on its live works, or nobody (In-house).
     */
    private const SOLE_HOLDER = 'CASE
        WHEN '.self::OLDER_FOLDER.' THEN work_file.vendor_id
        WHEN NOT EXISTS (SELECT 1 FROM work_file_item AS hs
            WHERE hs.work_file_id = work_file.id AND hs.status <> \'cancelled\') THEN work_file.vendor_id
        ELSE (SELECT MAX(hl.vendor_id) FROM work_file_item AS hl
            WHERE hl.work_file_id = work_file.id AND hl.status <> \'cancelled\')
        END';

    /**
     * The vendor cut: each vendor's own works, and the office's under
     * In-house.
     *
     * Asked for by the owner on 2026-09-28, with the rule that anything
     * vendor-wise counts only the works given to that vendor. Grouped by the
     * folder's own vendor, a folder a vendor held part of put its whole charge,
     * cost and margin under them — the office's work with it — and a folder
     * split between two vendors, which names neither, went under In-house.
     *
     * A folder one holder has all of is worked out in SQL, as every other cut
     * is. A shared one is taken apart in PHP, holder by holder, by the same
     * arithmetic the vendor-wise Work Report draws its rows with — see
     * holderRows() — so a vendor's line here is the sum of their rows there.
     * What a folder's parts do not come to of its own figures, if its works
     * and its totals have drifted apart, goes under In-house: every cut of a
     * period still accounts for the same billed and cost.
     *
     * Each holder's margin is kept out only while their own works wait on a
     * price, as the owner chose: a vendor's priced work shows its margin
     * beside the office's unpriced part.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function profitByVendor(?string $from, ?string $to)
    {
        // Ungrouped, each folder alone: the correlated tests sit where
        // MariaDB allows them. Then named, then grouped over plain columns.
        $each = DB::table('work_file')
            ->whereRaw('NOT ('.self::SHARED_HOLDERS.')')
            ->selectRaw(self::SOLE_HOLDER.' as holder_id')
            ->selectRaw(self::EARNED.' as billed')
            ->selectRaw(self::SPENT.' as cost')
            ->selectRaw('CASE WHEN '.self::OUTSTANDING.' THEN 0 ELSE '.self::EARNED.' - ('.self::SPENT.') END as margin')
            ->selectRaw('CASE WHEN '.self::OUTSTANDING.' THEN 1 ELSE 0 END as unpriced');

        self::betweenDates($each, $from, $to);

        $named = DB::query()
            ->fromSub($each, 'each_file')
            ->leftJoin('party as vendor', 'vendor.id', '=', 'each_file.holder_id')
            ->selectRaw('COALESCE(vendor.id, 0) as group_key')
            ->selectRaw("COALESCE(vendor.name, 'In-house') as group_label")
            ->addSelect('each_file.billed', 'each_file.cost', 'each_file.margin', 'each_file.unpriced');

        $rows = DB::query()
            ->fromSub($named, 'each_holder')
            ->select('group_key', 'group_label')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('COALESCE(SUM(billed), 0) as billed')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->selectRaw('COALESCE(SUM(margin), 0) as margin')
            ->selectRaw('COALESCE(SUM(unpriced), 0) as unpriced')
            ->groupBy('group_key', 'group_label')
            ->get()
            ->keyBy(fn ($row) => (int) $row->group_key);

        foreach (self::sharedProfit($from, $to) as $key => $part) {
            $row = $rows->get($key) ?? (object) ['group_key' => $key, 'group_label' => null,
                'files' => 0, 'billed' => 0, 'cost' => 0, 'margin' => 0, 'unpriced' => 0];

            foreach (['files', 'billed', 'cost', 'margin', 'unpriced'] as $figure) {
                $row->$figure = round((float) $row->$figure + $part[$figure], 2);
            }

            $rows->put($key, $row);
        }

        // Vendors who appear only on shared folders, named in one go.
        $unnamed = $rows->filter(fn ($row) => $row->group_label === null)->keys()->filter()->all();
        $names = $unnamed ? DB::table('party')->whereIn('id', $unnamed)->pluck('name', 'id') : collect();

        $rows = $rows->map(function ($row) use ($names) {
            $row->group_label ??= (int) $row->group_key === 0 ? 'In-house' : ($names[(int) $row->group_key] ?? 'Unknown vendor');
            $row->files = (int) $row->files;
            $row->unpriced = (int) $row->unpriced;

            return $row;
        });

        return self::withGivenUp($rows->sortByDesc(fn ($row) => (float) $row->billed)->values(), $from, $to, 'vendor');
    }

    /**
     * The folders a period's vendor cut takes apart, holder by holder: each
     * holder's billed, cost, margin, and whether its own works wait on a
     * price, keyed by vendor id and 0 for the office.
     *
     * @return array<int, array{files: int, billed: float, cost: float, margin: float, unpriced: int}>
     */
    private static function sharedProfit(?string $from, ?string $to): array
    {
        $query = DB::table('work_file')
            ->whereRaw(self::SHARED_HOLDERS)
            ->select(
                'work_file.id', 'work_file.status', 'work_file.customer_amount', 'work_file.returned_amount',
                'work_file.vendor_id', 'work_file.vendor_amount', 'work_file.vendor_date',
                'work_file.vendor_returned_on', 'work_file.vendor_returned_amount'
            )
            ->selectRaw(self::FINISHED_ON.' as finished_on')
            ->selectRaw(self::PAID_OUT.' as expenses')
            ->selectRaw(self::EARNED.' as earned')
            ->selectRaw(self::SPENT.' as spent');

        self::betweenDates($query, $from, $to);

        $folders = $query->get();

        if ($folders->isEmpty()) {
            return [];
        }

        $works = self::liveWorks($folders->pluck('id')->all());
        $out = [];

        $add = function (int $key, array $figures) use (&$out) {
            $out[$key] ??= ['files' => 0, 'billed' => 0.0, 'cost' => 0.0, 'margin' => 0.0, 'unpriced' => 0];

            foreach ($figures as $figure => $amount) {
                $out[$key][$figure] += $amount;
            }
        };

        foreach ($folders as $folder) {
            $billed = 0.0;
            $cost = 0.0;

            foreach (self::holderRows($folder, $works[$folder->id] ?? collect(), true) as $holder => $part) {
                $totals = self::rowTotals($part);

                $add($holder === 'office' ? 0 : (int) $holder, [
                    'files' => 1,
                    'billed' => $totals['billed'],
                    'cost' => $totals['cost'],
                    'margin' => $totals['margin'] ?? 0.0,
                    'unpriced' => $totals['margin'] === null ? 1 : 0,
                ]);

                $billed += $totals['billed'];
                $cost += $totals['cost'];
            }

            // What the parts do not come to of the folder's own figures.
            $left = ['billed' => round((float) $folder->earned - $billed, 2), 'cost' => round((float) $folder->spent - $cost, 2)];

            if (abs($left['billed']) >= 0.005 || abs($left['cost']) >= 0.005) {
                $add(0, $left + ['margin' => $left['billed'] - $left['cost']]);
            }
        }

        return $out;
    }

    /**
     * The live works of some folders, as the vendor-wise report and the
     * vendor cut read them: charge, rate, vendor, dates, standing and what
     * came back of the rate.
     *
     * @param  array<int, int>  $folderIds
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection>
     */
    private static function liveWorks(array $folderIds)
    {
        return DB::table('work_file_item as i')
            ->join('work_type as t', 't.id', '=', 'i.work_type_id')
            ->leftJoin('party as v', 'v.id', '=', 'i.vendor_id')
            ->whereIn('i.work_file_id', $folderIds)
            ->where('i.status', '<>', self::CANCELLED)
            ->orderBy('i.id')
            ->get([
                'i.id', 'i.work_file_id', 'i.vendor_id', 'i.customer_amount', 'i.vendor_amount',
                'i.vendor_date', 'i.vendor_returned_on', 'i.status', 'i.approved_on',
                WorkFileItemModel::partReversals() ? 'i.vendor_returned_amount' : DB::raw('NULL as vendor_returned_amount'),
                't.name as work', 'v.name as vendor_name', 'v.mobile as vendor_mobile', 'v.whatsapp as vendor_whatsapp',
            ])
            ->groupBy('work_file_id');
    }

    /**
     * A period's files, and how many wait on a price: counted once each, for
     * the headings of the vendor cut, whose rows count a shared folder under
     * each holder of it, and of the work type cut, whose rows count works.
     *
     * @return array{files: int, unpriced: int}
     */
    public static function profitFiles(?string $from, ?string $to): array
    {
        $each = DB::table('work_file')->selectRaw('CASE WHEN '.self::OUTSTANDING.' THEN 1 ELSE 0 END as unpriced');

        self::betweenDates($each, $from, $to);

        $count = DB::query()->fromSub($each, 'each_file')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('COALESCE(SUM(unpriced), 0) as unpriced')
            ->first();

        return ['files' => (int) $count->files, 'unpriced' => (int) $count->unpriced];
    }

    /**
     * What the office gave up on its bills, as a row of its own.
     *
     * A customer owes 5,000, pays 4,950, and the 50 is written off: the charge
     * stays as it was — it was the right charge — so every cut of this report
     * goes on reporting 5,000 earned on that file. The 50 is real money not
     * taken, and it belongs here, in the same period as the charge it reduces:
     * dated by the day the papers came in, which is the date every other figure
     * on this report is filtered by.
     *
     * Its own line rather than a column, for the reason counter expenses have
     * one: a discount belongs to a bill, and a bill is not a work type or a
     * vendor. Under any cut, the same figure.
     */
    private static function withGivenUp($rows, ?string $from, ?string $to, string $group = 'month')
    {
        // entry_kind arrives with the reversal migration, the allocation table
        // with the one before it; this reads both.
        if (! PartyLedgerModel::reversible()) {
            return $rows;
        }

        $query = DB::table('party_ledger_allocation as a')
            ->join('party_ledger as e', 'e.id', '=', 'a.entry_id')
            ->join('work_file', 'work_file.id', '=', 'a.work_file_id')
            ->where('e.entry_kind', PartyLedgerModel::WRITEOFF)
            // A write-off taken back released its lines; it gave up nothing.
            ->whereNull('a.released_at');

        self::betweenDates($query, $from, $to);

        /*
         * Cut by period, the discounts are cut by period too, and each lands
         * under the month or year whose margin it corrects. Found in review: a
         * single figure at the foot of twelve months said which months were
         * overstated to nobody.
         */
        $by = match ($group) {
            'month' => "DATE_FORMAT(work_file.received_date, '%Y-%m')",
            'year' => "DATE_FORMAT(work_file.received_date, '%Y')",
            default => null,
        };

        if ($by === null) {
            $total = round((float) $query->sum('a.amount'), 2);

            return $total > 0 ? $rows->push(self::givenUpRow($total)) : $rows;
        }

        $periods = $query->selectRaw("$by as period")
            ->selectRaw('SUM(a.amount) as given')
            ->groupBy('period')
            ->pluck('given', 'period');

        if ($periods->isEmpty()) {
            return $rows;
        }

        // Each period's own, under it; anything whose period has no row of its
        // own — every file of it cancelled, say — at the end rather than lost.
        $out = collect();

        foreach ($rows as $row) {
            $out->push($row);

            if (($given = round((float) ($periods[$row->group_key] ?? 0), 2)) > 0) {
                $out->push(self::givenUpRow($given, $row->group_label));
                $periods->forget($row->group_key);
            }
        }

        foreach ($periods as $period => $given) {
            if (round((float) $given, 2) > 0) {
                $out->push(self::givenUpRow(round((float) $given, 2), (string) $period));
            }
        }

        return $out;
    }

    /** The row itself: a cost that charges nobody, so the margin carries it. */
    private static function givenUpRow(float $total, ?string $period = null): object
    {
        return (object) [
            // Apart from counter expenses, which is 0 on the work type cut.
            'group_key' => -1,
            'group_label' => 'Discounts & write-offs'.($period ? ' · '.$period : ''),
            'note' => 'Given up on a bill; the charge stays as it was',
            'files' => 0,
            'billed' => 0,
            'cost' => $total,
            'margin' => -$total,
            'unpriced' => 0,
        ];
    }
    /**
     * The same question asked of the works rather than the folders.
     *
     * This is what per-work pricing bought: "HPT + TR + HPA" could never say
     * what a transfer earned, because the folder carried one figure for all
     * three. Now each work carries its own.
     *
     * Cancelled work is out — it charges nobody — and so is a cancelled file.
     * A returned file is kept off its works too: a refund is agreed for the
     * folder, and splitting it across the works on it would be inventing a
     * precision nobody recorded. It is on a line of its own instead; see
     * returnedToCustomer().
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function profitByWorkType(?string $from, ?string $to)
    {
        // A work waits on a price the same way a file does, on either side —
        // a vendor's only if it was given to one; see VENDOR_WORK_UNPRICED.
        $outstanding = "(work_file_item.customer_amount IS NULL OR work_file_item.customer_amount <= 0
            OR EXISTS (SELECT 1 FROM work_file_item AS vrs
                WHERE vrs.id = work_file_item.id AND ".self::VENDOR_WORK_UNPRICED."))";

        /*
         * What a work cost: its rate, less what came back of it when its
         * vendor handed it back — each work's own, as SPENT adds them up for
         * the folder. Found on 2026-09-29: the rate was counted whole, and a
         * work handed back cost here what it cost nowhere else. Before the
         * works could carry a part, all of it.
         */
        /*
         * On an older folder the hand-back is the folder's: its figure is
         * shared over its works by their rates (found in review). Before the
         * works could carry a part, as before: the rate whole.
         */
        $cost = WorkFileItemModel::partReversals()
            ? "COALESCE(work_file_item.vendor_amount, 0) - CASE
                WHEN work_file_item.vendor_id IS NOT NULL AND work_file_item.vendor_returned_on IS NOT NULL
                    THEN LEAST(COALESCE(work_file_item.vendor_returned_amount, work_file_item.vendor_amount, 0),
                               COALESCE(work_file_item.vendor_amount, 0))
                WHEN work_file_item.vendor_id IS NULL AND work_file.vendor_returned_on IS NOT NULL
                    AND work_file.vendor_amount > 0 AND ".self::OLDER_FOLDER."
                    THEN ROUND(COALESCE(work_file_item.vendor_amount, 0)
                        * LEAST(COALESCE(work_file.vendor_returned_amount, work_file.vendor_amount), work_file.vendor_amount)
                        / work_file.vendor_amount, 2)
                ELSE 0 END"
            : 'COALESCE(work_file_item.vendor_amount, 0)';

        /*
         * Every work worked out on its own, before anything is grouped — as
         * profitBy() does each file, for the same reason: the outstanding test
         * is a correlated subquery, which MariaDB refuses inside an aggregate
         * under a GROUP BY and MySQL allows.
         */
        $each = DB::table('work_file_item')
            ->join('work_file', 'work_file.id', '=', 'work_file_item.work_file_id')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->where('work_file_item.status', '<>', self::CANCELLED)
            ->whereNotIn('work_file.status', [self::CANCELLED, self::RETURNED])
            ->selectRaw('work_type.id as group_key')
            ->selectRaw('work_type.name as group_label')
            ->selectRaw('COALESCE(work_file_item.customer_amount, 0) as billed')
            ->selectRaw("$cost as cost")
            ->selectRaw("CASE WHEN $outstanding THEN 0
                ELSE work_file_item.customer_amount - ($cost) END as margin")
            ->selectRaw("CASE WHEN $outstanding THEN 1 ELSE 0 END as unpriced");

        self::betweenDates($each, $from, $to);

        $rows = DB::query()
            ->fromSub($each, 'each_work')
            ->select('group_key', 'group_label')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('COALESCE(SUM(billed), 0) as billed')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->selectRaw('COALESCE(SUM(margin), 0) as margin')
            ->selectRaw('COALESCE(SUM(unpriced), 0) as unpriced')
            ->groupBy('group_key', 'group_label')
            ->orderByRaw('billed desc')
            ->get();

        $rows = $rows->when(
            ($returned = self::returnedToCustomer($from, $to)) !== null,
            fn ($all) => $all->push($returned)
        );

        return self::withGivenUp($rows->when(
            ($counter = self::counterExpenses($from, $to)) !== null,
            fn ($all) => $all->push($counter)
        ), $from, $to, 'work_type');
    }

    /**
     * The files returned to the customer, as a row of their own.
     *
     * Their refund is agreed for the folder, so no one work can carry it, and
     * the works above leave them out. Left out of the cut altogether, which is
     * what happened until a health check on 2026-10-07 found it, they took
     * with them what the office kept of the charge and the vendor's rate that
     * still stands on the vendor's statement — both of which every other cut
     * counts — so the same period read lower here than on any other tab, with
     * nothing on screen saying why. Their challans were on Counter expenses
     * all along, so the tab did not even agree with itself.
     *
     * So they are a line, as counter expenses are, and the tab adds up to the
     * same money as the others: billed is what was kept (EARNED), and cost is
     * the vendor's rate that stands — SPENT less what was paid out over the
     * counter, which is on that line already. Never awaiting a price: a
     * returned file is settled; see OUTSTANDING.
     */
    private static function returnedToCustomer(?string $from, ?string $to): ?object
    {
        // Each file on its own, then added up, as profitBy() does each file.
        $each = DB::table('work_file')
            ->where('work_file.status', self::RETURNED)
            ->selectRaw(self::EARNED.' as billed')
            ->selectRaw('('.self::SPENT.') - '.self::PAID_OUT.' as cost')
            ->selectRaw("(SELECT COUNT(*) FROM work_file_item AS rw
                WHERE rw.work_file_id = work_file.id AND rw.status <> 'cancelled') as works");

        self::betweenDates($each, $from, $to);

        $returned = DB::query()
            ->fromSub($each, 'each_file')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('COALESCE(SUM(works), 0) as works')
            ->selectRaw('COALESCE(SUM(billed), 0) as billed')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->selectRaw('COALESCE(SUM(billed - cost), 0) as margin')
            ->first();

        if (! (int) $returned->files) {
            return null;
        }

        return (object) [
            // Apart from counter expenses (0) and the discounts (-1).
            'group_key' => -2,
            'group_label' => 'Returned to customer',
            'note' => 'Kept after the refund, which is agreed for the file, so no one work carries it',
            // Its works, as the rows above count theirs: taken in, and given back.
            'files' => (int) $returned->works,
            'billed' => (float) $returned->billed,
            'cost' => (float) $returned->cost,
            'margin' => (float) $returned->margin,
            'unpriced' => 0,
        ];
    }

    /**
     * The money that no work type can carry, as a row of its own.
     *
     * A challan or an affidavit is paid on the folder, and deliberately so — see
     * the note on the create_file_expense_tables migration: an affidavit is
     * drawn for the vehicle, not for the transfer as opposed to the
     * hypothecation on the same papers. There is no honest way to split one
     * across the three works it was spent on.
     *
     * Leaving it out is not the answer either, and was what happened until now:
     * every other cut of this report subtracts it — they read SPENT, which
     * includes it — so the same month had one margin under Customer and a
     * larger one under Work Type, with nothing on screen saying which was which.
     * A reader comparing the two tabs was comparing two different questions.
     *
     * So it is shown, on its own line, under a name that says why it is not
     * filed under a work. The same move the vendor cut already makes for a file
     * nobody was given: a real bucket for the things the grouping cannot hold,
     * rather than a silence.
     */
    private static function counterExpenses(?string $from, ?string $to): ?object
    {
        $query = DB::table('work_file_expense')
            ->join('work_file', 'work_file.id', '=', 'work_file_expense.work_file_id');

        /*
         * No status filter, unlike the works above. SPENT adds this money
         * outside the CASE that zeroes a cancelled file's vendor cost, because
         * the office paid it out whether or not the work was later called off —
         * and the two cuts only reconcile if this counts the same rows.
         */
        self::betweenDates($query, $from, $to);

        $total = (float) $query->sum('work_file_expense.amount');

        if ($total <= 0) {
            return null;
        }

        return (object) [
            'group_key' => 0,
            'group_label' => 'Counter expenses',
            // Said on the row itself rather than left to the reader to work out
            // from a line that has a cost and charges nobody.
            'note' => 'Paid on the file, so no one work carries it',
            // No work was done for a challan fee, and counting one here would
            // put it in the Works total at the foot of the column.
            'files' => 0,
            'billed' => 0,
            'cost' => $total,
            'margin' => -$total,
            'unpriced' => 0,
        ];
    }

    /** The period a report covers, by the day the papers came in. */
    private static function betweenDates($query, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->whereDate('work_file.received_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('work_file.received_date', '<=', $to);
        }
    }

    /**
     * What was given up, month by month, on the files of each.
     *
     * By the day the papers came in, as every figure beside it is, so a
     * discount corrects the month whose margin it overstated.
     *
     * @return \Illuminate\Support\Collection<string, float>
     */
    private static function givenUpByMonth(?string $from = null)
    {
        if (! PartyLedgerModel::reversible()) {
            return collect();
        }

        $query = DB::table('party_ledger_allocation as a')
            ->join('party_ledger as e', 'e.id', '=', 'a.entry_id')
            ->join('work_file', 'work_file.id', '=', 'a.work_file_id')
            ->where('e.entry_kind', PartyLedgerModel::WRITEOFF)
            ->whereNull('a.released_at');

        if ($from) {
            $query->whereDate('work_file.received_date', '>=', $from);
        }

        return $query->selectRaw("DATE_FORMAT(work_file.received_date, '%Y-%m') as month")
            ->selectRaw('SUM(a.amount) as given')
            ->groupBy('month')
            ->pluck('given', 'month')
            ->map(fn ($given) => round((float) $given, 2));
    }

    /**
     * Headline figures for the dashboard.
     *
     * Cancelled files are excluded from the money throughout — they post nothing
     * to any ledger, so counting them here would make the dashboard disagree
     * with the statements.
     *
     * @return array{open: int, month_billed: float, month_margin: float}
     */
    /**
     * When a file's money is not yet settled, as SQL.
     *
     * Lifted out of summary() so the month tile and the twelve-month chart
     * under it cannot drift apart. A file whose price is still to be agreed has
     * no margin yet and is left out of one rather than counted at a cost of
     * nothing — and a tile saying 40,000 above a chart saying 62,000 for the
     * same month is worse than either figure alone.
     */
    private static function unsettled(): string
    {
        $shortWork = fn (string $column) => "EXISTS (
            SELECT 1 FROM work_file_item
            WHERE work_file_item.work_file_id = work_file.id
              AND work_file_item.status <> '".self::CANCELLED."'
              AND (work_file_item.$column IS NULL OR work_file_item.$column <= 0))";

        return "(status NOT IN ('".self::CANCELLED."', '".self::RETURNED."') AND (
            customer_amount IS NULL OR customer_amount <= 0
            OR ".$shortWork('customer_amount')."
            OR ".self::VENDOR_UNPRICED."))";
    }

    /**
     * What was billed, what it cost and what was left, month by month.
     *
     * By the day the papers came in, the same as the File Margin tile, because
     * that is the month the office thinks of a file as belonging to. Oldest
     * first, and every month in the range is present even when nothing happened
     * in it — a chart that silently closes its gaps draws a quiet August as
     * though it never existed.
     *
     * @return array<int, array{label: string, month: string, billed: float, cost: float, margin: float, files: int}>
     */
    public static function monthlyMoney(int $months = 12): array
    {
        $earned = self::EARNED;
        $spent = self::SPENT;
        $unsettled = self::unsettled();

        $from = now()->startOfMonth()->subMonths($months - 1);

        /*
         * Every file worked out on its own, then grouped — as profitBy() does,
         * for its reason: the cost and the outstanding test are correlated
         * subqueries against the file, and inside an aggregate under a GROUP
         * BY that is something MySQL allows and MariaDB — the live server —
         * refuses ("work_file.id isn't in GROUP BY"). Found in review.
         */
        $each = DB::table('work_file')
            ->whereDate('received_date', '>=', $from->toDateString())
            ->selectRaw("DATE_FORMAT(received_date, '%Y-%m') as month")
            ->selectRaw("$earned as billed")
            ->selectRaw("CASE WHEN $unsettled THEN 0 ELSE ($spent) END as cost")
            ->selectRaw("CASE WHEN $unsettled THEN 0 ELSE $earned - ($spent) END as margin");

        $rows = DB::query()
            ->fromSub($each, 'each_file')
            ->select('month')
            ->selectRaw('COALESCE(SUM(billed), 0) as billed')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost')
            ->selectRaw('COALESCE(SUM(margin), 0) as margin')
            ->selectRaw('COUNT(*) as files')
            ->groupBy('month')
            // keyBy, not pluck: a row here is four figures, and pluck wants one.
            ->get()
            ->keyBy('month');

        /*
         * What was given up on those months' files, so the tile and the chart
         * do not read higher than the Profit report they link to.
         */
        $given = self::givenUpByMonth($from->toDateString());

        return self::overTheMonths($months, function (Carbon $month) use ($rows, $given) {
            $row = $rows[$month->format('Y-m')] ?? null;
            $off = (float) ($given[$month->format('Y-m')] ?? 0);

            return [
                'billed' => round((float) ($row->billed ?? 0), 2),
                // What was given up is money the office does not keep, so it
                // sits with the cost and comes off the margin — the same place
                // the Profit report puts it.
                'cost' => round((float) ($row->cost ?? 0) + $off, 2),
                'margin' => round((float) ($row->margin ?? 0) - $off, 2),
                'files' => (int) ($row->files ?? 0),
            ];
        });
    }

    /**
     * Files taken in against files finished with, month by month.
     *
     * Finished meaning approved or gone back to the customer — FINISHED_ON is
     * the same expression the lists date that by. The two lines answer one
     * question: is the office keeping up with what is coming through the door.
     *
     * @return array<int, array{label: string, month: string, received: int, finished: int}>
     */
    public static function monthlyFlow(int $months = 12): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1)->toDateString();

        $received = DB::table('work_file')
            ->whereDate('received_date', '>=', $from)
            ->selectRaw("DATE_FORMAT(received_date, '%Y-%m') as month")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $finishedOn = self::FINISHED_ON;

        $finished = DB::table('work_file')
            ->whereRaw("$finishedOn IS NOT NULL")
            ->whereRaw("$finishedOn >= ?", [$from])
            ->selectRaw("DATE_FORMAT($finishedOn, '%Y-%m') as month")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        return self::overTheMonths($months, fn (Carbon $month) => [
            'received' => (int) ($received[$month->format('Y-m')] ?? 0),
            'finished' => (int) ($finished[$month->format('Y-m')] ?? 0),
        ]);
    }

    /**
     * The last N months, oldest first, each labelled and filled by the caller.
     *
     * Every month present whether anything happened in it or not, for the
     * reason given on monthlyMoney(): a gap is a fact about the business and a
     * chart that closes it is telling a different story.
     */
    private static function overTheMonths(int $months, callable $fill): array
    {
        $out = [];
        $month = now()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $out[] = array_merge([
                'month' => $month->format('Y-m'),
                // "Sep" on its own where the year has not turned, because a
                // twelve-month axis is mostly one year and reads better short.
                'label' => $month->format('M'),
                'year' => $month->format('Y'),
            ], $fill($month));

            $month = $month->copy()->addMonth();
        }

        return $out;
    }

    /**
     * What vendors are holding right now, and who has had something longest.
     *
     * Counted off the works rather than the folders, because a folder split
     * between two vendors is out with both of them and belongs to neither
     * alone. Work that has come back is not being held.
     *
     * @return array{files: int, vendors: int, oldest_days: ?int, oldest_vendor: ?string}
     */
    public static function vendorsHolding(): array
    {
        $out = DB::table('work_file_item as i')
            ->join('work_file as f', 'f.id', '=', 'i.work_file_id')
            ->join('party as v', 'v.id', '=', 'i.vendor_id')
            ->whereNotNull('i.vendor_id')
            ->whereNull('i.vendor_returned_on')
            ->whereNotIn('i.status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            ->selectRaw('COUNT(DISTINCT i.work_file_id) as files')
            ->selectRaw('COUNT(DISTINCT i.vendor_id) as vendors')
            ->selectRaw('MAX(DATEDIFF(CURDATE(), i.vendor_date)) as oldest_days')
            ->first();

        $days = $out?->oldest_days === null ? null : (int) $out->oldest_days;

        // Who that oldest one is with, asked only when there is one to name.
        $vendor = $days === null ? null : DB::table('work_file_item as i')
            ->join('party as v', 'v.id', '=', 'i.vendor_id')
            ->whereNotNull('i.vendor_id')
            ->whereNull('i.vendor_returned_on')
            ->whereNotIn('i.status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            ->whereRaw('DATEDIFF(CURDATE(), i.vendor_date) = ?', [$days])
            ->value('v.name');

        return [
            'files' => (int) ($out?->files ?? 0),
            'vendors' => (int) ($out?->vendors ?? 0),
            'oldest_days' => $days,
            'oldest_vendor' => $vendor,
        ];
    }

    /**
     * Files held up waiting on a paper, and how long the oldest has waited.
     *
     * The same question the files list asks with PENDING_PAPERS, counted rather
     * than listed. Waited from the day the papers came in: a file nobody can
     * finish is ageing from the moment it arrived, not from the moment somebody
     * noticed a form was missing.
     *
     * @return array{files: int, oldest_days: ?int}
     */
    public static function papersPending(): array
    {
        $row = DB::table('work_file')
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereRaw(self::PENDING_PAPERS)
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('MAX(DATEDIFF(CURDATE(), received_date)) as oldest_days')
            ->first();

        return [
            'files' => (int) ($row?->files ?? 0),
            'oldest_days' => $row?->oldest_days === null ? null : (int) $row->oldest_days,
        ];
    }

    public static function summary(): array
    {
        $open = (int) DB::table('work_file')
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();

        $earned = self::EARNED;
        $spent = self::SPENT;

        /*
         * A file whose price is still to be agreed has no margin yet, so it is
         * left out of the month's rather than counted at a cost of nothing.
         *
         * COALESCE(vendor_amount, 0) above reads "not agreed" as free, so a
         * file out with a vendor at no agreed rate reported its entire charge
         * as profit — on the one figure the business is run from.
         *
         * The rule itself is unsettled(), shared with the twelve-month chart
         * this tile now sits above. Two copies of it would eventually disagree,
         * and a tile and a chart disagreeing about the same month is worse than
         * either of them being wrong on its own.
         */
        $outstanding = self::unsettled();

        $month = DB::table('work_file')
            ->whereYear('received_date', now()->year)
            ->whereMonth('received_date', now()->month)
            ->selectRaw("COALESCE(SUM($earned), 0) as billed")
            ->selectRaw("COALESCE(SUM(CASE WHEN $outstanding THEN 0 ELSE $earned - ($spent) END), 0) as margin")
            // Counted so the tile can say what its figure is a figure of.
            ->selectRaw("COALESCE(SUM(CASE WHEN $outstanding THEN 1 ELSE 0 END), 0) as unpriced")
            ->selectRaw('COUNT(*) as files')
            ->first();

        // And what was given up on this month's files, as the chart beside it
        // and the Profit report both count it.
        $given = (float) (self::givenUpByMonth(now()->startOfMonth()->toDateString())[now()->format('Y-m')] ?? 0);

        return [
            'open' => $open,
            'month_billed' => (float) ($month->billed ?? 0),
            'month_margin' => round((float) ($month->margin ?? 0) - $given, 2),
            'month_files' => (int) ($month->files ?? 0),
            'month_unpriced' => (int) ($month->unpriced ?? 0),
        ];
    }
    /**
     * A folder from before works carried their own vendor, which that vendor
     * still has: out with them, or handed back with part of its rate still
     * theirs.
     *
     * Its works name nobody, so each reads as waiting for a vendor — and Give
     * to Vendor offered them while the vendor had the papers. But all of an
     * older folder is its vendor's (see isOlderFolder()), and giving any of it
     * out takes their credit off their statement. Found in review on
     * 2026-09-30; the same rule as cameBackWhole() on a work, asked of the
     * folder that carries the vendor.
     */
    public function isHeldByItsVendor(): bool
    {
        return $this->vendor_id
            && self::isOlderFolder($this->items)
            && (! $this->vendor_returned_on || $this->vendor_returned_amount !== null);
    }

    /** isHeldByItsVendor(), as SQL, of the row called work_file. */
    public const HELD_BY_ITS_VENDOR = '(work_file.vendor_id IS NOT NULL AND '.self::OLDER_FOLDER.'
        AND (work_file.vendor_returned_on IS NULL OR work_file.vendor_returned_amount IS NOT NULL))';

    /**
     * A folder from before works carried their own vendor, handed back by
     * that vendor with all of its rate reversed: nothing of it is theirs, and
     * it can go out again.
     */
    public function cameBackWholeFromItsVendor(): bool
    {
        return $this->vendor_id
            && self::isOlderFolder($this->items)
            && $this->vendor_returned_on
            && $this->vendor_returned_amount === null;
    }

    /**
     * Let go of the vendor such a folder came back from, as it goes out again
     * or is kept here: what they did not finish is nobody's, as though never
     * given.
     *
     * The rates agreed with them for it go too. Left on works that name
     * nobody they would read as the office's own cost. Their credit and its
     * reversal for it, which net to nothing, leave their statement at the
     * next syncLedger().
     *
     * What they did finish stays theirs: approved, or gone back to its
     * customer, while they had it. Written onto the work itself — handed back
     * with the folder, all of its rate reversed — as a work of their own
     * would be (see WorkFileItemModel::cameBackWhole(), which never lets go of
     * finished work). Found in review: cleared with the rest, the transfer
     * they got approved left the Vendors report and their profit.
     */
    public function letGoOfItsVendor(): void
    {
        foreach ($this->items as $item) {
            if (in_array($item->status, [self::APPROVED, self::RETURNED], true)) {
                $item->vendor_id = $this->vendor_id;
                $item->vendor_date = $this->vendor_date;
                $item->vendor_returned_on = $this->vendor_returned_on;
                $item->save();
            } elseif ($item->vendor_amount !== null) {
                $item->vendor_amount = null;
                $item->save();
            }
        }

        $this->vendor_id = null;
        $this->vendor_date = null;
        $this->vendor_returned_on = null;
        $this->vendor_amount = null;
    }

    /**
     * WorkFileItemModel::canBeGivenOut(), as a condition on the works: waiting
     * for a vendor, or back whole from one — see cameBackWhole().
     */
    public static function canBeGivenOut($q)
    {
        return $q->whereNotIn('status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            ->where(fn ($who) => $who
                ->where(fn ($waiting) => $waiting->whereNull('vendor_id')->whereNull('kept_in_house_on'))
                ->when(WorkFileItemModel::partReversals(), fn ($back) => $back->orWhere(fn ($whole) => $whole
                    ->whereNotNull('vendor_id')
                    ->whereNotNull('vendor_returned_on')
                    ->whereNull('vendor_returned_amount'))));
    }

    /**
     * Files waiting to be given to a vendor: received, not cancelled, nobody
     * working on them yet. This is exactly the set the assign screen offers, and
     * the same conditions are re-applied on save so a stale page cannot reassign
     * a file that was given away in the meantime.
     */
    public static function unassigned()
    {
        return self::query()
            // items.vendor: a folder can come back here for its other half, and
            // the half already gone has to say who has it.
            // vendor: an older folder back from its vendor says who; see
            // cameBackWholeFromItsVendor().
            ->with('workType', 'customer', 'vendor', 'items.workType', 'items.vendor')
            // Whether its papers are ready to go with it; see assign().
            ->select('work_file.*')
            ->selectRaw(self::NEEDS_AUDIT.' as needs_audit')
            ->selectRaw(self::PENDING_PAPER_NAMES.' as pending_papers')
            /*
             * A folder with any work still on the desk, rather than a folder
             * with no vendor.
             *
             * Its works go out one at a time now, so a folder whose transfer is
             * with one vendor and whose hypothecation addition is still here
             * has to come back to this screen — under the old rule it left it
             * the moment anything on it was given away.
             */
            ->where(fn ($outer) => $outer
                ->whereHas('items', fn ($q) => self::canBeGivenOut($q))
                // A folder with no works at all is still handed over whole.
                ->orWhereDoesntHave('items'))
            // Nor anything of an older folder its vendor still has.
            ->whereRaw('NOT '.self::HELD_BY_ITS_VENDOR)
            // Only work still in hand can be given out. A file that is approved,
            // returned or cancelled has nothing left for a vendor to do.
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Files that can still go back to the customer: anything not already
     * returned and not cancelled. The return screen offers exactly this set and
     * the same conditions are re-applied on save, so a stale page cannot return
     * a file twice.
     */
    /**
     * Files that may be handed back to the customer with a refund.
     *
     * Returning is a refund, not a delivery: netCustomer() takes the returned
     * portion off the charge, and a return with no partial figure typed takes
     * all of it. So a folder holding work that came through approved must not
     * be on that screen — sending it there gives back money the office earned,
     * on a job the RTO has already done, and the statement quietly loses the
     * charge.
     *
     * "Any work approved", not "the folder is approved": a partly approved
     * folder is one where some work is through and some is not, and refunding
     * the whole of it refunds the part that finished.
     *
     * Applied as a scope so the screen that lists them and the post that acts
     * on them ask the same question. Two places spelling out one rule is how
     * a list and the thing behind it come to disagree.
     */
    public function scopeWithoutApprovedWork($query)
    {
        return $query
            ->whereNotIn('status', [self::RETURNED, self::CANCELLED, self::APPROVED])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                ->where('work_file_item.status', self::APPROVED));
    }

    public static function returnableToCustomer()
    {
        return self::query()
            ->with('workType', 'customer', 'vendor', 'items.workType')
            ->withoutApprovedWork()
            ->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Files for the status board.
     *
     * 'open' is the default and means work still in hand — the reason to open
     * this screen at all. Anything else filters to that one status.
     */
    public static function forStatusBoard(string $filter, $workTypeId = null, $vendorId = null)
    {
        // The jobs come with the file: the board moves each of them along on
        // its own, because approvals arrive one at a time.
        $query = self::query()->with('workType', 'customer', 'vendor', 'items.workType', 'items.vendor');

        $holder = self::holderKey($vendorId);

        /*
         * One vendor's view, or the office's: the folders they hold work on,
         * standing where their own works stand. Asked for by the owner on
         * 2026-09-28, with the rule that anything vendor-wise counts only the
         * works given to that vendor: asked of the folder's vendor, a vendor's
         * view listed the office's own work beside theirs, the In-house view
         * missed work kept in the office on a folder a vendor had part of, and
         * a folder split between two vendors was under neither.
         */
        if ($holder !== null) {
            return $query
                ->whereIn('work_file.id', self::heldBy($holder, $filter, $workTypeId)->select('p.work_file_id'))
                ->orderBy('received_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();
        }

        self::applyStatusFilter($query, $filter);

        if ($workTypeId) {
            // Matched against the jobs, so a file is shown when any of its work
            // is of that type — not only when the first one is.
            $query->whereHas('items', fn ($q) => $q->where('work_type_id', $workTypeId));
        }

        return $query
            ->orderBy('received_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * 'open' means work still in hand, 'all' means no restriction, and anything
     * else is one named status. Shared so the board and its counts can never
     * disagree about what a tab means.
     */
    private static function applyStatusFilter($query, string $filter, string $column = 'work_file.status'): void
    {
        // Named with its table: the work type counts below join the works,
        // which have a status of their own, and a bare column would be
        // ambiguous — or worse, silently the wrong one.
        if ($filter === 'open') {
            $query->whereIn($column, self::OPEN_STATUSES);
        } elseif (array_key_exists($filter, self::STATUSES)) {
            $query->where($column, $filter);
        }
    }

    /**
     * The board's vendor filter, as a holder: null for no filter, 0 for the
     * work nobody was given ('none' — a deliberate choice, which a blank
     * could not tell from no filter), a vendor's id, or -1 — matching nothing,
     * as a junk id always did.
     */
    public static function holderKey($vendorId): ?int
    {
        return match (true) {
            $vendorId === null || $vendorId === '' => null,
            $vendorId === self::IN_HOUSE => 0,
            ctype_digit((string) $vendorId) && (int) $vendorId > 0 => (int) $vendorId,
            default => -1,
        };
    }

    /** Folders any work of which, cancelled ones included, was ever given out. */
    private static function everGiven()
    {
        return DB::table('work_file_item')->whereNotNull('vendor_id')->distinct()->select('work_file_id');
    }

    /**
     * One row per work, with who holds it: its own vendor — or, on an older
     * folder, the folder's — and 0 for nobody. Ungrouped: grouped over, it is
     * MariaDB-safe, having nothing correlated in it.
     */
    private static function worksByHolder(bool $openFolders = false)
    {
        return DB::table('work_file_item as i')
            ->join('work_file', 'work_file.id', '=', 'i.work_file_id')
            // A part can only stand open on a folder that is open: asked of an
            // open tab — In Hand, the board's default — the rest is not read
            // (found in review: every work ever entered was grouped, every load).
            ->when($openFolders, fn ($q) => $q->whereIn('work_file.status', self::OPEN_STATUSES))
            ->leftJoinSub(self::everGiven(), 'given', 'given.work_file_id', '=', 'i.work_file_id')
            ->select('i.id', 'i.work_file_id', 'i.work_type_id', 'i.status', 'work_file.status as folder_status')
            ->selectRaw('COALESCE(i.vendor_id, CASE WHEN given.work_file_id IS NULL THEN work_file.vendor_id END, 0) AS holder');
    }

    /** boardStatus(), as SQL over a holder's works (as $h, grouped). */
    private static function boardStatusSql(string $h): string
    {
        $open = "'".implode("','", self::OPEN_STATUSES)."'";
        [$approved, $partly, $cancelled, $returned] = [self::APPROVED, self::PARTLY_APPROVED, self::CANCELLED, self::RETURNED];

        return "CASE
            WHEN SUM($h.status IN ($open)) > 0 THEN
                CASE WHEN SUM($h.status = '$approved') > 0 THEN '$partly'
                     ELSE ELT(MIN(NULLIF(FIELD($h.status, $open), 0)), $open) END
            WHEN SUM($h.status <> '$cancelled') = 0 THEN '$cancelled'
            WHEN SUM($h.status NOT IN ('$returned', '$cancelled')) = 0 THEN '$returned'
            ELSE '$approved' END";
    }

    /**
     * Each folder's parts, one per holder, standing where that holder's
     * works stand — narrowed to parts with a work of one type when asked.
     */
    private static function holdings($workTypeId = null, ?string $filter = null)
    {
        $parts = DB::query()->fromSub(self::worksByHolder(self::isOpenTab($filter)), 'h')
            ->select('h.work_file_id', 'h.holder')
            ->selectRaw(self::boardStatusSql('h').' AS status')
            ->groupBy('h.work_file_id', 'h.holder');

        if ($workTypeId) {
            $parts->havingRaw('SUM(h.work_type_id = ?) > 0', [(int) $workTypeId]);
        }

        return $parts;
    }

    /** Whether a tab shows only work still in hand: In Hand, or one of its stages. */
    private static function isOpenTab(?string $filter): bool
    {
        return $filter === 'open' || in_array($filter, self::OPEN_STATUSES, true);
    }

    /** One holder's parts under a tab. */
    private static function heldBy(int $holder, string $filter, $workTypeId = null)
    {
        $query = DB::query()->fromSub(self::holdings($workTypeId, $filter), 'p')->where('p.holder', $holder);

        self::applyStatusFilter($query, $filter, 'p.status');

        return $query;
    }

    /** The works of this folder one holder has; see worksByHolder(). Cancelled ones included. */
    public function worksHeldBy(int $holder)
    {
        $older = self::isOlderFolder($this->items);

        return $this->items
            ->filter(fn ($item) => (int) ($item->vendor_id ?: ($older ? $this->vendor_id : 0)) === $holder)
            ->values();
    }

    /**
     * Which vendors are holding work under the filters that are on, and how
     * much each of them has.
     *
     * Work nobody was given is counted together as in-house: it is work in
     * hand like any other, and leaving it out of the row would make the counts
     * disagree with the board.
     *
     * One chip a holder, counting their parts: a folder shared between a
     * vendor and the office, or two vendors, is under each of them — so the
     * chips can add up to more than All. Each counts exactly what the board
     * shows when it is clicked.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function vendorCounts(string $filter, $workTypeId = null)
    {
        $query = DB::query()->fromSub(self::holdings($workTypeId, $filter), 'p')
            ->leftJoin('party as vendor', 'vendor.id', '=', 'p.holder');

        self::applyStatusFilter($query, $filter, 'p.status');

        return $query
            ->select(
                DB::raw('p.holder as id'),
                DB::raw("COALESCE(vendor.name, CASE WHEN p.holder = 0 THEN 'In-house' ELSE 'Unknown vendor' END) as name"),
                DB::raw('COUNT(*) as total')
            )
            // ONLY_FULL_GROUP_BY: the columns the names are derived from.
            ->groupBy('p.holder', 'vendor.name')
            ->orderBy('name', 'asc')
            ->get();
    }
    /**
     * How many files sit behind each status tab, counted within whatever work
     * type is currently selected — a count that ignored the other filter would
     * promise rows the tab then does not show.
     *
     * @return array<string, int>  keyed by tab: 'open', each status, 'all'
     */
    public static function statusCounts($workTypeId = null, $vendorId = null): array
    {
        $holder = self::holderKey($vendorId);

        if ($holder !== null) {
            // One holder's parts, by where their works stand; see forStatusBoard().
            $byStatus = DB::query()->fromSub(self::holdings($workTypeId), 'p')
                ->where('p.holder', $holder)
                ->selectRaw('p.status, COUNT(*) as total')
                ->groupBy('p.status')
                ->pluck('total', 'status');
        } else {
            $query = DB::table('work_file');

            if ($workTypeId) {
                /*
                 * Matched against the works, which is how the board itself filters:
                 * counting the folder's own type credited a folder to the first work
                 * on it, so the tab promised fewer files than the board then showed.
                 */
                $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('work_file_item')
                    ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                    ->where('work_file_item.work_type_id', $workTypeId));
            }

            $byStatus = $query->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
        }

        $counts = ['open' => 0, 'all' => 0];

        foreach (array_keys(self::STATUSES) as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
            $counts['all'] += $counts[$status];

            if (in_array($status, self::OPEN_STATUSES, true)) {
                $counts['open'] += $counts[$status];
            }
        }

        return $counts;
    }

    /**
     * Work types with how many files each has under the current status tab.
     * Types with nothing to show are left out — an empty filter is a dead end.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function workTypeCounts(string $filter, $vendorId = null)
    {
        $holder = self::holderKey($vendorId);

        /*
         * One holder's view: the types of their own works, on the parts the
         * board shows them under this tab.
         */
        if ($holder !== null) {
            return DB::query()->fromSub(self::worksByHolder(self::isOpenTab($filter)), 'w')
                ->joinSub(self::heldBy($holder, $filter)->select('p.work_file_id', 'p.holder'), 'p',
                    fn ($join) => $join->on('p.work_file_id', '=', 'w.work_file_id')->on('p.holder', '=', 'w.holder'))
                ->join('work_type', 'work_type.id', '=', 'w.work_type_id')
                ->select('work_type.id', 'work_type.name', DB::raw('COUNT(DISTINCT w.work_file_id) as total'))
                ->groupBy('work_type.id', 'work_type.name')
                ->orderBy('work_type.name', 'asc')
                ->get();
        }

        /*
         * Counted through the works, so a folder appears under every type it
         * holds. DISTINCT because that is the point: a folder with a transfer
         * and a hypothecation addition is one file under each of them, not two
         * under either.
         */
        $query = DB::table('work_file')
            ->join('work_file_item', 'work_file_item.work_file_id', '=', 'work_file.id')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id');

        self::applyStatusFilter($query, $filter);

        return $query
            ->select('work_type.id', 'work_type.name', DB::raw('COUNT(DISTINCT work_file.id) as total'))
            ->groupBy('work_type.id', 'work_type.name')
            ->havingRaw('COUNT(DISTINCT work_file.id) > 0')
            ->orderBy('work_type.name', 'asc')
            ->get();
    }

    /**
     * The office a registration number belongs to.
     *
     * The first four characters: the state and the district code, BR06 or BR01,
     * which is the RTO the papers go through. What a transfer costs at one
     * office is not what it costs at another, so a rate only means something
     * beside the office it was paid at.
     */
    public static function rtoOf(?string $registration): string
    {
        return substr(self::normaliseRegistration((string) $registration), 0, 4);
    }

    /**
     * The last few charges this customer paid, per work and per office.
     *
     * Asked where a price is being agreed with them in front of you. Their own
     * history rather than everybody's — a price is settled between two people —
     * and split by office, because the RTO fee is passed on and the same work
     * costs one thing at BR01 and another at BR06.
     *
     * One read of this customer's own work, grouped afterwards. Grouping in the
     * database would need a window function or a query per pair, and neither is
     * worth it for one party's history.
     *
     * @return array<int, array{work_type_id: int, rto: string, rates: array}>
     */
    public static function recentCustomerRates(int $customerId, int $limit = 5): array
    {
        $rows = DB::table('work_file_item')
            ->join('work_file', 'work_file.id', '=', 'work_file_item.work_file_id')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->where('work_file.customer_id', $customerId)
            // A charge that was never agreed is not a charge, and a cancelled
            // file charged nobody.
            ->where('work_file_item.customer_amount', '>', 0)
            ->where('work_file.status', '<>', self::CANCELLED)
            ->orderByDesc('work_file.received_date')
            ->orderByDesc('work_file.id')
            ->get([
                'work_file_item.work_type_id',
                'work_file.file_no',
                'work_file.received_date',
                'work_file.registration_no',
                'work_file.status',
                'work_type.name as work_type',
                'work_file_item.customer_amount as amount',
            ]);

        $grouped = [];

        foreach ($rows as $row) {
            $key = ((int) $row->work_type_id).'|'.self::rtoOf($row->registration_no);

            // Newest first out of the query, so the first few of each pair are
            // the few that are wanted.
            if (count($grouped[$key] ?? []) >= $limit) {
                continue;
            }

            $grouped[$key][] = $row;
        }

        $out = [];

        foreach ($grouped as $key => $rates) {
            [$typeId, $rto] = explode('|', $key, 2);

            $out[] = [
                'work_type_id' => (int) $typeId,
                'rto' => $rto,
                'rates' => $rates,
            ];
        }

        return $out;
    }
    /**
     * The last few rates agreed for each work, at the office it was for.
     *
     * Shown where a rate is being agreed, because that is where the question is
     * asked: what did we pay for a transfer at BR06 last time, and to whom.
     * Every vendor's is included rather than only the one being chosen —
     * comparing across them is half the reason to ask.
     *
     * One query per pair, deliberately. A handful are on the screen at a time,
     * each is a short indexed read, and the portable alternatives are a window
     * function or reading every rate ever agreed and throwing most of it away.
     *
     * @param  array<int, array{0: int, 1: string}>  $pairs  work type id, RTO
     * @return array<int, array{work_type_id: int, rto: string, rates: array}>
     */
    public static function recentVendorRates(array $pairs, int $limit = 5): array
    {
        $out = [];
        $seen = [];

        foreach ($pairs as [$typeId, $rto]) {
            $key = $typeId.'|'.$rto;

            if (! $typeId || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            /*
             * Paid to the work's own vendor, on the day it went to them — or,
             * on an older folder, the folder's. Found on 2026-09-28: read off
             * the folder, a rate typed on work the office kept was offered as
             * paid to the vendor who had the rest, and a folder split between
             * two vendors was never offered at all.
             */
            $held = 'COALESCE(work_file_item.vendor_id, CASE WHEN given.work_file_id IS NULL THEN work_file.vendor_id END)';
            $on = 'COALESCE(work_file_item.vendor_date, CASE WHEN given.work_file_id IS NULL THEN work_file.vendor_date END)';

            $query = DB::table('work_file_item')
                ->join('work_file', 'work_file.id', '=', 'work_file_item.work_file_id')
                ->leftJoinSub(self::everGiven(), 'given', 'given.work_file_id', '=', 'work_file_item.work_file_id')
                // A work nobody was given was paid to nobody.
                ->join('party as vendor', 'vendor.id', '=', DB::raw($held))
                ->where('work_file_item.work_type_id', $typeId)
                // A rate that was never agreed is not a rate that was paid, and
                // cancelled work was owed for by nobody.
                ->whereNotNull('work_file_item.vendor_amount')
                ->where('work_file_item.vendor_amount', '>', 0)
                ->where('work_file_item.status', '<>', self::CANCELLED)
                ->where('work_file.status', '<>', self::CANCELLED);

            /*
             * The same office. A file with no registration number cannot say
             * which office it was for, so it is not offered as a comparison for
             * one that can.
             */
            if ($rto !== '') {
                $query->whereRaw('LEFT(work_file.registration_no, 4) = ?', [$rto]);
            }

            $out[] = [
                'work_type_id' => (int) $typeId,
                'rto' => $rto,
                'rates' => $query
                    ->orderByRaw("$on DESC")
                    ->orderByDesc('work_file.id')
                    ->orderByDesc('work_file_item.id')
                    ->limit($limit)
                    ->get([
                        'work_file.file_no',
                        DB::raw("$on as vendor_date"),
                        'work_file.registration_no',
                        'vendor.name as vendor',
                        'work_file_item.vendor_amount as amount',
                        'work_file_item.customer_amount as charged',
                    ])
                    ->all(),
            ];
        }

        return $out;
    }
    /**
     * Everything already booked against one vehicle.
     *
     * This is the verification the receive screen needs: before charging for a
     * transfer, the operator can see that the same vehicle was charged for a
     * transfer last month, and at what price. Cancelled files are included and
     * labelled rather than hidden — "we booked this and voided it" is exactly
     * the kind of thing worth seeing before booking it again.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function historyFor(string $registrationNo, ?int $excludeId = null)
    {
        $normalised = self::normaliseRegistration($registrationNo);

        if ($normalised === '') {
            return collect();
        }

        $query = DB::table('work_file')
            ->join('work_type', 'work_type.id', '=', 'work_file.work_type_id')
            ->join('party as customer', 'customer.id', '=', 'work_file.customer_id')
            ->leftJoin('party as vendor', 'vendor.id', '=', 'work_file.vendor_id')
            ->where('work_file.registration_no', $normalised)
            ->select(
                'work_file.id',
                'work_file.file_no',
                'work_file.received_date',
                'work_file.status',
                'work_file.customer_amount',
                'work_file.returned_amount',
                'work_type.name as work_type',
                'work_type.id as work_type_id',
                'customer.name as customer_name',
                'work_file.vendor_id',
                'vendor.name as vendor_name'
            );

        if ($excludeId) {
            $query->where('work_file.id', '!=', $excludeId);
        }

        $files = $query->orderBy('work_file.received_date', 'desc')
            ->orderBy('work_file.id', 'desc')
            ->limit(25)
            ->get();

        /*
         * The works on each, rather than the folder's own type.
         *
         * A folder holding a transfer and a hypothecation addition has one
         * work_type_id and two works, so "has this vehicle had a transfer
         * before" cannot be answered from the column joined above.
         */
        $all = $files->isEmpty() ? collect() : DB::table('work_file_item')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->leftJoin('party as work_vendor', 'work_vendor.id', '=', 'work_file_item.vendor_id')
            ->whereIn('work_file_item.work_file_id', $files->pluck('id')->all())
            ->orderBy('work_file_item.id')
            ->get(['work_file_item.work_file_id', 'work_file_item.status', 'work_file_item.vendor_id',
                'work_type.id', 'work_type.name', 'work_vendor.name as vendor_name'])
            ->groupBy('work_file_id');

        foreach ($files as $file) {
            $mine = $all->get($file->id, collect());
            $file->works = $mine->reject(fn ($work) => $work->status === self::CANCELLED)->values();

            /*
             * Who had it, from its works, as the Work Report says it: every
             * vendor by name, "+ in-house" where the office kept some. Read
             * off the folder, one split between two vendors named nobody.
             */
            $file->vendor_name = self::givenTo($mine, $file->vendor_id, $file->vendor_name,
                $mine->filter(fn ($work) => $work->vendor_id)->mapWithKeys(fn ($work) => [(int) $work->vendor_id => $work->vendor_name])->all());
            // Still in hand: the state that makes the same work arriving again
            // a file entered twice rather than a job done again.
            $file->open = ! in_array($file->status, [self::APPROVED, self::RETURNED, self::CANCELLED], true);
        }

        return $files;
    }

    /**
     * Work already in hand for this vehicle, of the kinds about to be taken in.
     *
     * The counter's mistake this catches: the same papers entered twice, which
     * charges the customer twice and sends two files for one job. A vehicle
     * whose transfer was done and finished last year is not this — that is the
     * same work again, which is ordinary — so only unfinished work counts.
     *
     * @param  array<int, int>  $workTypeIds
     * @return array<int, object>  one per clash: work_type_id, work_type, file_no, status
     */
    public static function workAlreadyInHand(?string $registrationNo, array $workTypeIds): array
    {
        $normalised = self::normaliseRegistration($registrationNo);
        $workTypeIds = array_values(array_filter(array_map('intval', $workTypeIds)));

        if ($normalised === '' || ! $workTypeIds) {
            return [];
        }

        return DB::table('work_file_item')
            ->join('work_file', 'work_file.id', '=', 'work_file_item.work_file_id')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->where('work_file.registration_no', $normalised)
            ->whereIn('work_file_item.work_type_id', $workTypeIds)
            // Neither the file nor the work itself is finished with.
            ->whereNotIn('work_file.status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            ->whereNotIn('work_file_item.status', [self::APPROVED, self::RETURNED, self::CANCELLED])
            ->orderBy('work_file.id')
            ->get([
                'work_file.id as file_id',
                'work_file.file_no',
                'work_file.status',
                'work_type.id as work_type_id',
                'work_type.name as work_type',
            ])
            ->all();
    }

    /**
     * One spelling per vehicle, so "br01 dd-1234" and "BR01DD1234" find each
     * other. Stored normalised; the operator may type it however they like.
     */
    public static function normaliseRegistration(?string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /**
     * Work files for the party-wise report.
     *
     * One row per file, carrying whichever party the report is grouped by as
     * party_id / party_name, so the view groups on one pair of columns instead
     * of branching on the report type in a dozen places.
     *
     * Vendor-wise necessarily drops files nobody was given — there is no vendor
     * to file them under.
     */
    /**
     * Every work a file is for, named, as one column.
     *
     * A folder holding a transfer and a hypothecation addition is both of
     * them, and calling it by the first alone is how a list comes to show
     * "HPA" over a file that is also a transfer.
     *
     * Cancelled work is left out: it charges nobody, and naming it beside the
     * billed figure would say that it did. A file with nothing left falls back
     * to its own work type, which is all it has to be called by.
     *
     * Written as SQL rather than through the relation because both callers are
     * query-builder reports that read hundreds of rows at once — one label per
     * file this way, against one query per file the other.
     */
    /**
     * The works on each file, in the order they were entered.
     *
     * One query for a whole page of the list rather than one per row, and one
     * shape for everything built from it: the sentence under the status badge
     * and the columns that reach a spreadsheet both read this.
     *
     * @param  array<int>  $fileIds
     * @return array<int, array<int, object>>  keyed by file: name, status, approved_on
     */
    /**
     * One customer's files, and only the columns that customer may see.
     *
     * The select list is the security boundary, written out rather than
     * narrowed from select * afterwards. work_file carries vendor_id,
     * vendor_amount, vendor_date and both vendor_returned columns on the same
     * row as the file number; the difference between vendor_amount and
     * customer_amount is the margin, and a query that fetches the row and
     * trusts the caller to drop four fields is a query one careless edit away
     * from handing it over.
     *
     * Cancelled files are shown. A customer who was charged nothing still sent
     * papers in and is owed an answer about where they went.
     */
    public static function forCustomer(int $customerId)
    {
        return DB::table('work_file')
            ->leftJoin('work_type', 'work_type.id', '=', 'work_file.work_type_id')
            ->where('work_file.customer_id', $customerId)
            ->select(
                'work_file.id',
                'work_file.file_no',
                'work_file.received_date',
                'work_file.registration_no',
                'work_file.description',
                /*
                 * The office's own note on the folder, shown to the customer at
                 * their request. It is free text somebody types, so it is only
                 * as safe as what gets typed — which is why the status log's
                 * remark is deliberately not here beside it: that one is
                 * generated, and it generates "Given to <vendor>".
                 */
                'work_file.remarks',
                'work_file.status',
                'work_file.customer_amount',
                'work_file.returned_amount',
                'work_file.returned_on',
                'work_file.handed_over_on',
                'work_file.approval_screenshot',
                // Names only — the notes belong to the file page, and the
                // office's own notes to nobody here.
                DB::raw(self::PENDING_PAPER_NAMES.' as pending_papers'),
                'work_type.name as work_type'
            )
            ->orderByDesc('work_file.received_date')
            ->orderByDesc('work_file.id');
    }

    /**
     * What is left of a remark once the office's own additions are removed.
     *
     * The remark column holds two things at once. The status board stores
     * exactly what somebody typed; the assign and vendor-return screens take
     * what somebody typed and append a clause of their own — "Given to <vendor>",
     * "HPA, TR given to <vendor>" when only part of a folder went, and "Papers
     * returned by <vendor>" — into the same field. So a remark cannot be shown
     * to a customer as it stands, and cannot be trusted on the strength of
     * where it came from either.
     *
     * The generated clause runs to the end of the string. It is cut from where
     * it starts — after the last dash the screens join a typed remark with, or
     * from the beginning — so the works named in front of "given to" go with
     * the vendor's name, and whatever the office actually wrote stays.
     *
     * Found in use: matched as "Given to" with a capital, the part-folder
     * clause, "HPA given to Shailendra Pandey Motihari", reached a customer's
     * timeline whole. Matched in any case now, and whatever is left is still
     * checked for a vendor's name or number (withoutVendors) — a name typed
     * by hand is caught there too.
     *
     * Matched against our own wording, which is why WorkFileTest drives a real
     * assignment through the controller rather than trusting this pattern to
     * still describe what that screen writes.
     *
     * @param  array<int, array{0: string, 1: string}>|null  $marks  vendorMarks(), when already read
     */
    public static function customerRemark(?string $remark, ?array $marks = null): ?string
    {
        if (! is_string($remark) || trim($remark) === '') {
            return null;
        }

        $clean = $remark;

        if (preg_match('/\b(given\s+to|papers\s+returned\s+by)\b/iu', $clean, $found, PREG_OFFSET_CAPTURE)) {
            $head = substr($clean, 0, $found[0][1]);

            // Back to the dash a typed remark was joined with, if there is one.
            $cut = preg_match_all('/\s[-–—]\s/u', $head, $dashes, PREG_OFFSET_CAPTURE)
                ? end($dashes[0])[1]
                : 0;

            $clean = substr($clean, 0, $cut);
        }

        $clean = trim($clean, " \t\n\r\0\x0B-–—");

        return self::withoutVendors($clean === '' ? null : $clean, $marks);
    }

    /**
     * What identifies a vendor in free text: each one's name, the first word
     * of it, and their numbers — lower case, spaces run together.
     *
     * The first word because a name is typed however the typist likes —
     * "Shailendra ji" for Shailendra Pandey Motihari — and a first name is the
     * part most often typed. Only a word of four letters or more, and not one
     * of the courtesies or trade words that begin a name without identifying
     * it; the town at the end of a name is not taken, being a word customers
     * use of themselves.
     *
     * @return array<int, array{0: string, 1: string}>  [kind, mark]: 'text' or 'digits'
     */
    public static function vendorMarks(): array
    {
        return self::partyMarks('vendor');
    }

    /**
     * A run of digits that may be a phone number, however it was typed:
     * spaced, dashed, dotted, slashed or bracketed, with +91 or without.
     * Found after the vendor-side sweep: "98765.12345" and "(98765) 12345"
     * went past both filters as typed.
     */
    private const NUMBER_RUN = '/\+?\(?\d[\d\s\-.\/()]{8,}\d/u';

    /**
     * vendorMarks() for what one customer reads: less what is theirs.
     *
     * The vendor account that is this same customer — linked by the office
     * for set-off — carries their own name and number, and so does the
     * customer's own row. Neither is a vendor to them. Found after the
     * vendor-side sweep: a person who is both read their own name cut from
     * their own statement and portal ("Received from … ji"), the mirror of
     * what was put right on the vendor's side. A vendor who only shares a
     * first name with them is still cut: it may be the vendor who is meant.
     *
     * @param  array<int, array{0: string, 1: string, 2?: int}>|null  $marks  vendorMarks(), when already read
     * @return array<int, array{0: string, 1: string, 2?: int}>
     */
    public static function vendorMarksFor(?int $customerId, ?array $marks = null): array
    {
        $marks ??= self::vendorMarks();
        $customer = $customerId ? DB::table('party')->where('id', $customerId)->where('party_type', 'customer')->first() : null;

        if (! $customer) {
            return $marks;
        }

        $linked = PartyLedgerModel::canSetOff() && ($customer->linked_vendor_id ?? null) ? (int) $customer->linked_vendor_id : null;

        $own = array_values(array_filter(
            array_map(fn ($number) => substr(preg_replace('/\D/', '', (string) $number), -10), [$customer->mobile, $customer->whatsapp]),
            fn ($digits) => strlen($digits) === 10
        ));

        return array_values(array_filter($marks, fn ($mark) => ! ($linked !== null && ($mark[2] ?? null) === $linked)
            && ! ($mark[0] === 'digits' && in_array($mark[1], $own, true))));
    }

    /**
     * Every party of one type's names, first names and numbers, as
     * vendorMarks() describes them.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function partyMarks(string $type, array $except = []): array
    {
        // The courtesies a name is saved with, which say nothing of who it is.
        $titles = ['shri', 'shree', 'sri', 'sh', 'smt', 'shrimati', 'mr', 'mrs', 'ms', 'miss', 'm/s', 'md', 'mohd', 'dr', 'er', 'km', 'kumari', 'late', 'prof'];
        $common = ['the', 'new', 'auto', 'autos', 'motor', 'motors', 'rto', 'agency', 'services', 'service', 'bihar', 'patna'];
        $marks = [];

        $parties = DB::table('party')->where('party_type', $type)
            ->when($except, fn ($q) => $q->whereNotIn('id', $except))
            ->get(['id', 'name', 'mobile', 'whatsapp']);

        foreach ($parties as $party) {
            // Whose each mark is, third, so one party's can be left out later.
            $id = (int) $party->id;
            $name = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) $party->name)));

            if (mb_strlen($name) >= 3) {
                $marks[] = ['text', $name, $id];
            }

            /*
             * And the name without the courtesy it was saved with, and its
             * first word after that. Found in review: "Shri Rakesh Kumar" was
             * caught only when typed with its "Shri", and "Smt." — with its
             * dot, which the list did not have — became a mark of its own, so
             * "Smt. Kiran" was cut and "Sunita ji" was not.
             */
            $words = explode(' ', $name);

            while (count($words) > 1 && in_array(rtrim($words[0], '.'), $titles, true)) {
                array_shift($words);
            }

            $bare = implode(' ', $words);

            if ($bare !== $name && mb_strlen($bare) >= 3) {
                $marks[] = ['text', $bare, $id];
            }

            $first = rtrim($words[0] ?? '', '.,');

            if (mb_strlen($first) >= 4 && ! in_array($first, $common, true) && $first !== $bare) {
                $marks[] = ['text', $first, $id];
            }

            foreach ([$party->mobile, $party->whatsapp] as $number) {
                $digits = substr(preg_replace('/\D/', '', (string) $number), -10);

                if (strlen($digits) === 10) {
                    $marks[] = ['digits', $digits, $id];
                }
            }
        }

        return $marks;
    }

    /**
     * What identifies a customer in typed text: the mirror of vendorMarks().
     *
     * The owner's other rule: a vendor is never told a customer's name. A
     * vendor's statement is printed and sent to them, and what the office
     * types on a vendor's entries — "paid for Rakesh ji's TR" — is typed by
     * people. The same names, first names and numbers, the same words left
     * alone; every customer, since typed text can name any of them.
     *
     * @param  array<int, int>  $except  customers left out: a vendor's own
     *                                   linked account is the vendor themself
     * @return array<int, array{0: string, 1: string}>
     */
    public static function customerMarks(array $except = []): array
    {
        return self::partyMarks('customer', $except);
    }

    /**
     * Text for a vendor with every customer's name and number taken out: the
     * mirror of redactVendors(), for a vendor's statement.
     *
     * For one piece of text. A page of them builds customerRedactor() once.
     *
     * @param  array<int, array{0: string, 1: string}>|null  $marks  customerMarks(), when already read
     */
    public static function redactCustomers(?string $text, ?array $marks = null, string $with = '…'): ?string
    {
        return self::customerRedactor($marks ?? self::customerMarks(), [], $with)($text);
    }

    /**
     * What takes customers' names and numbers out of a vendor's text, made
     * once for a page.
     *
     * There are far more customers than vendors, so the names are matched a
     * batch at a time in one pattern rather than one pattern each — the
     * longest first within it, so a full name goes whole. And the patterns
     * are made once, not for every line. Found in review: made for every
     * cell, a long vendor's statement took seconds with a thousand customers
     * and ran out of time with a few thousand.
     *
     * What is in $keep is set aside before anything is cut and put back
     * after: the vendor's own name on their own statement, which a customer
     * sharing its first word would otherwise split — "Advance to … Kumar
     * Motors". Found in review, as was the vendor's own linked customer
     * account, which the caller leaves out of $marks.
     *
     * Where a pattern cannot be run, the text is not shown at all rather
     * than shown uncut.
     *
     * @param  array<int, array{0: string, 1: string}>  $marks
     * @param  array<int, string>  $keep  names left as they are
     * @return \Closure(?string): ?string
     */
    public static function customerRedactor(array $marks, array $keep = [], string $with = '…'): \Closure
    {
        $phrase = fn (string $name) => implode('\s+', array_map(fn ($word) => preg_quote($word, '/'), explode(' ', $name)));

        $longestFirst = function (array $names): array {
            $names = array_values(array_unique(array_filter($names, fn ($name) => $name !== '')));
            usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

            return $names;
        };

        $names = $longestFirst(array_map(fn ($mark) => $mark[1], array_filter($marks, fn ($mark) => $mark[0] === 'text')));
        $keptNames = $longestFirst(array_map(fn ($name) => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($name))), $keep));

        $batches = fn (array $names) => array_map(
            fn ($batch) => '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map($phrase, $batch)).')(?![\p{L}\p{N}])/iu',
            array_chunk($names, 100)
        );

        /*
         * A name with a kept one inside it goes before anything is set aside —
         * a customer "Rakesh Kumar Singh" beside a vendor "Rakesh Kumar" —
         * or setting the vendor's own name aside would keep the start of the
         * customer's, and the rest would match nothing. Found in making the
         * customer-side mirror of this.
         */
        $holds = fn (string $name) => (bool) array_filter($keptNames, fn ($kept) => $kept !== $name
            && preg_match('/(?<![\p{L}\p{N}])'.$phrase($kept).'(?![\p{L}\p{N}])/iu', $name));

        $first = $batches(array_values(array_filter($names, $holds)));
        $patterns = $batches(array_values(array_filter($names, fn ($name) => ! $holds($name))));

        $kept = array_map(fn ($name) => '/(?<![\p{L}\p{N}])'.$phrase($name).'(?![\p{L}\p{N}])/iu', $keptNames);

        $numbers = array_flip(array_map(fn ($mark) => $mark[1], array_filter($marks, fn ($mark) => $mark[0] === 'digits')));

        return function (?string $text) use ($first, $patterns, $kept, $numbers, $with): ?string {
            if ($text === null || trim($text) === '') {
                return $text;
            }

            foreach ($first as $pattern) {
                $text = preg_replace($pattern, $with, $text);

                if ($text === null) {
                    return $with;
                }
            }

            // Set aside under characters no name or number is made of.
            $aside = [];

            foreach ($kept as $pattern) {
                $text = preg_replace_callback($pattern, function ($found) use (&$aside) {
                    $aside[] = $found[0];

                    return "\u{E000}".mb_chr(0xE100 + count($aside) - 1)."\u{E001}";
                }, $text);

                if ($text === null) {
                    return $with;
                }
            }

            foreach ($patterns as $pattern) {
                $text = preg_replace($pattern, $with, $text);

                if ($text === null) {
                    return $with;
                }
            }

            if ($numbers) {
                $text = preg_replace_callback(self::NUMBER_RUN, function ($found) use ($numbers, $with) {
                    $digits = preg_replace('/\D/', '', $found[0]);

                    // Any ten in a row that are a customer's, with or without 91.
                    for ($at = 0; $at + 10 <= strlen($digits); $at++) {
                        if (isset($numbers[substr($digits, $at, 10)])) {
                            return $with;
                        }
                    }

                    return $found[0];
                }, $text);

                if ($text === null) {
                    return $with;
                }
            }

            return $aside
                ? preg_replace_callback('/\x{E000}(.)\x{E001}/u', fn ($found) => $aside[mb_ord($found[1]) - 0xE100] ?? '', $text) ?? $with
                : $text;
        };
    }

    /**
     * Text for a customer with every vendor's name and number taken out.
     *
     * For a line that has to stay on the page — a statement's particulars, a
     * file's details, a document's name, a note against a paper the customer
     * still has to bring — where leaving the whole line out would leave a gap
     * nobody could explain. Each name, first name and number is replaced; the
     * rest reads as typed. Loose notes, which can simply not be shown, go
     * through withoutVendors() instead.
     *
     * Found in review, after the timeline was put right: each of these is
     * typed by hand and reached the customer as typed.
     *
     * @param  array<int, array{0: string, 1: string}>|null  $marks  vendorMarks(), when already read
     */
    public static function redactVendors(?string $text, ?array $marks = null, string $with = '…'): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }

        $marks ??= self::vendorMarks();

        // The longest first, so a full name goes whole rather than leaving its
        // surname behind its first name's replacement.
        $names = array_map(fn ($mark) => $mark[1], array_filter($marks, fn ($mark) => $mark[0] === 'text'));
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            // Spaces in a name match any run of them, as withoutVendors reads it.
            $pattern = implode('\s+', array_map(fn ($word) => preg_quote($word, '/'), explode(' ', $name)));
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu', $with, $text);
        }

        $numbers = array_map(fn ($mark) => $mark[1], array_filter($marks, fn ($mark) => $mark[0] === 'digits'));

        if ($numbers) {
            $text = (string) preg_replace_callback(self::NUMBER_RUN, function ($found) use ($numbers, $with) {
                $digits = preg_replace('/\D/', '', $found[0]);

                foreach ($numbers as $number) {
                    if (str_contains($digits, $number)) {
                        return $with;
                    }
                }

                return $found[0];
            }, $text);
        }

        return $text;
    }

    /**
     * Text for a customer, or null when it names a vendor.
     *
     * The owner's rule is that no customer is told who does the work, and
     * free text is typed by people: a remark, a file's details, a note. What
     * names a vendor — by name, first name or number — is not shown at all,
     * rather than shown with the name cut out: cut, it can still say more
     * than it should, and the status beside it says where the file is.
     *
     * @param  array<int, array{0: string, 1: string}>|null  $marks  vendorMarks(), when already read
     */
    public static function withoutVendors(?string $text, ?array $marks = null): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $marks ??= self::vendorMarks();
        $plain = ' '.trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text))).' ';
        $digits = preg_replace('/\D/', '', $text);

        foreach ($marks as [$kind, $mark]) {
            if ($kind === 'digits' ? str_contains($digits, $mark)
                : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($mark, '/').'(?![\p{L}\p{N}])/u', $plain)) {
                return null;
            }
        }

        return $text;
    }

    /**
     * Everything that has happened to a file, as its customer may read it.
     *
     * Every movement is included — the question is "where has my file been",
     * and an entry left out to be safe is the entry somebody rings about. What
     * is filtered is not the entry but the wording: the status is said in the
     * customer's vocabulary and the remark goes through customerRemark above.
     */
    public static function customerTimeline(int $fileId, ?array $marks = null): array
    {
        $rows = DB::table('work_file_status_log')
            ->leftJoin('work_file_item', 'work_file_item.id', '=', 'work_file_status_log.work_file_item_id')
            ->leftJoin('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->where('work_file_status_log.work_file_id', $fileId)
            ->orderBy('work_file_status_log.created_at')
            ->orderBy('work_file_status_log.id')
            ->select(
                'work_file_status_log.id',
                'work_file_status_log.work_file_id',
                'work_file_status_log.from_status',
                'work_file_status_log.to_status',
                'work_file_status_log.remark',
                'work_file_status_log.event',
                'work_file_status_log.created_at',
                // Which work it was about, on a folder holding several. The
                // user who made the change is not selected: who in the office
                // touched a file is the office's business.
                'work_type.name as work_type'
            )
            ->get();

        $out = [];
        // The file's customer's own, when the caller read them.
        $marks ??= self::vendorMarks();

        foreach (self::withoutUndoneHandovers($rows) as $row) {
            $handover = $row->event === self::HANDED_OVER;
            $papers = $row->event === self::PAPERS;
            // Whether this entry leaves the customer something to bring in.
            $waiting = $papers && str_contains((string) $row->remark, 'Pending:');

            $out[] = [
                'id' => (int) $row->id,
                'date' => date('d-m-Y', strtotime($row->created_at)),
                'time' => date('h:i A', strtotime($row->created_at)),
                'from' => $row->from_status ? self::customerStatus($row->from_status) : null,
                // A handover moves nothing, so said as what happened rather than
                // as the status the file was already at.
                'to' => match (true) {
                    $handover => 'Papers handed over to you',
                    $waiting => 'Papers needed from you',
                    $papers => 'Papers complete',
                    default => self::customerStatus($row->to_status),
                },
                'tone' => match (true) {
                    $handover => 'approved',
                    $waiting => 'needs-you',
                    $papers => 'moving',
                    default => self::customerTone($row->to_status),
                },
                // Null when the entry is a note that did not move anything, so
                // the line can read as a note rather than as a move to where it
                // already was.
                'moved' => $row->from_status !== null && $row->from_status !== $row->to_status,
                'work_type' => $row->work_type,
                'remark' => self::customerRemark($row->remark, $marks),
            ];
        }

        return $out;
    }

    /**
     * The last thing said about each file, and the last time anything happened.
     *
     * Two separate questions answered from one pass, because they have
     * different answers: a file moved this morning with nothing typed has a
     * remark from last week and was updated today. Showing the older date
     * beside the older remark would say the file has not moved since.
     *
     * One query for the whole page. Read in order and overwritten, so what
     * survives per file is the newest of each.
     *
     * @param  array<int, int>  $fileIds
     * @return array<int, array{remark: ?string, remark_on: ?string, updated_on: ?string}>
     */
    public static function latestCustomerUpdates(array $fileIds, ?array $marks = null): array
    {
        if (! $fileIds) {
            return [];
        }

        $rows = DB::table('work_file_status_log')
            ->whereIn('work_file_id', $fileIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->select('work_file_id', 'remark', 'event', 'created_at')
            ->get();

        $out = [];

        // A handover taken back is neither the customer's latest news nor the
        // last time their file moved.
        foreach (self::withoutUndoneHandovers($rows) as $row) {
            $out[$row->work_file_id] ??= ['remark' => null, 'remark_on' => null, 'updated_on' => null];

            // Anything at all counts as the file having moved.
            $out[$row->work_file_id]['updated_on'] = $row->created_at;

            // The office's own additions are trimmed first, so an entry whose
            // only content was "Given to <vendor>" does not count as the last
            // thing anybody said.
            $remark = self::customerRemark($row->remark, $marks ??= self::vendorMarks());

            if ($remark !== null) {
                $out[$row->work_file_id]['remark'] = $remark;
                $out[$row->work_file_id]['remark_on'] = $row->created_at;
            }
        }

        return $out;
    }

    /**
     * The works on one file, and only what their customer may see.
     *
     * Written out for the same reason as forCustomer above: work_file_item
     * carries vendor_amount beside customer_amount, and the two together are
     * the margin on that work. This is the narrower of the two lists — the
     * office's own breakdown query fetches more and is used by office screens.
     */
    public static function customerWorks(int $fileId)
    {
        return DB::table('work_file_item')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->where('work_file_item.work_file_id', $fileId)
            ->orderBy('work_file_item.id')
            ->select(
                'work_file_item.id',
                'work_file_item.status',
                'work_file_item.approved_on',
                'work_file_item.customer_amount',
                'work_file_item.approval_screenshot',
                'work_type.name as work_type'
            )
            ->get();
    }

    /**
     * Whether a stored path is one of ours, before anything is read from disk.
     *
     * These paths come from our own rows, so this is not guarding against a
     * hostile value today. It guards against the day something else writes that
     * column: a path assembled from an upload name and handed to a file reader
     * is how a portal that serves a customer's own screenshot starts serving
     * whatever else the account can reach.
     */
    public static function isStoredUpload(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        /*
         * One of the two directories this application writes to, and nowhere
         * else. Checked as a list rather than a prefix per caller: a third
         * kind of upload should be refused here until it is added on purpose.
         */
        $ours = str_starts_with($path, self::UPLOAD_DIR.'/')
            || str_starts_with($path, self::DOC_DIR.'/');

        return $ours && is_file(public_path($path));
    }

    public static function workBreakdown(array $fileIds): array
    {
        if (! $fileIds) {
            return [];
        }

        $rows = DB::table('work_file_item')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->whereIn('work_file_item.work_file_id', $fileIds)
            ->orderBy('work_file_item.id')
            ->select(
                'work_file_item.work_file_id',
                'work_file_item.status',
                'work_file_item.approved_on',
                'work_type.name',
                // For screens that do something to a work rather than only
                // describe it: the id to address it by, and whether its
                // approval already has a document behind it.
                'work_file_item.id',
                'work_file_item.approval_screenshot',
                // Whose it is, when it went and whether it came back, for a
                // report asking one vendor's time.
                'work_file_item.vendor_id',
                'work_file_item.vendor_date',
                'work_file_item.vendor_returned_on'
            )
            ->get();

        $byFile = [];

        foreach ($rows as $row) {
            $byFile[$row->work_file_id][] = $row;
        }

        return $byFile;
    }

    /**
     * The works settled one way, in the order they were entered.
     *
     * @param  array<int, object>  $works
     * @return array<int, object>
     */
    private static function worksThat(array $works, string $status): array
    {
        return array_values(array_filter($works, fn ($work) => $work->status === $status));
    }

    /**
     * Everything still in hand, whatever stage it has reached.
     *
     * @param  array<int, object>  $works
     * @return array<int, object>
     */
    private static function worksOpen(array $works): array
    {
        return array_values(array_filter(
            $works,
            fn ($work) => ! in_array($work->status, [self::APPROVED, self::RETURNED, self::CANCELLED], true)
        ));
    }

    /**
     * "HPA approved · HPT, TR pending" — which way a folder's works disagree.
     *
     * "Partly Approved" says that they do, which is the thing worth knowing
     * from across the room, and then the next question is always which. On the
     * list there is no room for a stage each, so everything still in hand reads
     * as pending; the board is where the stages are.
     *
     * Nothing is returned for a folder of one work, or one whose works all
     * agree — the badge beside it already says that, and repeating it on every
     * row would bury the rows where it matters.
     *
     * @param  array<int, object>  $works
     */
    public static function workNote(array $works): ?string
    {
        $states = array_unique(array_map(fn ($work) => $work->status, $works));

        if (count($states) < 2) {
            return null;
        }

        $said = [];

        // Read in a fixed order, so the same folder does not describe itself
        // differently tomorrow because a work moved.
        foreach ([self::APPROVED => 'approved', self::RETURNED => 'returned', self::CANCELLED => 'cancelled'] as $status => $word) {
            $named = self::names(self::worksThat($works, $status));

            if ($named !== '') {
                $said[] = $named.' '.$word;
            }
        }

        $pending = self::names(self::worksOpen($works));

        if ($pending !== '') {
            $said[] = $pending.' pending';
        }

        return implode(' · ', $said);
    }

    /**
     * The same answer as three fields rather than a sentence, for a
     * spreadsheet — where a column can be sorted and filtered and a sentence
     * cannot.
     *
     * The dates line up with the names beside them, in the same order, so a
     * folder approved a week apart reads across: "HPA, TR" against
     * "21-08-2026, 28-08-2026".
     *
     * @param  array<int, object>  $works
     * @return array{done: string, approved_on: string, pending: string}
     */
    public static function workSplit(array $works): array
    {
        $approved = self::worksThat($works, self::APPROVED);

        return [
            'done' => self::names($approved),
            'approved_on' => implode(', ', array_map(
                fn ($work) => $work->approved_on ? date('d-m-Y', strtotime($work->approved_on)) : '—',
                $approved
            )),
            'pending' => self::names(self::worksOpen($works)),
        ];
    }

    /**
     * @param  array<int, object>  $works
     */
    private static function names(array $works): string
    {
        return implode(', ', array_unique(array_map(fn ($work) => $work->name, $works)));
    }
    /** Works given to a vendor with no rate yet; see VENDOR_WORK_UNPRICED. */
    private static function unpricedWorksColumn()
    {
        return DB::raw('(SELECT COUNT(*) FROM work_file_item AS vrs WHERE '.self::VENDOR_WORK_UNPRICED.') AS unpriced_works');
    }

    private static function unbilledWorksColumn()
    {
        $cancelled = self::CANCELLED;

        return DB::raw(<<<SQL
            (SELECT COUNT(*) FROM work_file_item
              WHERE work_file_item.work_file_id = work_file.id
                AND work_file_item.status <> '$cancelled'
                AND (work_file_item.customer_amount IS NULL OR work_file_item.customer_amount <= 0)
            ) AS unbilled_works
            SQL);
    }
    private static function workLabelColumn()
    {
        $cancelled = self::CANCELLED;

        return DB::raw(<<<SQL
            COALESCE((
                SELECT GROUP_CONCAT(item_type.name ORDER BY work_file_item.id SEPARATOR ', ')
                FROM work_file_item
                JOIN work_type AS item_type ON item_type.id = work_file_item.work_type_id
                WHERE work_file_item.work_file_id = work_file.id
                  AND work_file_item.status <> '$cancelled'
            ), work_type.name) AS work_type
            SQL);
    }

    /**
     * Who has this folder, for a list that reads rows rather than models.
     *
     * The same answer vendorLabel() gives, as SQL: a folder split between two
     * vendors has none of its own, and its column is null — so a list would
     * call it in-house work, which is the one thing it is not.
     */
    private static function vendorLabelColumn()
    {
        $cancelled = self::CANCELLED;

        return DB::raw(<<<SQL
            COALESCE(vendor.name, (
                SELECT CASE WHEN COUNT(DISTINCT vendor_item.vendor_id) > 1
                            THEN CONCAT(COUNT(DISTINCT vendor_item.vendor_id), ' vendors')
                       END
                FROM work_file_item AS vendor_item
                WHERE vendor_item.work_file_id = work_file.id
                  AND vendor_item.vendor_id IS NOT NULL
                  AND vendor_item.status <> '$cancelled'
            )) AS vendor_label
            SQL);
    }

    /**
     * A folder whose vendor has only part of it, as a where clause.
     *
     * It names a vendor, because roll-up names the one vendor its works went
     * to — but a live work on it went to nobody, or to somebody else. A
     * transfer given to Sharma and a hypothecation kept in the office is such a
     * folder: Sharma's, by its own vendor, and half of it is not.
     *
     * A folder none of whose works ever carried a vendor, cancelled ones
     * included, is not: it is from before works had their own, and its vendor
     * has all of it. Asked of every work, as Approval Time asks — asked of the
     * live ones only, a folder whose one given work was cancelled read as older,
     * and the work kept in the office went to the vendor.
     */
    private static function givenInPart($q): void
    {
        $q->whereNotNull('work_file.vendor_id')
            ->whereRaw('NOT '.self::OLDER_FOLDER)
            ->whereExists(fn ($other) => $other->select(DB::raw(1))
                ->from('work_file_item as other')
                ->whereColumn('other.work_file_id', 'work_file.id')
                ->where('other.status', '<>', self::CANCELLED)
                ->where(fn ($not) => $not->whereNull('other.vendor_id')
                    ->orWhereColumn('other.vendor_id', '<>', 'work_file.vendor_id')));
    }

    /**
     * @param  bool  $folderStatus  vendor-wise, narrow on the folder's own status
     *                              rather than that of the vendor's works; see below
     */
    public static function report(string $partyType, $partyId = null, ?string $status = null, ?string $from = null, ?string $to = null, bool $folderStatus = false)
    {
        $isVendor = $partyType === 'vendor';

        $query = DB::table('work_file')
            ->join('work_type', 'work_type.id', '=', 'work_file.work_type_id')
            ->join('party as customer', 'customer.id', '=', 'work_file.customer_id')
            ->leftJoin('party as vendor', 'vendor.id', '=', 'work_file.vendor_id')
            ->select(
                'work_file.id',
                'work_file.file_no',
                'work_file.received_date',
                'work_file.registration_no',
                'work_file.status',
                'work_file.description',
                'work_file.customer_amount',
                'work_file.returned_amount',
                // Selected because awaitingPrice() needs to know whether there is a
                // vendor at all — an in-house file has no rate to agree and never will.
                'work_file.vendor_id',
                'work_file.vendor_amount',
                'work_file.vendor_returned_on',
                'work_file.vendor_returned_amount',
                // The day it went out, for the days-out figure beside it —
                // and the day it finished, for the same figure once it is over.
                'work_file.vendor_date',
                DB::raw(self::FINISHED_ON.' as finished_on'),
                self::workLabelColumn(),
                self::unpricedWorksColumn(),
                self::unbilledWorksColumn(),
                // What the office paid out of its own till, so rowTotals can
                // add it to the cost without a query per row.
                DB::raw(self::PAID_OUT.' as expenses'),
                'customer.name as customer_name',
                'vendor.name as vendor_name',
                DB::raw(($isVendor ? 'vendor.id' : 'customer.id').' as party_id'),
                DB::raw(($isVendor ? 'vendor.name' : 'customer.name').' as party_name'),
                /*
                 * Where a list of these files can be sent; see WorkReport.vue.
                 * Their WhatsApp number when one is saved, and the mobile when
                 * not — the rule the statement and the reminders follow, so the
                 * same vendor is never messaged on two different numbers.
                 */
                DB::raw($isVendor
                    ? "COALESCE(NULLIF(vendor.whatsapp, ''), vendor.mobile) as party_mobile"
                    : "COALESCE(NULLIF(customer.whatsapp, ''), customer.mobile) as party_mobile")
            );

        // The status and the period, said once for both halves of a vendor
        // report — though the half below is not always asked its status here.
        $byStatus = function ($q) use ($status) {
            if ($status === 'open') {
                $q->whereIn('work_file.status', self::OPEN_STATUSES);
            } elseif ($status && array_key_exists($status, self::STATUSES)) {
                $q->where('work_file.status', $status);
            }
        };

        $byDate = function ($q) use ($from, $to) {
            if ($from) {
                $q->whereDate('work_file.received_date', '>=', $from);
            }

            if ($to) {
                $q->whereDate('work_file.received_date', '<=', $to);
            }
        };

        // Copied before the folder's own vendor is asked about, because a
        // folder split between two vendors has none.
        $split = $isVendor ? clone $query : null;

        if ($isVendor) {
            // Whole, only where the folder's vendor has all of it; the rest is
            // drawn below, a vendor's own works at a time.
            $query->whereNotNull('work_file.vendor_id')
                ->whereNot(fn ($q) => self::givenInPart($q));
        }

        if ($partyId) {
            $query->where($isVendor ? 'work_file.vendor_id' : 'work_file.customer_id', $partyId);
        }

        $byStatus($query);
        $byDate($query);

        $rows = $query
            ->orderBy('party_name', 'asc')
            ->orderBy('work_file.received_date', 'asc')
            ->orderBy('work_file.id', 'asc')
            ->get();

        if (! $isVendor) {
            return $rows;
        }

        /*
         * And the folders split between vendors, which the query above cannot
         * see.
         *
         * It asks for work_file.vendor_id, and a folder whose works went to two
         * agents has none of its own — roll-up clears it rather than name one
         * and put the other's work on his statement. So a vendor holding one
         * work of a split folder was missing it from their list here, and from
         * the list the office now sends them on WhatsApp.
         *
         * Each such folder is drawn once under every vendor holding any of it,
         * showing only that vendor's works.
         *
         * And so is a folder its vendor holds only part of. Asked for by the
         * owner on 2026-09-28: F-00089's hypothecation went to a vendor and its
         * transfer to nobody, and the vendor's list said "HPT, TR" — on the
         * screen, on the WhatsApp list the office sends him, with the whole
         * folder's charge and the transfer's standing as its status.
         *
         * Folders whose vendor has all of them are left exactly as they were.
         */
        $split->where(fn ($q) => $q
            ->where(fn ($none) => $none->whereNull('work_file.vendor_id')
                ->whereExists(fn ($given) => $given->select(DB::raw(1))
                    ->from('work_file_item')
                    ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                    ->whereNotNull('work_file_item.vendor_id')
                    ->where('work_file_item.status', '<>', self::CANCELLED)))
            ->orWhere(fn ($part) => self::givenInPart($part)));

        if ($partyId) {
            $split->whereExists(fn ($theirs) => $theirs->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                ->where('work_file_item.vendor_id', $partyId)
                ->where('work_file_item.status', '<>', self::CANCELLED));
        }

        $byDate($split);

        /*
         * A vendor's row stands where their works stand, not where the
         * folder does — a folder waiting on work kept in the office is In
         * Office, with the vendor's part long since approved. So the status
         * asked for is asked of each row once it is drawn. Approval Time
         * asks the folder's instead: it counts approved files, which is
         * what the owner chose it to count.
         */
        if ($folderStatus) {
            $byStatus($split);
        }

        $shares = self::splitByVendor($split->get(), $partyId);

        if (! $folderStatus && $status === 'open') {
            $shares = $shares->filter(fn ($row) => in_array($row->status, self::OPEN_STATUSES, true))->values();
        } elseif (! $folderStatus && $status && array_key_exists($status, self::STATUSES)) {
            $shares = $shares->filter(fn ($row) => $row->status === $status)->values();
        }

        // Nothing split, nothing to change: the report is what it always was.
        if ($shares->isEmpty()) {
            return $rows;
        }

        /*
         * Back into the order the query gave, which is what groups the bands.
         * Names compared without case, as the database's collation compares
         * them — compared byte by byte, "suman" and "Test" would swap and every
         * existing band after them would move.
         */
        return $rows->concat($shares)
            ->sort(fn ($a, $b) => [mb_strtolower((string) $a->party_name), $a->received_date, $a->id]
                <=> [mb_strtolower((string) $b->party_name), $b->received_date, $b->id])
            ->values();
    }

    /**
     * A folder its vendors share — with each other, or with the office — as
     * one report row per vendor holding any of it.
     *
     * Each row is the folder narrowed to that vendor's works: what the
     * customer is charged for them, what that vendor is owed for them, the day
     * the first of them went out, and where they stand — a vendor whose part is
     * approved is not still at work because the office's part is. Summed, the
     * rows come to the folder's given works once, which is what keeps a report
     * total from counting a split folder twice. Work kept in the office is
     * under no vendor, as a folder kept wholly in the office always was.
     *
     * The office's own expenses belong to the file and not to either vendor,
     * so they are shared in proportion to each work's charge — the office's
     * own works taking their share, which appears under nobody: shared among
     * the vendors alone, a vendor holding one work of three was given the
     * whole folder's expenses, and a margin on their work it never had. So is
     * a refund, a folder
     * going back to its customer whole. An amount a vendor returned is only
     * ever the vendors'. The last share takes the rounding, so the shares
     * always add up to the paisa.
     *
     * Each row carries the ids of its works, so the screen shows and moves
     * those and not the whole folder's.
     *
     * @param  \Illuminate\Support\Collection  $folders  rows from report()'s own select
     */
    private static function splitByVendor($folders, $onlyVendor = null)
    {
        if ($folders->isEmpty()) {
            return collect();
        }

        $works = self::liveWorks($folders->pluck('id')->all());

        $out = collect();

        foreach ($folders as $folder) {
            foreach (self::holderRows($folder, $works[$folder->id] ?? collect()) as $vendorId => $row) {
                if (! $onlyVendor || (int) $vendorId === (int) $onlyVendor) {
                    $out->push($row);
                }
            }
        }

        return $out;
    }

    /**
     * A folder's parts, one per holder: each vendor with live works on it,
     * and — asked for — the office, for the live works given to nobody.
     *
     * Each part is the folder narrowed to that holder's works, in the shape a
     * report row has, so rowTotals() reads it as it reads a whole folder:
     * their charge and rates, their dates and standing, their share of the
     * office's expenses and of a refund, what came back of their rates. The
     * vendor-wise Work Report draws the vendors' parts; the Profit report's
     * vendor cut adds up every holder's, the office's under In-house — one
     * arithmetic, so the two cannot disagree about a vendor.
     *
     * @param  object  $folder  a row of report()'s select, or one with the same money columns
     * @param  \Illuminate\Support\Collection  $live  the folder's live works, as splitByVendor() reads them
     * @return array<int|string, object> vendor id => part, and 'office' => part when asked
     */
    private static function holderRows(object $folder, $live, bool $withOffice = false): array
    {
        $byVendor = $live->filter(fn ($w) => $w->vendor_id)->groupBy('vendor_id');
        $office = $live->reject(fn ($w) => $w->vendor_id);

        if ($byVendor->isEmpty() && ! $withOffice) {
            return [];
        }

        /*
         * Each vendor's weight is what the customer is charged for their
         * works; the office's, for the works it kept — or, while nothing
         * is charged yet, how many works each holds. The office is weighed
         * first, so the rounding falls to a vendor. Found in review: last,
         * a kept work charged nothing took a share of -0.01, and the
         * vendors' shares came to a paisa more than was spent.
         */
        $holdings = ($office->isEmpty() ? [] : ['office' => $office]) + $byVendor->all();

        if (! $holdings) {
            return [];
        }

        $weights = array_map(fn ($some) => $some->sum(fn ($w) => (float) $w->customer_amount), $holdings);

        if (array_sum($weights) <= 0) {
            $weights = array_map(fn ($some) => $some->count(), $holdings);
        }

        $vendors = array_diff_key($weights, ['office' => true]);

        $expenses = self::apportion((float) ($folder->expenses ?? 0), $weights);
        $refund = $folder->returned_amount === null ? null : self::apportion((float) $folder->returned_amount, $weights);
        $sentBack = ($folder->vendor_returned_amount === null || ! $vendors) ? null : self::apportion((float) $folder->vendor_returned_amount, $vendors);

        $out = [];

        foreach ($holdings as $holder => $mine) {
            if ($holder === 'office' && ! $withOffice) {
                continue;
            }

            $first = $mine->first();
            $priced = $mine->filter(fn ($w) => $w->vendor_amount !== null);

            $row = clone $folder;

            if ($holder === 'office') {
                // Work nobody was given: no vendor, nothing handed back, and no
                // vendor's rate to wait on — a rate typed on it is the office's cost.
                $row->vendor_id = null;
                $row->vendor_name = null;
                $row->party_id = 0;
                $row->party_name = 'In-house';
                $row->party_mobile = null;
            } else {
                $row->vendor_id = (int) $holder;
                $row->vendor_name = $first->vendor_name;
                $row->party_id = (int) $holder;
                $row->party_name = $first->vendor_name;
                // As the query above says it for every other folder.
                $row->party_mobile = $first->vendor_whatsapp ?: $first->vendor_mobile;
            }

            $row->customer_amount = round($mine->sum(fn ($w) => (float) $w->customer_amount), 2);
            $row->vendor_amount = $priced->isEmpty() ? null : round($priced->sum(fn ($w) => (float) $w->vendor_amount), 2);

            // Out since the first of these went; back once all of them are.
            $row->vendor_date = $mine->pluck('vendor_date')->filter()->min();
            $row->vendor_returned_on = $mine->contains(fn ($w) => ! $w->vendor_returned_on)
                ? null
                : $mine->pluck('vendor_returned_on')->filter()->max();

            $row->work_type = $mine->pluck('work')->filter()->implode(', ');

            /*
             * Where these works stand, and the day they were through —
             * which the days under the dispatch date count to, on the
             * screen and on the list the vendor is sent. The folder's
             * status is passed so a returned folder stays returned; a
             * return is always of the whole folder, so its day is the
             * folder's.
             */
            $row->status = self::holderStatus($mine, $folder->status);

            $row->finished_on = match ($row->status) {
                self::APPROVED => $mine->pluck('approved_on')->filter()->max(),
                self::RETURNED => $folder->finished_on,
                default => null,
            };

            // So awaitingPrice() asks about these works, not the whole
            // folder's — and not one handed back, which will never have a
            // rate, nor one nobody was given, which has none to agree.
            $row->unpriced_works = $holder === 'office' ? 0 : $mine->filter(fn ($w) => ! $w->vendor_returned_on
                && ($w->vendor_amount === null || (float) $w->vendor_amount <= 0))->count();
            $row->unbilled_works = $mine->filter(fn ($w) => $w->customer_amount === null || (float) $w->customer_amount <= 0)->count();

            $row->expenses = $expenses[$holder];
            $row->returned_amount = $refund === null ? null : $refund[$holder];

            /*
             * What came back of their rates, from their own works: found
             * in review, a part typed for one vendor was shared by charge
             * over both, and the other's cost dropped for work they still
             * had. Before the works could carry a part, the folder's
             * figure, shared as it was. The office's own rates are never
             * handed back.
             */
            $back = $mine->filter(fn ($w) => $w->vendor_id && $w->vendor_returned_on);
            $row->vendor_returned_amount = match (true) {
                $holder === 'office' => null,
                ! WorkFileItemModel::partReversals() => $sentBack === null ? null : $sentBack[$holder],
                $back->isEmpty() => null,
                default => round($back->sum(fn ($w) => self::returnedPortion($w->vendor_returned_amount, $w->vendor_amount)), 2),
            };

            $row->split_item_ids = $mine->pluck('id')->map(fn ($id) => (int) $id)->all();

            $out[$holder] = $row;
        }

        return $out;
    }

    /**
     * Where one holder's works on a folder stand: statusFromItems() of theirs,
     * the folder's status passed so a returned folder stays returned.
     *
     * Returned only while the folder is: the refund is recorded on the
     * folder, and cleared from it the moment it is not. Found in review: a
     * folder returned, then one of its works brought back, left the vendor's
     * works reading returned — billed nothing, against a refund the ledger no
     * longer holds. Such a part reads as the folder does.
     */
    public static function holderStatus($works, string $folderStatus): string
    {
        $status = self::statusFromItems(collect($works), $folderStatus);

        return $status === self::RETURNED && $folderStatus !== self::RETURNED ? $folderStatus : $status;
    }

    /**
     * Where one holder's works stand, as the status board shows them: what the
     * works say — a part all of whose standing works went back to the customer
     * reads Returned, whatever the folder does since.
     *
     * Not holderStatus(), whose "as the folder does" keeps a report from
     * billing a refund the ledger no longer holds. Found in review: on the
     * board, a vendor's returned work on a folder partly brought back read as
     * In Office — counted under In Hand, where the board then had no row of
     * theirs to show. The board's own mirror is boardStatusSql().
     */
    public static function boardStatus($works, string $folderStatus): string
    {
        return self::statusFromItems(collect($works), self::RETURNED);
    }

    /**
     * A total shared out in proportion to some weights, to the paisa.
     *
     * Every share but the last is rounded; the last is whatever is left, so
     * the shares always add back up to exactly the total. Equal shares when
     * nothing has any weight at all, rather than dividing by nothing.
     *
     * @param  array<int|string, float>  $weights
     * @return array<int|string, float>
     */
    private static function apportion(float $total, array $weights): array
    {
        $keys = array_keys($weights);
        $sum = array_sum($weights);
        $out = [];
        $given = 0.0;

        foreach ($keys as $n => $key) {
            if ($n === count($keys) - 1) {
                $out[$key] = round($total - $given, 2);

                break;
            }

            $share = $sum > 0
                ? round($total * $weights[$key] / $sum, 2)
                : round($total / count($keys), 2);

            $out[$key] = $share;
            $given += $share;
        }

        return $out;
    }

    /**
     * What one row of the report is actually worth, once returns and
     * cancellations are taken into account. Kept here so the report, the file
     * list and the dashboard cannot each answer it differently.
     *
     * @return array{billed: float, cost: float, margin: float}
     */
    /**
     * Whether a price is still to be agreed on either side of a file.
     *
     * Cancelled and returned files are settled: a cancelled file charged
     * nobody and a returned one was charged and refunded, so nothing about
     * either is outstanding.
     */
    public static function awaitingPrice($row): bool
    {
        if (in_array($row->status, [self::CANCELLED, self::RETURNED], true)) {
            return false;
        }

        /*
         * Asked of the works, because that is where a figure is agreed now.
         * The folder's total says nothing about a folder that is half
         * priced: 1,200 agreed on one work and nothing on the other totals
         * 1,200, and reads as settled.
         *
         * The folder is the fallback for a caller that did not select the
         * counts — the answer it gives is the old one, which is right for
         * the file of one work that most of them are.
         */
        /*
         * Asked of the folder and of the works, and outstanding if either says
         * so. Never one instead of the other:
         *
         * The folder's total cannot see a folder that is half priced — 1,200
         * agreed on one work and nothing on the other totals 1,200, and reads
         * as settled. The works cannot see a folder that has none, which is not
         * a state receiving can produce but is exactly the row this report
         * should not pass over in silence.
         *
         * Coalesced, because rowTotals is called with rows from several
         * different queries and not all of them select the counts. Without
         * them the answer is the old one, which is right for the file of one
         * work that most of them are.
         */
        $unbilled = (float) ($row->customer_amount ?? 0) <= 0
            || (int) ($row->unbilled_works ?? 0) > 0;

        /*
         * The folder's vendor with no figure, or a work given to a vendor with
         * none — counted by VENDOR_WORK_UNPRICED, so work done in-house is not
         * asked for a rate, and a folder split between two vendors, naming
         * neither, still is for the one it lacks.
         */
        // Not once it is back from its vendor: nothing was agreed for work
        // handed back, and nothing will be (found in review, 2026-09-29).
        $unpriced = (($row->vendor_id ?? null) !== null && ($row->vendor_returned_on ?? null) === null
                && (float) ($row->vendor_amount ?? 0) <= 0)
            || (int) ($row->unpriced_works ?? 0) > 0;

        return $unbilled || $unpriced;
    }

    /**
     * What a file earned, what it cost, and the difference — where there is one.
     *
     * The margin is null while either price is still to be agreed, because a
     * difference between a figure and a blank is not a margin. Subtracting
     * anyway reported a file given to a vendor at 5,000 and not yet billed as a
     * loss of 5,000, and summed those into a report total that said the
     * business was down money it had simply not invoiced. It is unknown, not
     * negative, and the report says so by leaving the cell empty.
     *
     * Billed and cost stay as they are. Those are facts about what has
     * happened; only their difference is the thing that cannot be known yet.
     */
    /**
     * Recompute what the file says from the jobs on it.
     *
     * The customer is charged per job, so the file's figure is their sum —
     * and that sum is what reaches their statement, which is why this runs
     * before syncLedger and not after.
     *
     * The vendor cost is a sum too, but stays null while every job is still
     * unpriced: null means "not agreed" everywhere else in this application
     * and a file whose vendor rates are all outstanding has not agreed one.
     * Summing them to zero would post nothing while claiming a figure.
     *
     * work_type_id keeps pointing at the first job. Every screen still reads
     * it, and a file with two jobs has no single type — workLabel() is what
     * says the whole truth, and the screens move onto it as they are touched.
     */
    /**
     * What the folder's status is, given the state of the jobs in it.
     *
     * Approvals arrive separately, so the interesting case is the mixed one:
     * a hypothecation addition through while the transfer on the same papers
     * is not. Calling that approved claims work that is not done; calling it
     * pending hides work that is. It is partly approved, and the file is still
     * in hand.
     *
     * While jobs are still open and none has been approved, the file shows the
     * least advanced of them — the one actually holding the folder up, which
     * is what someone looking at the list wants to know.
     *
     * With nothing open left: all cancelled is cancelled, all returned is
     * returned, and anything else means work was done and approved.
     */
    /**
     * What this folder's jobs may be set to on the board.
     *
     * One job means the folder is that job, so returning it returns the
     * papers and the old one-click return still works. Two jobs and the
     * option goes: half an envelope cannot go back, and a folder left with
     * one job returned and one approved would bill the customer for work
     * they have in their hand.
     */
    public static function jobStatusesFor(int $jobCount): array
    {
        $statuses = self::JOB_STATUSES;

        if ($jobCount > 1) {
            unset($statuses[self::RETURNED]);
        }

        return $statuses;
    }

    /**
     * What the edit screen was drawn from, as one short string.
     *
     * The edit form posts every field it shows — the status among them, and
     * for a folder of one work that status is written straight through to the
     * work. So a page left open while a colleague returned the file, approved
     * it, or corrected its price posted the old values back over theirs: the
     * return undone and its refund taken off the ledger, the approval's date
     * wiped, the price put back.
     *
     * The page carries this from when it was drawn and the save compares it
     * with the file as it is now. It covers what the form can overwrite — the
     * file's own fields, each work's status, type, prices, vendor and in-house
     * mark, the return, the expenses and the names given to its documents —
     * the name is what the customer sees a PDF as — and nothing that merely touches
     * updated_at, so a save is refused because the file changed and never
     * because something brushed past it.
     */
    public function editFingerprint(): string
    {
        $money = fn ($value) => $value === null ? null : number_format((float) $value, 2, '.', '');
        $day = fn ($value) => $value ? date('Y-m-d', strtotime((string) $value)) : null;
        $id = fn ($value) => $value ? (int) $value : null;

        $works = $this->items()->orderBy('id')->get()->map(fn ($item) => [
            (int) $item->id,
            (string) $item->status,
            $id($item->work_type_id),
            $money($item->customer_amount),
            $money($item->vendor_amount),
            $id($item->vendor_id),
            $day($item->vendor_date),
            $day($item->vendor_returned_on),
            $day($item->kept_in_house_on),
            $day($item->approved_on),
        ])->all();

        $expenses = $this->expenses()->orderBy('id')->get()->map(fn ($expense) => [
            (int) $expense->id,
            $id($expense->expense_type_id),
            $money($expense->amount),
            $day($expense->spent_on),
            (string) $expense->remark,
        ])->all();

        // The name each document goes by. The form sends back every box, and a
        // name a colleague gave since would otherwise be put back as it was.
        $documents = $this->documents()->orderBy('id')->get()->map(fn ($doc) => [
            (int) $doc->id,
            (string) $doc->title,
        ])->all();

        return sha1(json_encode([
            'file_no' => (string) $this->file_no,
            'received' => $day($this->received_date),
            'type' => $id($this->work_type_id),
            'registration' => (string) $this->registration_no,
            'customer' => $id($this->customer_id),
            'charged' => $money($this->customer_amount),
            'vendor' => $id($this->vendor_id),
            'cost' => $money($this->vendor_amount),
            'vendor_date' => $day($this->vendor_date),
            'vendor_returned_on' => $day($this->vendor_returned_on),
            'status' => (string) $this->status,
            'returned_on' => $day($this->returned_on),
            'returned_amount' => $money($this->returned_amount),
            'description' => (string) $this->description,
            'remarks' => (string) $this->remarks,
            'works' => $works,
            'expenses' => $expenses,
            'documents' => $documents,
        ]));
    }

    /**
     * @param  string|null  $current  the folder's status now, so a return can be kept
     */
    public static function statusFromItems($items, ?string $current = null): string
    {
        $open = $items->filter(fn ($item) => in_array($item->status, self::OPEN_STATUSES, true));
        $approved = $items->filter(fn ($item) => $item->status === self::APPROVED);

        if ($open->isNotEmpty()) {
            if ($approved->isNotEmpty()) {
                return self::PARTLY_APPROVED;
            }

            // OPEN_STATUSES is in the order work moves through, so the first
            // one present is the furthest back.
            foreach (self::OPEN_STATUSES as $stage) {
                if ($open->contains(fn ($item) => $item->status === $stage)) {
                    return $stage;
                }
            }
        }

        if ($items->every(fn ($item) => $item->status === self::CANCELLED)) {
            return self::CANCELLED;
        }

        if ($items->every(fn ($item) => $item->status === self::RETURNED)) {
            return self::RETURNED;
        }

        /*
         * A returned folder with a cancelled work on it stays returned.
         *
         * Return to Customer sends back the works still standing and leaves a
         * cancelled one cancelled — it was charged nothing, so there is nothing
         * to give back. Counted in above, that cancelled work made such a
         * folder read as Approval Done the next time anything on it was saved,
         * even a remark: the return's date was cleared, the refund taken off
         * the ledger, and the customer charged again for papers in their hand.
         *
         * Stays, and never becomes. Papers go back a whole folder at a time,
         * through Return to Customer, which records the day and the amount
         * agreed. A roll-up that turned a folder into a return would have to
         * make both up — today, and the whole charge — and the folders it
         * would do it to are the ones the old rule already un-returned, whose
         * agreed refund is recorded nowhere now. Those are left as they are
         * for the office to put right; files:audit names them.
         */
        $standing = $items->reject(fn ($item) => $item->status === self::CANCELLED);

        if ($current === self::RETURNED && $standing->every(fn ($item) => $item->status === self::RETURNED)) {
            return self::RETURNED;
        }

        return self::APPROVED;
    }

    public function rollUp(): void
    {
        $items = $this->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $this->work_type_id = $items->first()->work_type_id;

        /*
         * A cancelled job stops counting, exactly as a cancelled file always
         * has: it was entered in error and charges nobody. Leaving it in the
         * sum would keep billing the customer for work struck off the folder.
         */
        $live = $items->reject(fn ($item) => $item->status === self::CANCELLED);

        $this->customer_amount = round($live->sum(fn ($item) => (float) $item->customer_amount), 2);

        $priced = $live->filter(fn ($item) => $item->vendor_amount !== null);
        $this->vendor_amount = $priced->isEmpty()
            ? null
            : round($priced->sum(fn ($item) => (float) $item->vendor_amount), 2);

        /*
         * The folder's vendor is now its works' vendor.
         *
         * One vendor while they agree, none while they do not — a folder split
         * between two of them has no single vendor, and naming one of them on
         * the folder would put the other's work on his statement. The date is
         * the earliest of theirs: the day this folder started being out.
         *
         * Only from works that carry one. A folder whose works never had a
         * vendor keeps whatever was set on it, so the screens that still write
         * the folder direct are not undone by the next save. One whose only
         * given work was cancelled names nobody now: found on 2026-09-28, it
         * kept naming that vendor, and every list that reads the folder's
         * vendor put the office's own work under his name.
         */
        if ($from = self::vendorFromWorks($items)) {
            $this->vendor_id = $from['vendor_id'];
            $this->vendor_date = $from['vendor_date'];
            $this->vendor_returned_on = $from['vendor_returned_on'];
        }

        // And what came back of their rates, as the works say it: the figure
        // is theirs now, and the folder's is the sum; see reversalFromWorks().
        if (WorkFileItemModel::partReversals() && ! self::isOlderFolder($items)) {
            $this->vendor_returned_amount = $this->reversalFromWorks($items);
        }

        // What it is now, so a return is kept; see statusFromItems().
        $this->status = self::statusFromItems($items, $this->status);

        /*
         * Evidence belongs to the job that was approved, but the files list
         * still offers one link per folder — so the folder points at the first
         * approval it has. A folder with two approvals has two documents and
         * both are reachable from its own screen; this is the shortcut for the
         * list, not the record.
         */
        $this->approval_screenshot = $items
            ->firstWhere(fn ($item) => $item->status === self::APPROVED && $item->approval_screenshot)
            ?->approval_screenshot;
    }

    /**
     * Every job on the file, named. "HPA, TR" rather than a made-up work type
     * called HPA + TR, which is what the list had to hold before.
     */
    /**
     * The works on this file as a party paying for them knows them.
     *
     * Never a cancelled one — the charge on the statement leaves those out.
     * For a vendor, only the works they were given: a folder split between two
     * vendors is none of the other's business. A folder whose vendor is set on
     * the folder and on none of its works (some screens still save it so) is
     * that vendor's in full. Found in review: the two places that name works
     * against a payment disagreed about both.
     */
    public function worksFor(?int $vendorId = null): string
    {
        return $this->itemsFor($vendorId)->map(fn ($item) => $item->workType?->name)->filter()->implode(', ');
    }

    /**
     * The works on this file that are not cancelled — for a vendor, only the
     * ones they were given. A file from before works carried their own vendor
     * is the folder's vendor's, all of it; see isOlderFolder().
     */
    public function itemsFor(?int $vendorId = null)
    {
        $live = $this->items->reject(fn ($item) => $item->status === self::CANCELLED);

        if ($vendorId === null) {
            return $live;
        }

        if (self::isOlderFolder($this->items)) {
            return (int) $this->vendor_id === $vendorId ? $live : $live->take(0);
        }

        return $live->where('vendor_id', $vendorId);
    }

    public function workLabel(): string
    {
        return $this->items->map(fn ($item) => $item->workType?->name)->filter()->implode(', ');
    }

    public static function rowTotals($row): array
    {
        $billed = self::netCustomer($row->status, $row->customer_amount, $row->returned_amount);

        /*
         * The vendor's share, and the office's own.
         *
         * A row that was not asked for its expenses reports none rather than
         * guessing: a query that has not selected them cannot say whether the
         * answer is nothing or unknown, and inventing a zero would understate a
         * cost rather than leave it visibly absent.
         */
        $paidOut = (float) ($row->expenses ?? 0);

        $cost = self::netVendor($row->status, $row->vendor_amount, $row->vendor_returned_on !== null, $row->vendor_returned_amount)
            + $paidOut;

        return [
            'billed' => $billed,
            'cost' => $cost,
            'expenses' => $paidOut,
            'margin' => self::awaitingPrice($row) ? null : $billed - $cost,
        ];
    }

    /**
     * Files for the list, with the names behind every id resolved in one query.
     */
    /**
     * The three ways a file can be waiting on a price.
     *
     * A file may be taken in and given to a vendor before either figure is
     * agreed — the ledgers stay quiet until there is something to post, which is
     * correct, and is also why an unpriced file is invisible until someone goes
     * looking. These are what "go looking" means.
     */
    public const PENDING = [
        'customer' => 'Not billed to the customer',
        'vendor' => 'Vendor rate not agreed',
        'any' => 'Any price outstanding',
    ];

    /**
     * Narrows a query to files still waiting on a price.
     *
     * Cancelled and returned files are never included. A cancelled file is not
     * owed for and a returned one has been settled and handed back, so both
     * would read as work needing attention when there is none.
     */
    private static function pendingWhere($query, string $which): void
    {
        $query->whereNotIn('work_file.status', [self::CANCELLED, self::RETURNED]);

        /*
         * The test is the one syncSide() uses to decide there is nothing to
         * post: no party, or an amount of zero or less. Anything it declines to
         * post is money not yet on a statement, and that is exactly what this
         * report is for — so the two have to agree, or a file falls between
         * them and is reported by neither.
         *
         * It did. A vendor amount left blank stores null and was reported; a
         * vendor amount typed as 0 stores 0.00, posted nothing, and was
         * reported by nothing.
         */
        /*
         * The folder's own figure, or any work on it without one. Either makes
         * a file outstanding, for the reasons set out in awaitingPrice(): the
         * folder cannot see a half priced file, and the works cannot see a
         * folder that has none.
         */
        $short = fn ($q, string $table, string $column) => $q
            ->where(fn ($v) => $v->whereNull("$table.$column")->orWhere("$table.$column", '<=', 0));

        $anyWorkShort = fn (string $column) => fn ($w) => $short(
            $w->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                ->where('work_file_item.status', '<>', self::CANCELLED),
            'work_file_item',
            $column
        );

        $outstanding = fn ($q, string $column) => $q->where(
            fn ($o) => $short($o, 'work_file', $column)->orWhereExists($anyWorkShort($column))
        );

        $unbilled = fn ($q) => $outstanding($q, 'customer_amount');

        // Only work given to a vendor — work done in-house has no rate to
        // agree and never will. The same test as the list's; see VENDOR_UNPRICED.
        $unpriced = fn ($q) => $q->whereRaw(self::VENDOR_UNPRICED);
        match ($which) {
            'customer' => $unbilled($query),
            'vendor' => $unpriced($query),
            default => $query->where(fn ($q) => $unbilled($q)->orWhere($unpriced)),
        };
    }

    /**
     * How many files are waiting on each kind of price.
     *
     * One query per kind rather than one grouped query, because a file can be
     * waiting on both and would otherwise be counted once and reported twice.
     *
     * @return array{customer: int, vendor: int, any: int}
     */
    public static function pendingCounts(): array
    {
        $counts = [];

        foreach (array_keys(self::PENDING) as $which) {
            $query = DB::table('work_file');
            self::pendingWhere($query, $which);
            $counts[$which] = $query->count();
        }

        return $counts;
    }

    /**
     * How long the longest-waiting file has been waiting, in days.
     *
     * A count on its own does not separate three files priced tomorrow from
     * three nobody has looked at since last month, and those are not the same
     * situation. Null when nothing is waiting.
     */
    public static function longestWaitingDays(): ?int
    {
        $query = DB::table('work_file');
        self::pendingWhere($query, 'any');

        $oldest = $query->min('work_file.received_date');

        if (! $oldest) {
            return null;
        }

        /*
         * From the received date to today, in that order: Carbon signs the
         * difference by direction, so the operands the other way round make
         * every age negative — and clamping that at zero would report every
         * file as received today, which is the exact reassurance this figure
         * exists to withhold.
         *
         * Whole days, and never negative: a file received today has waited
         * nothing rather than a fraction, and a date typed slightly in the
         * future is a slip rather than a negative wait.
         */
        return max(0, (int) Carbon::parse($oldest)->startOfDay()->diffInDays(
            now()->startOfDay(),
            absolute: false
        ));
    }

    public static function listing(?string $status = null, ?string $from = null, ?string $to = null, ?string $pending = null)
    {
        $query = DB::table('work_file')
            ->join('work_type', 'work_type.id', '=', 'work_file.work_type_id')
            ->join('party as customer', 'customer.id', '=', 'work_file.customer_id')
            ->leftJoin('party as vendor', 'vendor.id', '=', 'work_file.vendor_id')
            ->select(
                'work_file.id',
                'work_file.file_no',
                'work_file.received_date',
                'work_file.registration_no',
                'work_file.status',
                'work_file.approval_screenshot',
                'work_file.description',
                'work_file.customer_amount',
                // Selected because awaitingPrice() needs to know whether there is a
                // vendor at all — an in-house file has no rate to agree and never will.
                'work_file.vendor_id',
                'work_file.vendor_amount',
                'work_file.vendor_returned_on',
                // The day it went out, what the days since are counted from,
                // and the day the work on it finished — see FINISHED_ON.
                'work_file.vendor_date',
                DB::raw(self::FINISHED_ON.' as finished_on'),
                'work_file.returned_amount',
                'work_file.vendor_returned_amount',
                'work_file.handed_over_on',
                // Where its papers stand, without a query per row.
                DB::raw(self::NEEDS_AUDIT.' as needs_audit'),
                DB::raw(self::PENDING_PAPER_NAMES.' as pending_papers'),
                self::workLabelColumn(),
                self::unpricedWorksColumn(),
                self::unbilledWorksColumn(),
                // What the office paid out of its own till, so rowTotals can
                // add it to the cost without a query per row.
                DB::raw(self::PAID_OUT.' as expenses'),
                'customer.name as customer_name',
                'customer.id as customer_id',
                'vendor.name as vendor_name',
                'vendor.id as vendor_id',
                self::vendorLabelColumn()
            );

        if ($status === 'open') {
            // Work still in hand, the same set the dashboard counts.
            $query->whereIn('work_file.status', self::OPEN_STATUSES);
        } elseif ($status === self::AWAITING_AUDIT) {
            // Received, and its papers not yet checked — the audit queue.
            $query->whereIn('work_file.status', [self::IN_OFFICE, self::PAPER_PENDENCY])
                ->whereRaw(self::NEEDS_AUDIT);
        } elseif ($status === self::PAPERS_PENDING) {
            $query->whereRaw(self::PENDING_PAPERS);
        } elseif ($status === self::AWAITING_HANDOVER) {
            // Finished work whose papers the customer has not collected — the
            // list the counter works through.
            $query->where('work_file.status', self::APPROVED)
                ->whereNull('work_file.handed_over_on');
        } elseif ($status) {
            $query->where('work_file.status', $status);
        }

        if ($from) {
            $query->whereDate('work_file.received_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('work_file.received_date', '<=', $to);
        }

        if ($pending && array_key_exists($pending, self::PENDING)) {
            self::pendingWhere($query, $pending);

            /*
             * Oldest first, the opposite of every other view.
             *
             * Elsewhere the newest file is the interesting one. Here the list is
             * a list of things to chase, and the one that has been waiting
             * longest is the one to chase first — newest-first would bury it at
             * the bottom, which is where it has been all along.
             */
            return $query
                ->orderBy('work_file.received_date', 'asc')
                ->orderBy('work_file.id', 'asc')
                ->get();
        }

        if ($status === 'open' || $status === self::DISPATCHED) {
            /*
             * Longest out first, for the same reason the chase lists above are
             * oldest first.
             *
             * A list of work still in hand is read to find what is overdue, and
             * the file that has been with a vendor three weeks is the one to ask
             * about — newest-first puts it on the last page, which is where it
             * would stay. Work that has not gone anywhere has no age to be
             * judged on and follows behind, oldest of those first.
             */
            return $query
                ->orderByRaw('work_file.vendor_date IS NULL')
                ->orderBy('work_file.vendor_date', 'asc')
                ->orderBy('work_file.received_date', 'asc')
                ->orderBy('work_file.id', 'asc')
                ->get();
        }

        return $query
            ->orderBy('work_file.received_date', 'desc')
            ->orderBy('work_file.id', 'desc')
            ->get();
    }
}

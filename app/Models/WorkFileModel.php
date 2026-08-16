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

    public const RETURNED = 'paper_returned';

    public const IN_OFFICE = 'in_office';

    public const DISPATCHED = 'file_dispatch';

    public const STATUSES = [
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
        'part_pesi_required', 'under_verification'];

    /**
     * Bootstrap contextual class per status, for the badge on the list.
     */
    public const STATUS_BADGES = [
        'in_office' => 'bg-secondary',
        'paper_pendency' => 'bg-warning text-dark',
        'file_dispatch' => 'bg-info text-dark',
        'part_pesi_required' => 'bg-warning text-dark',
        'under_verification' => 'bg-primary',
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

            if (! $file->vendor_returned_on) {
                $file->vendor_returned_amount = null;
            }
        });
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkTypeModel::class, 'work_type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'vendor_id');
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
    public function logStatus(?string $from, ?string $remark = null): WorkFileStatusLogModel
    {
        $log = new WorkFileStatusLogModel;
        $log->work_file_id = $this->id;
        $log->from_status = $from;
        $log->to_status = $this->status;
        $log->remark = $remark ?: null;
        $log->user_id = Auth::id();
        $log->save();

        return $log;
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
            - self::netVendor($this->status, $this->vendor_amount, $this->isReturnedByVendor(), $this->vendor_returned_amount);
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
     * Store an approval screenshot against this file, replacing any earlier one.
     *
     * The stored name is derived from the file number and a hash, never from the
     * uploaded name — a browser-supplied name is attacker-controlled and would
     * otherwise decide where in the filesystem this lands.
     */
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

    public function screenshotUrl(): ?string
    {
        return $this->approval_screenshot ? url($this->approval_screenshot) : null;
    }

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
     * Bring this file's ledger entries in line with the file as it now stands.
     *
     * Called after every save. Rather than appending a correction, it updates the
     * entries the file already owns, so editing a mistyped amount leaves one
     * correct entry instead of a pair that has to be read together to work out
     * what was actually charged.
     */
    public function syncLedger(): void
    {
        // Read the name through the relation query, not the loaded relation: on an
        // edit that changed the work type, the cached relation is the old one.
        $workType = $this->workType()->value('name') ?? 'Work';
        $particular = $this->description ? $workType.' - '.$this->description : $workType;

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

        // The vendor is owed for doing the work: credit. Dated from when the file
        // was handed over, falling back to the day it came in.
        $this->syncSide(
            'vendor',
            'credit',
            $cancelled ? null : $this->vendor_id,
            $this->vendor_amount,
            $this->vendor_date ?: $this->received_date,
            $particular
        );

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

        // The mirror image on the vendor side: they handed the file back, so what
        // was booked to them is reversed with a debit beside the credit. Cancelling
        // the file withdraws this along with everything else.
        $this->syncSide(
            'vendor_return',
            'debit',
            ($this->vendor_returned_on && ! $cancelled) ? $this->vendor_id : null,
            $this->reversedToVendor(),
            $this->vendor_returned_on ?: $this->received_date,
            $particular.' - returned by vendor'
        );
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
    public static function withVendor()
    {
        return self::query()
            ->with('workType', 'customer', 'vendor')
            ->whereNotNull('vendor_id')
            ->whereNull('vendor_returned_on')
            // Only work still in play. A file that has been returned to its
            // customer, approved or cancelled is finished, and taking it "back"
            // from a vendor would drag it into a state it has already left.
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('vendor_date', 'asc')
            ->orderBy('id', 'asc')
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
    private function syncSide(string $role, string $entryType, $partyId, $amount, $date, string $particular): void
    {
        $entry = PartyLedgerModel::where('work_file_id', $this->id)
            ->where('file_role', $role)
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
     * Headline figures for the dashboard.
     *
     * Cancelled files are excluded from the money throughout — they post nothing
     * to any ledger, so counting them here would make the dashboard disagree
     * with the statements.
     *
     * @return array{open: int, month_billed: float, month_margin: float}
     */
    public static function summary(): array
    {
        $open = (int) DB::table('work_file')
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();

        // The CASE arms mirror netCustomer()/netVendor(); a file that charged
        // nobody must not inflate the month's takings from the dashboard.
        // LEAST() mirrors returnedPortion()'s cap: a refund can never exceed what
        // was charged, however the row got that way.
        $earned = "CASE
            WHEN status = '".self::CANCELLED."' THEN 0
            WHEN status = '".self::RETURNED."'
                THEN customer_amount - LEAST(COALESCE(returned_amount, customer_amount), customer_amount)
            ELSE customer_amount END";

        $spent = "CASE
            WHEN status = '".self::CANCELLED."' THEN 0
            WHEN vendor_returned_on IS NOT NULL
                THEN COALESCE(vendor_amount, 0)
                     - LEAST(COALESCE(vendor_returned_amount, COALESCE(vendor_amount, 0)), COALESCE(vendor_amount, 0))
            ELSE COALESCE(vendor_amount, 0) END";

        $month = DB::table('work_file')
            ->whereYear('received_date', now()->year)
            ->whereMonth('received_date', now()->month)
            ->selectRaw("COALESCE(SUM($earned), 0) as billed")
            ->selectRaw("COALESCE(SUM($earned - ($spent)), 0) as margin")
            ->first();

        return [
            'open' => $open,
            'month_billed' => (float) ($month->billed ?? 0),
            'month_margin' => (float) ($month->margin ?? 0),
        ];
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
            ->with('workType', 'customer')
            ->whereNull('vendor_id')
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
    public static function returnableToCustomer()
    {
        return self::query()
            ->with('workType', 'customer', 'vendor')
            ->whereNotIn('status', [self::RETURNED, self::CANCELLED])
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
    public static function forStatusBoard(string $filter, $workTypeId = null)
    {
        $query = self::query()->with('workType', 'customer', 'vendor');

        self::applyStatusFilter($query, $filter);

        if ($workTypeId) {
            $query->where('work_type_id', $workTypeId);
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
    private static function applyStatusFilter($query, string $filter): void
    {
        if ($filter === 'open') {
            $query->whereIn('status', self::OPEN_STATUSES);
        } elseif (array_key_exists($filter, self::STATUSES)) {
            $query->where('status', $filter);
        }
    }

    /**
     * How many files sit behind each status tab, counted within whatever work
     * type is currently selected — a count that ignored the other filter would
     * promise rows the tab then does not show.
     *
     * @return array<string, int>  keyed by tab: 'open', each status, 'all'
     */
    public static function statusCounts($workTypeId = null): array
    {
        $query = DB::table('work_file');

        if ($workTypeId) {
            $query->where('work_type_id', $workTypeId);
        }

        $byStatus = $query->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

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
    public static function workTypeCounts(string $filter)
    {
        $query = DB::table('work_file')
            ->join('work_type', 'work_type.id', '=', 'work_file.work_type_id');

        self::applyStatusFilter($query, $filter);

        return $query
            ->select('work_type.id', 'work_type.name', DB::raw('COUNT(work_file.id) as total'))
            ->groupBy('work_type.id', 'work_type.name')
            ->havingRaw('COUNT(work_file.id) > 0')
            ->orderBy('work_type.name', 'asc')
            ->get();
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
                'vendor.name as vendor_name'
            );

        if ($excludeId) {
            $query->where('work_file.id', '!=', $excludeId);
        }

        return $query->orderBy('work_file.received_date', 'desc')
            ->orderBy('work_file.id', 'desc')
            ->limit(25)
            ->get();
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
    public static function report(string $partyType, $partyId = null, ?string $status = null, ?string $from = null, ?string $to = null)
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
                'work_file.vendor_amount',
                'work_file.vendor_returned_on',
                'work_file.vendor_returned_amount',
                'work_type.name as work_type',
                'customer.name as customer_name',
                'vendor.name as vendor_name',
                DB::raw(($isVendor ? 'vendor.id' : 'customer.id').' as party_id'),
                DB::raw(($isVendor ? 'vendor.name' : 'customer.name').' as party_name')
            );

        if ($isVendor) {
            $query->whereNotNull('work_file.vendor_id');
        }

        if ($partyId) {
            $query->where($isVendor ? 'work_file.vendor_id' : 'work_file.customer_id', $partyId);
        }

        if ($status === 'open') {
            $query->whereIn('work_file.status', self::OPEN_STATUSES);
        } elseif ($status && array_key_exists($status, self::STATUSES)) {
            $query->where('work_file.status', $status);
        }

        if ($from) {
            $query->whereDate('work_file.received_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('work_file.received_date', '<=', $to);
        }

        return $query
            ->orderBy('party_name', 'asc')
            ->orderBy('work_file.received_date', 'asc')
            ->orderBy('work_file.id', 'asc')
            ->get();
    }

    /**
     * What one row of the report is actually worth, once returns and
     * cancellations are taken into account. Kept here so the report, the file
     * list and the dashboard cannot each answer it differently.
     *
     * @return array{billed: float, cost: float, margin: float}
     */
    public static function rowTotals($row): array
    {
        $billed = self::netCustomer($row->status, $row->customer_amount, $row->returned_amount);
        $cost = self::netVendor($row->status, $row->vendor_amount, $row->vendor_returned_on !== null, $row->vendor_returned_amount);

        return ['billed' => $billed, 'cost' => $cost, 'margin' => $billed - $cost];
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

        // Nothing charged yet. Zero rather than null: the column has always been
        // NOT NULL with a zero default, so "no figure" is stored as 0.00.
        $unbilled = fn ($q) => $q->where('work_file.customer_amount', '<=', 0);

        // Handed to a vendor without a rate. Only meaningful once there is a
        // vendor — an in-house file has no rate to agree and never will.
        $unpriced = fn ($q) => $q->whereNotNull('work_file.vendor_id')
            ->whereNull('work_file.vendor_amount');

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
                'work_file.vendor_amount',
                'work_file.vendor_returned_on',
                'work_file.returned_amount',
                'work_file.vendor_returned_amount',
                'work_type.name as work_type',
                'customer.name as customer_name',
                'customer.id as customer_id',
                'vendor.name as vendor_name',
                'vendor.id as vendor_id'
            );

        if ($status === 'open') {
            // Work still in hand, the same set the dashboard counts.
            $query->whereIn('work_file.status', self::OPEN_STATUSES);
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

        return $query
            ->orderBy('work_file.received_date', 'desc')
            ->orderBy('work_file.id', 'desc')
            ->get();
    }
}

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

    public const IN_OFFICE = 'in_office';

    public const DISPATCHED = 'file_dispatch';

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
    public function logStatus(?string $from, ?string $remark = null, ?int $itemId = null): WorkFileStatusLogModel
    {
        $log = new WorkFileStatusLogModel;
        $log->work_file_id = $this->id;
        // Which job this is about, on a folder that holds several. Null when it
        // is about the folder itself.
        $log->work_file_item_id = $itemId;
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
    public static function storeUpload(UploadedFile $upload, ?string $previous = null, string $prefix = 'item'): string
    {
        $directory = public_path(self::UPLOAD_DIR);

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
        $stored = self::UPLOAD_DIR.'/'.$name;

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
    public function ledgerParticular(): string
    {
        $works = $this->items()->with('workType')->get()
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
        foreach ([$this->registration_no, $this->description] as $detail) {
            $detail = trim((string) $detail);

            if ($detail !== '' && ! in_array($detail, $parts, true)) {
                $parts[] = $detail;
            }
        }

        return implode(' - ', $parts);
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
            ->with('workType', 'customer', 'vendor', 'items.workType')
            ->whereNotNull('vendor_id')
            ->whereNull('vendor_returned_on')
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

    /** And what it cost, mirroring netVendor() the same way. */
    public const SPENT = "CASE
        WHEN work_file.status = 'cancelled' THEN 0
        WHEN work_file.vendor_returned_on IS NOT NULL
            THEN COALESCE(work_file.vendor_amount, 0)
                 - LEAST(COALESCE(work_file.vendor_returned_amount, COALESCE(work_file.vendor_amount, 0)),
                         COALESCE(work_file.vendor_amount, 0))
        ELSE COALESCE(work_file.vendor_amount, 0) END";

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
        OR (work_file.vendor_id IS NOT NULL AND (
            work_file.vendor_amount IS NULL OR work_file.vendor_amount <= 0
            OR EXISTS (SELECT 1 FROM work_file_item
                WHERE work_file_item.work_file_id = work_file.id
                  AND work_file_item.status <> 'cancelled'
                  AND (work_file_item.vendor_amount IS NULL OR work_file_item.vendor_amount <= 0))))))";

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

        [$key, $label, $order] = match ($group) {
            'year' => ["DATE_FORMAT(work_file.received_date, '%Y')", "DATE_FORMAT(work_file.received_date, '%Y')", 'group_key desc'],
            'vendor' => ['COALESCE(vendor.id, 0)', "COALESCE(vendor.name, 'In-house')", 'billed desc'],
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

        if ($group === 'vendor') {
            $each->leftJoin('party as vendor', 'vendor.id', '=', 'work_file.vendor_id');
        }

        if ($group === 'customer') {
            $each->join('party as customer', 'customer.id', '=', 'work_file.customer_id');
        }

        self::betweenDates($each, $from, $to);

        return DB::query()
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
    }
    /**
     * The same question asked of the works rather than the folders.
     *
     * This is what per-work pricing bought: "HPT + TR + HPA" could never say
     * what a transfer earned, because the folder carried one figure for all
     * three. Now each work carries its own.
     *
     * Cancelled work is out — it charges nobody. So is a cancelled or returned
     * file: a refund is agreed for the folder, and splitting it across the
     * works on it would be inventing a precision nobody recorded.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function profitByWorkType(?string $from, ?string $to)
    {
        $query = DB::table('work_file_item')
            ->join('work_file', 'work_file.id', '=', 'work_file_item.work_file_id')
            ->join('work_type', 'work_type.id', '=', 'work_file_item.work_type_id')
            ->where('work_file_item.status', '<>', self::CANCELLED)
            ->whereNotIn('work_file.status', [self::CANCELLED, self::RETURNED]);

        self::betweenDates($query, $from, $to);

        // A work waits on a price the same way a file does, on either side.
        $outstanding = "(work_file_item.customer_amount IS NULL OR work_file_item.customer_amount <= 0
            OR (work_file.vendor_id IS NOT NULL
                AND (work_file_item.vendor_amount IS NULL OR work_file_item.vendor_amount <= 0)))";

        return $query
            ->selectRaw('work_type.id as group_key')
            ->selectRaw('work_type.name as group_label')
            ->selectRaw('COUNT(*) as files')
            ->selectRaw('COALESCE(SUM(work_file_item.customer_amount), 0) as billed')
            ->selectRaw('COALESCE(SUM(COALESCE(work_file_item.vendor_amount, 0)), 0) as cost')
            ->selectRaw("COALESCE(SUM(CASE WHEN $outstanding THEN 0
                ELSE work_file_item.customer_amount - COALESCE(work_file_item.vendor_amount, 0) END), 0) as margin")
            ->selectRaw("COALESCE(SUM(CASE WHEN $outstanding THEN 1 ELSE 0 END), 0) as unpriced")
            ->groupBy('work_type.id', 'work_type.name')
            ->orderByRaw('billed desc')
            ->get();
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
         * The arms mirror awaitingPrice(): settled files are not outstanding,
         * and a folder is asked about along with every live work on it, because
         * a folder half priced totals more than zero and reads as settled.
         */
        $shortWork = fn (string $column) => "EXISTS (
            SELECT 1 FROM work_file_item
            WHERE work_file_item.work_file_id = work_file.id
              AND work_file_item.status <> '".self::CANCELLED."'
              AND (work_file_item.$column IS NULL OR work_file_item.$column <= 0))";

        $outstanding = "(status NOT IN ('".self::CANCELLED."', '".self::RETURNED."') AND (
            customer_amount IS NULL OR customer_amount <= 0
            OR ".$shortWork('customer_amount')."
            OR (vendor_id IS NOT NULL AND (
                vendor_amount IS NULL OR vendor_amount <= 0
                OR ".$shortWork('vendor_amount')."))))";

        $month = DB::table('work_file')
            ->whereYear('received_date', now()->year)
            ->whereMonth('received_date', now()->month)
            ->selectRaw("COALESCE(SUM($earned), 0) as billed")
            ->selectRaw("COALESCE(SUM(CASE WHEN $outstanding THEN 0 ELSE $earned - ($spent) END), 0) as margin")
            // Counted so the tile can say what its figure is a figure of.
            ->selectRaw("COALESCE(SUM(CASE WHEN $outstanding THEN 1 ELSE 0 END), 0) as unpriced")
            ->selectRaw('COUNT(*) as files')
            ->first();

        return [
            'open' => $open,
            'month_billed' => (float) ($month->billed ?? 0),
            'month_margin' => (float) ($month->margin ?? 0),
            'month_files' => (int) ($month->files ?? 0),
            'month_unpriced' => (int) ($month->unpriced ?? 0),
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
            ->with('workType', 'customer', 'items.workType')
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
            ->with('workType', 'customer', 'vendor', 'items.workType')
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
    public static function forStatusBoard(string $filter, $workTypeId = null, $vendorId = null)
    {
        // The jobs come with the file: the board moves each of them along on
        // its own, because approvals arrive one at a time.
        $query = self::query()->with('workType', 'customer', 'vendor', 'items.workType');

        self::applyStatusFilter($query, $filter);
        self::applyVendorFilter($query, $vendorId);

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
    private static function applyStatusFilter($query, string $filter): void
    {
        // Named with its table: the work type counts below join the works,
        // which have a status of their own, and a bare column would be
        // ambiguous — or worse, silently the wrong one.
        if ($filter === 'open') {
            $query->whereIn('work_file.status', self::OPEN_STATUSES);
        } elseif (array_key_exists($filter, self::STATUSES)) {
            $query->where('work_file.status', $filter);
        }
    }

    /**
     * Narrows to one vendor, or to the work kept in-house.
     *
     * 'none' rather than an empty string, because a missing parameter and a
     * deliberate choice of "nobody" are different answers and a blank cannot
     * tell them apart.
     */
    private static function applyVendorFilter($query, $vendorId): void
    {
        if ($vendorId === self::IN_HOUSE) {
            $query->whereNull('work_file.vendor_id');

            return;
        }

        if ($vendorId) {
            $query->where('work_file.vendor_id', $vendorId);
        }
    }

    /**
     * Which vendors are holding work under the filters that are on, and how
     * much each of them has.
     *
     * Files nobody was given are counted together as in-house: they are work in
     * hand like any other, and leaving them out of the row would make the
     * counts disagree with the board.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function vendorCounts(string $filter, $workTypeId = null)
    {
        $query = DB::table('work_file')
            ->leftJoin('party as vendor', 'vendor.id', '=', 'work_file.vendor_id');

        self::applyStatusFilter($query, $filter);

        if ($workTypeId) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                ->where('work_file_item.work_type_id', $workTypeId));
        }

        return $query
            ->select(
                DB::raw('COALESCE(vendor.id, 0) as id'),
                DB::raw("COALESCE(vendor.name, 'In-house') as name"),
                DB::raw('COUNT(DISTINCT work_file.id) as total')
            )
            // ONLY_FULL_GROUP_BY: the columns the names are derived from.
            ->groupBy('vendor.id', 'vendor.name')
            ->havingRaw('COUNT(DISTINCT work_file.id) > 0')
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
        $query = DB::table('work_file');

        self::applyVendorFilter($query, $vendorId);

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
        self::applyVendorFilter($query, $vendorId);

        return $query
            ->select('work_type.id', 'work_type.name', DB::raw('COUNT(DISTINCT work_file.id) as total'))
            ->groupBy('work_type.id', 'work_type.name')
            ->havingRaw('COUNT(DISTINCT work_file.id) > 0')
            ->orderBy('work_type.name', 'asc')
            ->get();
    }

    /**
     * The last few rates agreed for each of these works.
     *
     * Shown where a rate is being agreed, because that is where the question is
     * asked: what did we pay for a transfer last time, and to whom. Every
     * vendor's is included rather than only the one being chosen — comparing
     * across them is half the reason to ask.
     *
     * One query per work type, deliberately. A handful of types are on the
     * screen at a time, each lookup is a short indexed read, and the portable
     * alternatives are a window function or reading every rate ever agreed and
     * throwing most of it away.
     *
     * @param  array<int>  $workTypeIds
     * @return array<int, array<int, object>>  keyed by work type, newest first
     */
    public static function recentVendorRates(array $workTypeIds, int $limit = 5): array
    {
        $out = [];

        foreach (array_unique($workTypeIds) as $typeId) {
            $out[$typeId] = DB::table('work_file_item')
                ->join('work_file', 'work_file.id', '=', 'work_file_item.work_file_id')
                ->join('party as vendor', 'vendor.id', '=', 'work_file.vendor_id')
                ->where('work_file_item.work_type_id', $typeId)
                // A rate that was never agreed is not a rate that was paid, and
                // a cancelled file was owed for by nobody.
                ->whereNotNull('work_file_item.vendor_amount')
                ->where('work_file_item.vendor_amount', '>', 0)
                ->where('work_file.status', '<>', self::CANCELLED)
                ->orderByDesc('work_file.vendor_date')
                ->orderByDesc('work_file.id')
                ->limit($limit)
                ->get([
                    'work_file.file_no',
                    'work_file.vendor_date',
                    'work_file.registration_no',
                    'vendor.name as vendor',
                    'work_file_item.vendor_amount as amount',
                    'work_file_item.customer_amount as charged',
                ])
                ->all();
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
                'work_type.name'
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
    private static function unpricedWorksColumn()
    {
        $cancelled = self::CANCELLED;

        return DB::raw(<<<SQL
            (SELECT COUNT(*) FROM work_file_item
              WHERE work_file_item.work_file_id = work_file.id
                AND work_file_item.status <> '$cancelled'
                AND (work_file_item.vendor_amount IS NULL OR work_file_item.vendor_amount <= 0)
            ) AS unpriced_works
            SQL);
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
                // Selected because awaitingPrice() needs to know whether there is a
                // vendor at all — an in-house file has no rate to agree and never will.
                'work_file.vendor_id',
                'work_file.vendor_amount',
                'work_file.vendor_returned_on',
                'work_file.vendor_returned_amount',
                self::workLabelColumn(),
                self::unpricedWorksColumn(),
                self::unbilledWorksColumn(),
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

        $unpriced = ($row->vendor_id ?? null) !== null
            && ((float) ($row->vendor_amount ?? 0) <= 0 || (int) ($row->unpriced_works ?? 0) > 0);
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

    public static function statusFromItems($items): string
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

        $this->status = self::statusFromItems($items);

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
    public function workLabel(): string
    {
        return $this->items->map(fn ($item) => $item->workType?->name)->filter()->implode(', ');
    }

    public static function rowTotals($row): array
    {
        $billed = self::netCustomer($row->status, $row->customer_amount, $row->returned_amount);
        $cost = self::netVendor($row->status, $row->vendor_amount, $row->vendor_returned_on !== null, $row->vendor_returned_amount);

        return [
            'billed' => $billed,
            'cost' => $cost,
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

        // Only meaningful once there is a vendor — an in-house file has no rate
        // to agree and never will.
        $unpriced = fn ($q) => $outstanding($q->whereNotNull('work_file.vendor_id'), 'vendor_amount');
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
                'work_file.returned_amount',
                'work_file.vendor_returned_amount',
                self::workLabelColumn(),
                self::unpricedWorksColumn(),
                self::unbilledWorksColumn(),
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

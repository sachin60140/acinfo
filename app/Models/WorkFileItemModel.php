<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job on a file.
 *
 * Papers come in for several works at once — a transfer and a hypothecation
 * addition on the same vehicle — and each is approved separately, often days
 * apart. A file with a single work type and a single status could say neither,
 * so each job is a row: its own price, its own cost, its own status, and its
 * own evidence when it is approved.
 *
 * The file above it is unchanged in meaning: one folder, one number, one
 * customer, one balance on their statement.
 */
class WorkFileItemModel extends Model
{
    public $table = 'work_file_item';

    /**
     * The same list a file uses. A job moves through the same stages the folder
     * does, and keeping one vocabulary means the status board can eventually
     * work on jobs without a second set of names to learn.
     */
    public const STATUSES = WorkFileModel::STATUSES;

    public function file(): BelongsTo
    {
        return $this->belongsTo(WorkFileModel::class, 'work_file_id');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkTypeModel::class, 'work_type_id');
    }

    /**
     * Whoever was given this particular job.
     *
     * A folder's works go out one at a time: the transfer to the agent who is
     * quick with transfers, the hypothecation addition to the one with the bank
     * contact. The folder's own vendor is worked out from these.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(PartyModel::class, 'vendor_id');
    }

    /** Still with a vendor: given out, and not yet come back. */
    public function isOut(): bool
    {
        return (bool) $this->vendor_id && ! $this->vendor_returned_on;
    }

    /**
     * The office is doing this one itself.
     *
     * Said out loud rather than left to be inferred from having no vendor,
     * because having no vendor is also what a work waiting for one looks like.
     * The two are the same row until somebody says which it is, and Give to
     * Vendor spent that whole time offering both.
     */
    public function isKeptInHouse(): bool
    {
        return $this->kept_in_house_on !== null;
    }

    /**
     * Waiting to be given to somebody: here, not spoken for, not finished.
     *
     * The set Give to Vendor offers, in one place, because the screen and the
     * query behind it have to agree about it or a folder appears on a list it
     * cannot be acted on from.
     */
    public function isWaitingForAVendor(): bool
    {
        return ! $this->vendor_id
            && ! $this->isKeptInHouse()
            && ! in_array($this->status, [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED], true);
    }

    public function isApproved(): bool
    {
        return $this->status === WorkFileModel::APPROVED;
    }

    /**
     * Settled: nothing further is expected of this job.
     *
     * Cancelled charged nobody and returned was charged and given back, so
     * neither is waiting on anything — the same test the file itself uses.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED], true);
    }

    /**
     * Stamp the approval date the first time a job is approved, and clear it if
     * that is undone — the same rule the file applies to its return date, for
     * the same reason: no route should be able to set the status without the
     * date following it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->isApproved()) {
                $item->approved_on = $item->approved_on ?: now()->toDateString();
            } else {
                $item->approved_on = null;
            }
        });
    }
}

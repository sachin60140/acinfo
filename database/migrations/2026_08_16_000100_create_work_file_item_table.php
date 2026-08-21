<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The work a file is actually for, one row per job.
 *
 * A file has carried a single work_type_id and a single status, so papers
 * brought in for two jobs at once could only be described by inventing a work
 * type that named both — which is why the list holds "HPT + TR", "TR + HPA" and
 * "HPT + TR + HPA" alongside the three real services. Three services need seven
 * such names; four need fifteen.
 *
 * Worse, approvals arrive separately. HPA can come back approved while TR is
 * still with the office days later, and a file with one status has nowhere to
 * put that: it is either approved or it is not, and neither is true.
 *
 * So the work moves onto its own rows. Each carries its own price, its own
 * cost, its own status and its own evidence, because each is approved on its
 * own. The file stays what it has always been — one folder, one number, one
 * customer.
 *
 * This migration only creates and fills the table. Every existing file gets
 * exactly one item mirroring what it already says, so nothing reads differently
 * afterwards; the screens move over in later steps. Nothing is guessed: a file
 * that says "HPT + TR + HPA" keeps that one type, because how its money divides
 * between the three is not recorded anywhere and inventing a split would put
 * numbers in the ledger that nobody entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_file') || Schema::hasTable('work_file_item')) {
            return;
        }

        Schema::create('work_file_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_file_id')->constrained('work_file')->cascadeOnDelete();
            $table->foreignId('work_type_id')->constrained('work_type');

            // Priced per job: the customer is charged for each work, and the
            // file's figure is the sum of them.
            $table->decimal('customer_amount', 15, 2)->default(0);

            // Null means the rate is not agreed yet, the same as on the file —
            // and the same meaning the pending report already reads.
            $table->decimal('vendor_amount', 15, 2)->nullable();

            $table->enum('status', [
                'in_office',
                'paper_pendency',
                'file_dispatch',
                'part_pesi_required',
                'under_verification',
                'approval_done',
                'paper_returned',
                'cancelled',
            ])->default('in_office');

            // Approval is the one status that has to be evidenced, and part
            // approval means two approvals arriving days apart with a document
            // each. So the evidence belongs to the job, not to the folder.
            $table->string('approval_screenshot')->nullable();
            $table->date('approved_on')->nullable();

            $table->timestamps();

            // Every read is "the items on this file".
            $table->index('work_file_id');
        });

        /*
         * One item per existing file, copying what the file already says. Done
         * in one statement rather than row by row: this runs against a live
         * table and a loop would leave it half migrated if it stopped.
         */
        DB::statement('
            insert into work_file_item
                (work_file_id, work_type_id, customer_amount, vendor_amount, status,
                 approval_screenshot, approved_on, created_at, updated_at)
            select
                id, work_type_id, customer_amount, vendor_amount, status,
                approval_screenshot,
                case when status = ? then coalesce(updated_at, created_at) else null end,
                coalesce(created_at, now()), coalesce(updated_at, now())
            from work_file
        ', ['approval_done']);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_file_item');
    }
};

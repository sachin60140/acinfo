<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which papers a file has, and which it is still waiting for.
 *
 * Paper Pendency has been a status for as long as there have been statuses,
 * and the customer has seen it as "Documents pending from you" — but nothing
 * recorded which documents. The office wrote it into the Details column
 * instead: "Without Challan & Affidavit", "Mannapuram NOC Not Available".
 * Nobody could filter it, count it, or show it to the customer.
 *
 * Four tables:
 *
 *   paper_type             the papers the office deals in — RC, Form 35, NOC
 *   work_type_paper        which of them each kind of work needs
 *   work_file_paper        one line per paper per file: received, pending or
 *                          not needed, with a note
 *   work_file_paper_item   which works on the file that line was for
 *
 * The last is what makes a checklist stand still once it has been checked.
 * Needed-by is recorded when the file is audited, so editing the master list
 * afterwards changes new audits and never quietly rewrites a file already
 * checked — and cancelling one work drops exactly the papers only it needed.
 */
return new class extends Migration
{
    /**
     * A starting list, from Parivahan's published requirements. The office
     * edits it on the Paper Types screen: several items are required in some
     * states only, and no list for Bihar is published separately.
     */
    private const PAPERS = [
        'RC (original)',
        'Insurance certificate',
        'PUC certificate',
        'ID and address proof',
        'PAN card or Form 60',
        'Chassis & engine pencil print',
        'Form 35',
        'Bank NOC / loan closure letter',
        'Form 29',
        'Form 30',
        "Buyer's date-of-birth proof",
        "Buyer's undertaking",
        'Passport-size photographs',
        'Tax / challan clearance',
        'Transfer challan',
        'Affidavit',
        'Form 34',
        'Loan agreement / sanction copy',
        'Form 26',
        'FIR / police report',
        "Financier's consent",
    ];

    /**
     * Which papers each work needs, by the work type's name. true is required,
     * false is "only if applicable". A work type the office named differently
     * simply starts with no list, and is given one on the Paper Types screen.
     */
    private const NEEDS = [
        'HPT' => [
            'RC (original)' => true,
            'Insurance certificate' => true,
            'PUC certificate' => true,
            'ID and address proof' => true,
            'PAN card or Form 60' => false,
            'Chassis & engine pencil print' => false,
            'Form 35' => true,
            'Bank NOC / loan closure letter' => true,
        ],
        'TR' => [
            'RC (original)' => true,
            'Insurance certificate' => true,
            'PUC certificate' => true,
            'ID and address proof' => true,
            'PAN card or Form 60' => true,
            'Chassis & engine pencil print' => true,
            'Form 29' => true,
            'Form 30' => true,
            "Buyer's date-of-birth proof" => true,
            "Buyer's undertaking" => false,
            'Passport-size photographs' => true,
            'Tax / challan clearance' => false,
            'Transfer challan' => false,
            'Affidavit' => false,
        ],
        'HPA' => [
            'RC (original)' => true,
            'Insurance certificate' => true,
            'PUC certificate' => true,
            'ID and address proof' => true,
            'PAN card or Form 60' => false,
            'Chassis & engine pencil print' => false,
            'Form 34' => true,
            'Loan agreement / sanction copy' => false,
        ],
        'DRC' => [
            'RC (original)' => false,
            'Insurance certificate' => true,
            'PUC certificate' => true,
            'ID and address proof' => true,
            'Passport-size photographs' => true,
            'Tax / challan clearance' => false,
            'Affidavit' => true,
            'Form 26' => true,
            'FIR / police report' => false,
            "Financier's consent" => false,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('paper_type')) {
            Schema::create('paper_type', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                // Retired rather than deleted: a file already checked still has
                // to be able to say which paper a line was.
                $table->boolean('is_active')->default(true);
                // The order a checklist is read in — the RC first, the forms
                // after, the way a folder is actually gone through.
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_type_paper')) {
            Schema::create('work_type_paper', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_type_id')->constrained('work_type')->cascadeOnDelete();
                $table->foreignId('paper_type_id')->constrained('paper_type')->cascadeOnDelete();
                // False is "only if applicable": on the checklist, but it starts
                // as Not needed and may stay that way without a reason.
                $table->boolean('required')->default(true);
                $table->timestamps();

                $table->unique(['work_type_id', 'paper_type_id'], 'work_type_paper_unique');
            });
        }

        if (! Schema::hasTable('work_file_paper')) {
            Schema::create('work_file_paper', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_file_id')->constrained('work_file')->cascadeOnDelete();
                // Restricted: a paper type in use is retired, never deleted.
                $table->foreignId('paper_type_id')->constrained('paper_type');

                // received | pending | not_needed
                $table->string('state', 12);

                // Whether any work it was checked for needed it outright. Kept on
                // the line so "Not needed" on a required paper can be asked to
                // say why, whatever the master list says later.
                $table->boolean('required')->default(true);

                // Shown to the customer: "Buyer has not signed Form 30".
                $table->string('note', 200)->nullable();
                // The office's own: never on the customer's page.
                $table->string('office_note', 200)->nullable();

                // The day it came in, for a paper that did.
                $table->date('received_on')->nullable();

                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // One RC per file, however many works need it.
                $table->unique(['work_file_id', 'paper_type_id'], 'work_file_paper_unique');
                $table->index(['state', 'work_file_id']);
            });
        }

        if (! Schema::hasTable('work_file_paper_item')) {
            Schema::create('work_file_paper_item', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_file_paper_id')->constrained('work_file_paper')->cascadeOnDelete();
                $table->foreignId('work_file_item_id')->constrained('work_file_item')->cascadeOnDelete();

                $table->unique(['work_file_paper_id', 'work_file_item_id'], 'work_file_paper_item_unique');
            });
        }

        if (! Schema::hasColumn('work_file_item', 'papers_audited_at')) {
            Schema::table('work_file_item', function (Blueprint $table) {
                /*
                 * When this work's papers were checked. Null is "still to be
                 * checked", which is what puts a file on the Paper Audit list.
                 */
                $table->dateTime('papers_audited_at')->nullable();
            });

            /*
             * Work already past the office is not asked to be audited: its
             * papers have gone. Only work still In Office or in Paper Pendency
             * joins the queue — which is the whole of the new process, applied
             * from here on.
             */
            DB::table('work_file_item')
                ->whereNotIn('status', ['in_office', 'paper_pendency'])
                ->update(['papers_audited_at' => DB::raw('COALESCE(updated_at, created_at, NOW())')]);
        }

        $this->seed();
    }

    private function seed(): void
    {
        $now = now();

        foreach (self::PAPERS as $i => $name) {
            if (! DB::table('paper_type')->where('name', $name)->exists()) {
                DB::table('paper_type')->insert([
                    'name' => $name,
                    'is_active' => 1,
                    'sort' => ($i + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $papers = DB::table('paper_type')->pluck('id', 'name');

        foreach (self::NEEDS as $workName => $needs) {
            $workTypeIds = DB::table('work_type')
                ->whereRaw('UPPER(TRIM(name)) = ?', [$workName])
                ->pluck('id');

            foreach ($workTypeIds as $workTypeId) {
                // A work type the office has already given a list is left as it is.
                if (DB::table('work_type_paper')->where('work_type_id', $workTypeId)->exists()) {
                    continue;
                }

                foreach ($needs as $paper => $required) {
                    DB::table('work_type_paper')->insert([
                        'work_type_id' => $workTypeId,
                        'paper_type_id' => $papers[$paper],
                        'required' => $required ? 1 : 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_file_item', 'papers_audited_at')) {
            Schema::table('work_file_item', function (Blueprint $table) {
                $table->dropColumn('papers_audited_at');
            });
        }

        Schema::dropIfExists('work_file_paper_item');
        Schema::dropIfExists('work_file_paper');
        Schema::dropIfExists('work_type_paper');
        Schema::dropIfExists('paper_type');
    }
};

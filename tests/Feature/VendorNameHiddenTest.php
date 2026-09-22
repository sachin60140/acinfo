<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkFileController;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * No customer is told who does the work.
 *
 * Found in use: on BR05AS6323 the customer's timeline read "HPA given to
 * Shailendra Pandey Motihari". Give to Vendor writes "Given to <vendor>" when a
 * whole folder goes and "<works> given to <vendor>" when part of one does; the
 * filter matched only the first, with a capital G.
 *
 * Read for: the part-folder clause is taken off, whatever its case, with the
 * works in front of it and without what the office typed before it; and a
 * vendor's name or number left anywhere in a remark — typed by hand, say —
 * keeps the remark off the customer's page.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VendorNameHiddenTest extends TestCase
{
    use DatabaseTransactions;

    private PartyModel $customer;

    private PartyModel $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->party('customer', 'Customer '.uniqid().' hidden', '93400'.random_int(10000, 99999));
        $this->vendor = $this->party('vendor', 'Shailendra Pandey Motihari', '9431012345');
    }

    private function party(string $type, string $name, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function type(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** A folder of two works, HPT and HPA, in the office. */
    private function folder(): array
    {
        $hpt = $this->type('HPT');
        $hpa = $this->type('HPA');

        $file = new WorkFileModel;
        $file->file_no = 'F-VH-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = 'BR05AS'.random_int(1000, 9999);
        $file->work_type_id = $hpt->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 7500;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $items = [];

        foreach ([[$hpt, 5000], [$hpa, 2500]] as [$type, $charge]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charge;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
            $items[] = $item;
        }

        $file->syncLedger();

        return [$file->fresh(), $items[0], $items[1], $hpa];
    }

    private function admin(): User
    {
        $admin = new User;
        $admin->name = 'Hidden Admin';
        $admin->email = 'hidden-'.uniqid().'@example.com';
        $admin->password = Hash::make('password-for-tests');
        $admin->user_type = 1;
        $admin->save();

        return $admin;
    }

    /** Give to Vendor, as the screen posts it: only the works named in jobs go. */
    private function give(WorkFileModel $file, array $jobs, string $remark = ''): void
    {
        Auth::loginUsingId($this->admin()->id);

        app(WorkFileController::class)->assign(Request::create('/admin/file/assign', 'POST', array_filter([
            'vendor_id' => $this->vendor->id,
            'vendor_date' => '2026-09-22',
            'files' => [$file->id],
            'jobs' => $jobs,
            'remark' => $remark,
        ], fn ($value) => $value !== '')));
    }

    private function shown(WorkFileModel $file): string
    {
        return implode(' | ', array_map(fn ($entry) => (string) $entry['remark'], WorkFileModel::customerTimeline($file->id)));
    }

    // ------------------------------------------------------------- the leak

    /** BR05AS6323: part of a folder given to a vendor, and the customer told nothing of who. */
    public function test_part_of_a_folder_given_to_a_vendor_names_no_vendor_to_the_customer(): void
    {
        [$file, , $hpa] = $this->folder();

        $this->give($file, [$hpa->id]);

        $written = (string) DB::table('work_file_status_log')->where('work_file_id', $file->id)->orderByDesc('id')->value('remark');
        $this->assertStringContainsString('given to '.$this->vendor->name, $written, 'the premise: the part-folder clause');

        $this->assertStringNotContainsString('Shailendra', $this->shown($file));
        $this->assertStringNotContainsString('given to', strtolower($this->shown($file)));

        // Nor on the pages the customer opens: the file, and the list of files.
        $this->withSession(['customer_id' => $this->customer->id]);

        $page = $this->getJson(route('customer.file', $file->id))->assertOk()->getContent();
        $list = $this->getJson(route('customer.files'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Shailendra', $page);
        $this->assertStringNotContainsString('Shailendra', $list);
    }

    /** What the office typed before the clause is kept; the clause and its works go. */
    public function test_what_was_typed_stays_and_the_clause_goes(): void
    {
        [$file, , $hpa] = $this->folder();

        $this->give($file, [$hpa->id], 'Sent for termination');

        $shown = $this->shown($file);

        $this->assertStringContainsString('Sent for termination', $shown);
        $this->assertStringNotContainsString('Shailendra', $shown);
        $this->assertStringNotContainsString('HPA', $shown, 'the works named in the clause were left dangling');
    }

    public function test_the_clause_is_taken_off_in_any_case_and_form(): void
    {
        foreach ([
            'HPA given to Dabloo Ji Muzaffarpur',
            'HPA, TR given to Dabloo Ji Muzaffarpur',
            'HPA GIVEN TO Dabloo Ji Muzaffarpur',
            'Given to Dabloo Ji Muzaffarpur',
            'papers returned by Dabloo Ji Muzaffarpur',
            'Papers returned by Dabloo Ji Muzaffarpur',
        ] as $remark) {
            $this->assertNull(WorkFileModel::customerRemark($remark), $remark);
        }

        $this->assertSame('Online done', WorkFileModel::customerRemark('Online done — HPA, TR given to Dabloo Ji Muzaffarpur'));
        $this->assertSame('Online done', WorkFileModel::customerRemark('Online done - Papers returned by Dabloo Ji Muzaffarpur'));
    }

    // ------------------------------------------------------------ the safety net

    /** Typed by hand, a vendor's name or number keeps the whole remark off the customer's page. */
    public function test_a_vendor_named_by_hand_is_not_shown(): void
    {
        foreach ([
            'Shailendra Pandey Motihari has the file',
            'Sent with Shailendra ji',
            'SHAILENDRA will call',
            'Status from 94310 12345',
            'Ask +91-9431012345',
        ] as $remark) {
            $this->assertNull(WorkFileModel::customerRemark($remark), $remark);
        }
    }

    /** A remark that names no vendor is shown as typed. */
    public function test_a_remark_naming_no_vendor_is_kept(): void
    {
        foreach (['Online Done', 'Customer from Motihari', 'Shailendranath to collect', 'PUC Fail, Chassis Print'] as $remark) {
            $this->assertSame($remark, WorkFileModel::customerRemark($remark), $remark);
        }
    }

    /** On the files list too — the latest remark of each file. */
    public function test_the_files_list_shows_no_vendor_named_by_hand(): void
    {
        [$file] = $this->folder();

        DB::table('work_file_status_log')->insert([
            'work_file_id' => $file->id,
            'from_status' => WorkFileModel::IN_OFFICE,
            'to_status' => WorkFileModel::IN_OFFICE,
            'remark' => 'Handed to Shailendra ji for HPA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(WorkFileModel::latestCustomerUpdates([$file->id])[$file->id]['remark']);
    }
}

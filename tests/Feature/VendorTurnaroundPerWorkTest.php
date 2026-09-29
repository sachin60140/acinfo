<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * How long a vendor takes, counted on their own works.
 *
 * Asked for by the owner on 2026-09-28, with the rule that anything vendor-wise
 * counts only the works given to that vendor. Counted by the folder, the
 * Vendors report kept a folder a vendor held part of "out" with them while the
 * office finished its own work, and timed them to the office's last approval;
 * a folder split between two vendors, naming neither, was nobody's.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VendorTurnaroundPerWorkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Turnaround Admin';
        $this->admin->email = 'turnaround-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');
        $this->shailendra = $this->party('vendor');

        $this->hpt = $this->workType('HPT');
        $this->tr = $this->workType('TR');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid();
        $party->mobile = '9'.random_int(600000000, 999999999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function daysAgo(int $days): string
    {
        return now()->subDays($days)->toDateString();
    }

    /**
     * A folder and its works, each [type, vendor or null, given days ago,
     * status, approved days ago].
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-TAT-'.uniqid();
        $file->received_date = $this->daysAgo(60);
        $file->registration_no = 'BR06TA'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $vendor, $given, $status, $approved] = $work + [2 => null, 3 => null, 4 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 2000;
            $item->vendor_id = $vendor?->id;
            $item->vendor_amount = $vendor ? 1000 : null;
            $item->vendor_date = $given === null ? null : $this->daysAgo($given);
            $item->status = $status ?? ($vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE);
            $item->approved_on = $approved === null ? null : $this->daysAgo($approved);
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** One vendor's row on the Vendors report. */
    private function row(PartyModel $vendor, array $query = []): ?array
    {
        return collect($this->actingAs($this->admin)->getJson(route('report.vendors', $query))
            ->assertOk()->json('props.rows'))->firstWhere('id', $vendor->id);
    }

    // --------------------------------------------------------- their part

    /** Their HPT approved in 9 days; the office's TR still in the office. */
    public function test_they_are_done_when_their_part_is_done(): void
    {
        $this->folder([
            [$this->hpt, $this->sharma, 20, WorkFileModel::APPROVED, 11],
            [$this->tr, null],
        ]);

        $row = $this->row($this->sharma);

        $this->assertSame(0, $row['out_now'], 'out with them while the office finished its own work');
        $this->assertSame(1, $row['finished']);
        $this->assertSame(9, $row['average_days']);
    }

    /** Their HPT still out 12 days; the office's TR has nothing to do with it. */
    public function test_they_are_waited_on_for_their_own_work(): void
    {
        $this->folder([
            [$this->hpt, $this->sharma, 12],
            [$this->tr, null],
        ]);

        $row = $this->row($this->sharma);

        $this->assertSame(1, $row['out_now']);
        $this->assertSame(12, $row['longest_out']);
    }

    /** Split between two, it is each one's own — it was nobody's. */
    public function test_a_split_folder_counts_under_each_for_their_own(): void
    {
        $this->folder([
            [$this->hpt, $this->sharma, 20, WorkFileModel::APPROVED, 15],
            [$this->tr, $this->shailendra, 10],
        ]);

        $sharma = $this->row($this->sharma);
        $shailendra = $this->row($this->shailendra);

        $this->assertSame(1, $sharma['files']);
        $this->assertSame(1, $sharma['finished']);
        $this->assertSame(5, $sharma['average_days']);

        $this->assertSame(1, $shailendra['files']);
        $this->assertSame(1, $shailendra['out_now']);
        $this->assertSame(10, $shailendra['longest_out']);
    }

    /** Waiting is the oldest work still with them, not the first they were ever given. */
    public function test_waiting_is_the_oldest_work_still_with_them(): void
    {
        $this->folder([
            [$this->hpt, $this->sharma, 30, WorkFileModel::APPROVED, 25],
            [$this->tr, $this->sharma, 8],
        ]);

        $row = $this->row($this->sharma);

        $this->assertSame(8, $row['longest_out']);
        // Not finished while any of theirs is still out.
        $this->assertSame(0, $row['finished']);
    }

    // --------------------------------------------------------- not their finish

    /** Handed back undone: not out with them, and not their finish either. */
    public function test_work_handed_back_is_neither_out_nor_finished(): void
    {
        $file = $this->folder([[$this->hpt, $this->sharma, 20]]);
        $file->items()->update(['vendor_returned_on' => $this->daysAgo(10), 'status' => WorkFileModel::IN_OFFICE]);
        $file->load('items');
        $file->rollUp();
        $file->save();

        // Back in the office, still to be done: not out with them.
        $this->assertSame(0, $this->row($this->sharma)['out_now'], 'waiting on a vendor who handed it back');

        // The office finishes it after.
        $file->items()->update(['status' => WorkFileModel::APPROVED, 'approved_on' => $this->daysAgo(2)]);

        $row = $this->row($this->sharma);

        $this->assertSame(1, $row['files']);
        $this->assertSame(0, $row['out_now']);
        $this->assertSame(0, $row['finished'], 'the office\'s finish counted as theirs');
    }

    /**
     * One work finished by them, another handed back and finished by the
     * office later: they took to their own finish, not the office's.
     */
    public function test_they_took_to_their_own_finish(): void
    {
        $file = $this->folder([
            [$this->hpt, $this->sharma, 20, WorkFileModel::APPROVED, 11],
            [$this->tr, $this->sharma, 20],
        ]);
        $file->items()->where('work_type_id', $this->tr->id)
            ->update(['vendor_returned_on' => $this->daysAgo(15), 'status' => WorkFileModel::APPROVED, 'approved_on' => $this->daysAgo(2)]);

        $row = $this->row($this->sharma);

        $this->assertSame(1, $row['finished']);
        $this->assertSame(9, $row['average_days'], 'timed to the office\'s approval of the work they handed back');
    }

    /** Their one given work cancelled: in their files, and nowhere else. */
    public function test_a_cancelled_given_work_is_in_their_files_only(): void
    {
        $file = $this->folder([
            [$this->hpt, $this->sharma, 20, WorkFileModel::CANCELLED],
            [$this->tr, null, null, WorkFileModel::APPROVED, 3],
        ]);
        // As one written before roll-up learned to clear the folder's vendor.
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id, 'vendor_date' => $this->daysAgo(20)]);

        $row = $this->row($this->sharma);

        $this->assertSame(1, $row['files']);
        $this->assertSame(0, $row['out_now']);
        $this->assertSame(0, $row['finished'], 'the office\'s approved TR finished as theirs');
    }

    // ------------------------------------------------------------- the period

    /** A part is in the period of the first of their works to go out. */
    public function test_the_period_is_their_first_work_out(): void
    {
        $this->folder([
            [$this->hpt, $this->sharma, 40],
            [$this->tr, $this->sharma, 5],
        ]);

        $this->assertNull($this->row($this->sharma, ['from' => $this->daysAgo(10)]), 'cut in two by the dates');
        $this->assertSame(1, $this->row($this->sharma, ['from' => $this->daysAgo(45), 'to' => $this->daysAgo(35)])['files']);
    }

    // ------------------------------------------------------- Approval Time agrees

    /**
     * An older folder handed back whole, then finished by the office: not the
     * vendor's time on Approval Time either — the day is the folder's.
     */
    public function test_approval_time_reads_an_older_folders_hand_back_from_the_folder(): void
    {
        $file = $this->folder([[$this->hpt, null, null, WorkFileModel::APPROVED, 2]]);
        DB::table('work_file')->where('id', $file->id)->update([
            'vendor_id' => $this->sharma->id,
            'vendor_date' => $this->daysAgo(30),
            'vendor_returned_on' => $this->daysAgo(20),
            'status' => WorkFileModel::APPROVED,
        ]);

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('report.approvaltime', ['party_type' => 'vendor', 'party_id' => $this->sharma->id]))
            ->assertOk()->json('props.rows'));

        $this->assertNull($rows->firstWhere('file_no', $file->file_no), '28 days the office took, charged to the vendor');
        $this->assertSame(0, $this->row($this->sharma)['finished']);
    }
}

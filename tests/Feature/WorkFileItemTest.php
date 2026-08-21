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
 * The work a file is for, as rows.
 *
 * Papers come in for several jobs at once and each is approved separately —
 * a hypothecation addition can come back approved while a transfer is still
 * pending days later. A file with one work type and one status could describe
 * neither, so the work moved onto its own rows.
 *
 * This is the first step and it is deliberately invisible: every file has
 * exactly one item saying what the file already said. These assert that, and
 * that it stays true — because until the screens move over, a file edited
 * without its item following would leave the two disagreeing about what the
 * job is.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class WorkFileItemTest extends TestCase
{
    use DatabaseTransactions;

    private WorkTypeModel $workType;

    private PartyModel $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workType = new WorkTypeModel;
        $this->workType->name = 'Item Work '.uniqid();
        $this->workType->is_active = 1;
        $this->workType->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Item Customer '.uniqid();
        $this->customer->mobile = '9333300001';
        $this->customer->is_active = 1;
        $this->customer->save();
    }

    private function file(float $charged = 5000, string $status = 'in_office'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-ITEM-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->work_type_id = $this->workType->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = $charged;
        $file->status = $status;
        $file->save();

        return $file;
    }

    public function test_receiving_a_file_gives_it_an_item(): void
    {
        $file = $this->file();

        $this->assertCount(1, $file->items()->get());

        $item = $file->items()->first();

        $this->assertSame($this->workType->id, (int) $item->work_type_id);
        $this->assertEquals(5000, $item->customer_amount);
        $this->assertSame('in_office', $item->status);
    }

    /**
     * Until the screens write items directly, the file is still what says what
     * it is for — so an edit has to carry to the item. A stale item would have
     * the next screen to read one describing the job the file used to be.
     */
    public function test_editing_a_file_carries_to_its_item(): void
    {
        $file = $this->file();
        $other = new WorkTypeModel;
        $other->name = 'Other Work '.uniqid();
        $other->is_active = 1;
        $other->save();

        $file->work_type_id = $other->id;
        $file->customer_amount = 7500;
        $file->status = 'under_verification';
        $file->save();

        $item = $file->items()->first();

        $this->assertSame($other->id, (int) $item->work_type_id);
        $this->assertEquals(7500, $item->customer_amount);
        $this->assertSame('under_verification', $item->status);
    }

    public function test_a_file_never_grows_a_second_item_from_being_saved_twice(): void
    {
        $file = $this->file();

        $file->customer_amount = 6000;
        $file->save();
        $file->customer_amount = 6500;
        $file->save();

        $this->assertSame(1, $file->items()->count(), 'saving updates the item rather than adding one');
    }

    /**
     * Every file, not just new ones. The migration filled the table from what
     * was already there, and a file without an item is one the next step would
     * read as having no work on it at all.
     */
    public function test_no_file_anywhere_is_without_an_item(): void
    {
        $orphans = DB::table('work_file')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id'))
            ->count();

        $this->assertSame(0, $orphans, 'every file must describe what it is for');
    }

    /**
     * Approval is dated the moment it is recorded, because part approval means
     * two approvals arriving days apart and "when" is the whole question.
     */
    public function test_an_approved_item_is_dated_and_an_undone_one_is_not(): void
    {
        $file = $this->file();
        $item = $file->items()->first();

        $item->status = WorkFileModel::APPROVED;
        $item->save();

        $this->assertNotNull($item->approved_on);
        $this->assertSame(now()->toDateString(), (string) $item->approved_on);

        $item->status = 'under_verification';
        $item->save();

        $this->assertNull($item->approved_on, 'no longer approved, so no approval date');
    }

    public function test_an_item_knows_when_nothing_further_is_expected_of_it(): void
    {
        $item = new WorkFileItemModel;

        foreach ([WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED] as $settled) {
            $item->status = $settled;
            $this->assertTrue($item->isSettled(), "$settled is finished");
        }

        foreach (WorkFileModel::OPEN_STATUSES as $open) {
            $item->status = $open;
            $this->assertFalse($item->isSettled(), "$open is still in hand");
        }
    }

    /**
     * Deleting a file takes its items with it. They describe that file and
     * nothing else, and rows pointing at a file that is gone would be counted
     * by anything that later sums work across items.
     */
    public function test_items_go_when_their_file_goes(): void
    {
        $file = $this->file();
        $id = $file->id;

        $file->delete();

        $this->assertSame(0, WorkFileItemModel::where('work_file_id', $id)->count());
    }
}

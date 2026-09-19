<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A folder split between two vendors.
 *
 * One job to the customer, several to the office — and the office does not send
 * them all to the same person. One agent is quick with transfers, another has
 * the bank contact, so a folder holding a transfer and a hypothecation addition
 * goes to both of them.
 *
 * The money has to follow the work. Each vendor is owed for the works they were
 * given and for nothing else, their line says which works it is for, and the
 * folder that went to one vendor writes the one line it always wrote — to the
 * same party, with the same words on it, because an unsplit file must not read
 * differently on a statement somebody has already filed.
 *
 * Nothing on screen creates a split yet. This is the shape underneath the
 * screens that will, so the splits here are built by hand.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class SplitVendorLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Split Admin';
        $this->admin->email = 'split-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');
        $this->shailendra = $this->party('vendor');
        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for the split';
        $party->mobile = '93300'.random_int(10000, 99999);
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

    /**
     * A folder, and its works: [work type, charged, [vendor, cost, given on]].
     *
     * @param  array<int, array{0: WorkTypeModel, 1: float, 2: ?array}>  $works
     */
    private function file(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-SV-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01SV'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        foreach ($works as [$type, $charged, $vendor]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->status = WorkFileModel::DISPATCHED;

            if ($vendor) {
                $item->vendor_id = $vendor[0]->id;
                $item->vendor_amount = $vendor[1];
                $item->vendor_date = $vendor[2] ?? '2026-09-02';
            }

            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** Read the works back, roll the folder up from them, and repost the ledger. */
    private function resync(WorkFileModel $file): void
    {
        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();
    }
    /** @return array<int, object> the vendor lines on this file, by party */
    private function lines(WorkFileModel $file, string $role = 'vendor'): array
    {
        return PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', $role)
            ->get()
            ->keyBy('party_id')
            ->all();
    }

    // ------------------------------------------------------------------ the split

    public function test_each_vendor_is_owed_for_their_own_work(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $lines = $this->lines($file);

        $this->assertCount(2, $lines, 'a split folder did not write a line each');
        $this->assertEquals(3000, $lines[$this->sharma->id]->amount);
        $this->assertEquals(1200, $lines[$this->shailendra->id]->amount);

        $this->assertSame(-3000.0, PartyLedgerModel::currentBalance($this->sharma->id));
        $this->assertSame(-1200.0, PartyLedgerModel::currentBalance($this->shailendra->id));
    }

    /** Two lines against one file number have to say which work each is for. */
    public function test_a_shared_folder_names_the_works_on_each_line(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $lines = $this->lines($file);

        $this->assertStringContainsString($this->tr->name, $lines[$this->sharma->id]->particular);
        $this->assertStringNotContainsString($this->hpa->name, $lines[$this->sharma->id]->particular);
        $this->assertStringContainsString($this->hpa->name, $lines[$this->shailendra->id]->particular);
    }

    public function test_a_vendor_with_two_of_three_is_owed_for_both(): void
    {
        $third = $this->workType('HPT');

        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->sharma, 1200]],
            [$third, 1000, [$this->shailendra, 600]],
        ]);

        $lines = $this->lines($file);

        $this->assertEquals(4200, $lines[$this->sharma->id]->amount);
        $this->assertEquals(600, $lines[$this->shailendra->id]->amount);
    }

    /** The day the folder started being out is the earliest of its works. */
    public function test_each_line_is_dated_from_the_day_that_work_went_out(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000, '2026-09-05']],
            [$this->hpa, 2000, [$this->shailendra, 1200, '2026-09-11']],
        ]);

        $lines = $this->lines($file);

        $this->assertSame('2026-09-05', substr((string) $lines[$this->sharma->id]->txn_date, 0, 10));
        $this->assertSame('2026-09-11', substr((string) $lines[$this->shailendra->id]->txn_date, 0, 10));
        $this->assertSame('2026-09-05', $file->vendor_date, 'the folder is out from the day the first work went');
    }

    // ------------------------------------------------------------ the folder itself

    public function test_a_split_folder_names_no_single_vendor(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $this->assertNull($file->vendor_id, 'one of the two vendors was put on the folder');
        $this->assertEquals(4200, $file->vendor_amount, 'the folder still costs what its works cost');
    }

    public function test_a_folder_whose_works_agree_still_names_its_vendor(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->sharma, 1200]],
        ]);

        $this->assertSame($this->sharma->id, (int) $file->vendor_id);
        $this->assertCount(1, $this->lines($file), 'an unsplit folder wrote more than one line');
    }

    // ------------------------------------------------------- work moving vendor

    /**
     * A line on a statement nobody is owed is money the office thinks it has to
     * pay, so the vendor who lost the work loses the line with it.
     */
    public function test_moving_a_work_takes_the_money_with_it(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $item = $file->items()->where('work_type_id', $this->hpa->id)->first();
        $item->vendor_id = $this->sharma->id;
        $item->save();

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $lines = $this->lines($file->fresh());

        $this->assertCount(1, $lines, 'the vendor who lost the work kept a line');
        $this->assertEquals(4200, $lines[$this->sharma->id]->amount);
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->shailendra->id));
    }

    public function test_cancelling_a_work_takes_its_vendor_off_the_file(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $item = $file->items()->where('work_type_id', $this->hpa->id)->first();
        $item->status = WorkFileModel::CANCELLED;
        $item->save();

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->assertCount(1, $this->lines($file->fresh()));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->shailendra->id),
            'a cancelled work still owed its vendor');
    }

    public function test_cancelling_the_folder_clears_every_vendor(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $file->status = WorkFileModel::CANCELLED;
        $file->items()->update(['status' => WorkFileModel::CANCELLED]);
        $file->save();
        $file->syncLedger();

        $this->assertSame([], $this->lines($file->fresh()));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->sharma->id));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->shailendra->id));
    }

    // ----------------------------------------------------------- coming back

    /** One vendor hands their work back while the other still has theirs. */
    public function test_a_reversal_is_written_only_for_the_work_that_came_back(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $item = $file->items()->where('work_type_id', $this->hpa->id)->first();
        $item->vendor_returned_on = '2026-09-20';
        $item->save();

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $reversals = $this->lines($file->fresh(), 'vendor_return');

        $this->assertCount(1, $reversals, 'a vendor still holding the work had it reversed');
        $this->assertEquals(1200, $reversals[$this->shailendra->id]->amount);

        // Shailendra is square; Sharma is still owed for the work he has.
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->shailendra->id));
        $this->assertSame(-3000.0, PartyLedgerModel::currentBalance($this->sharma->id));
        $this->assertNull($file->fresh()->vendor_returned_on, 'the folder is back while half of it is out');
    }

    public function test_the_folder_is_back_when_the_last_work_is_back(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $file->items()->update(['vendor_returned_on' => '2026-09-22']);

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->assertSame('2026-09-22', $file->fresh()->vendor_returned_on);
        $this->assertCount(2, $this->lines($file->fresh(), 'vendor_return'));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->sharma->id));
        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->shailendra->id));
    }

    /**
     * A return taken back takes its reversal with it.
     *
     * Undoing one vendor's return while the other's stands has to leave one
     * reversal on the file, not two — a reversal for work that is still out is
     * money the office has stopped owing for work nobody has done.
     */
    public function test_undoing_one_return_leaves_the_other_alone(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->shailendra, 1200]],
        ]);

        $file->items()->update(['vendor_returned_on' => '2026-09-22']);
        $this->resync($file);

        $this->assertCount(2, $this->lines($file->fresh(), 'vendor_return'));

        // Sharma's was recorded by mistake: his work never came back.
        $file->items()->where('work_type_id', $this->tr->id)->update(['vendor_returned_on' => null]);
        $this->resync($file);

        $reversals = $this->lines($file->fresh(), 'vendor_return');

        $this->assertCount(1, $reversals, 'a reversal stayed on work that is still out');
        $this->assertArrayHasKey($this->shailendra->id, $reversals);
        $this->assertSame(-3000.0, PartyLedgerModel::currentBalance($this->sharma->id), 'Sharma is owed again');
    }

    // -------------------------------------------------- the screens reach the works

    /**
     * Give to Vendor hands over the works, not just the folder.
     *
     * The folder's vendor is worked out from its works now, so a handover that
     * told only the folder would roll straight back up as in-house work — and
     * the vendor would be owed nothing for a file sitting on his desk.
     */
    public function test_giving_a_file_to_a_vendor_gives_its_works_to_that_vendor(): void
    {
        $file = $this->file([[$this->tr, 5000, null], [$this->hpa, 2000, null]]);

        // Rates are posted per work, which is what the form sends.
        $rates = $file->items()->pluck('id')->mapWithKeys(fn ($id) => [$id => 2000])->all();

        $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->sharma->id,
            'vendor_date' => '2026-09-14',
            'files' => [$file->id],
            'amounts' => $rates,
        ])->assertSessionHasNoErrors();

        foreach ($file->items()->get() as $item) {
            $this->assertSame($this->sharma->id, (int) $item->vendor_id, 'a work stayed in-house');
            $this->assertSame('2026-09-14', $item->vendor_date);
        }

        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
        $this->assertSame(-4000.0, PartyLedgerModel::currentBalance($this->sharma->id));
    }

    /** And moving the file to another vendor moves every work with it. */
    public function test_changing_the_vendor_on_the_file_screen_moves_every_work(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->sharma, 1200]],
        ]);

        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'status' => $file->status,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '7000',
            'vendor_id' => $this->shailendra->id,
            'vendor_amount' => '4200',
            'vendor_date' => '2026-09-08',
        ])->assertSessionHasNoErrors();

        foreach ($file->items()->get() as $item) {
            $this->assertSame($this->shailendra->id, (int) $item->vendor_id, 'a work stayed with the old vendor');
        }

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($this->sharma->id), 'the old vendor is still owed');
        $this->assertSame(-4200.0, PartyLedgerModel::currentBalance($this->shailendra->id));
    }
    // ----------------------------------------------------- the unsplit file is unmoved

    /**
     * The whole point of keying on the party: a folder that went to one vendor
     * reads exactly as it did before any of this.
     */
    public function test_a_folder_with_one_vendor_writes_the_line_it_always_did(): void
    {
        $file = $this->file([
            [$this->tr, 5000, [$this->sharma, 3000]],
            [$this->hpa, 2000, [$this->sharma, 1200]],
        ]);

        $line = $this->lines($file)[$this->sharma->id];

        $this->assertSame($file->ledgerParticular(), $line->particular,
            'an unsplit folder started naming its works on the statement');
        $this->assertEquals(4200, $line->amount);
    }

    /** And a file whose works carry no vendor at all is written from the folder. */
    public function test_a_folder_whose_works_know_no_vendor_is_still_posted(): void
    {
        $file = $this->file([[$this->tr, 5000, null]]);

        $file->vendor_id = $this->sharma->id;
        $file->vendor_amount = 2500;
        $file->vendor_date = '2026-09-03';
        $file->save();
        $file->syncLedger();

        $this->assertSame(-2500.0, PartyLedgerModel::currentBalance($this->sharma->id));
    }
}

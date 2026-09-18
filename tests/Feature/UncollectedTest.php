<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
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
 * Work that is done and not paid for.
 *
 * The ledger says what a customer owes in one figure. What it does not say is
 * which jobs that figure is made of, and "you owe 62,000" is an argument where
 * "these four files, the oldest from July" is a conversation.
 *
 * Money arrives against the account rather than against a file — nothing on a
 * receipt says which files it covered — so the oldest charge is settled first.
 * That is a convention, and these tests are mostly about the places where a
 * convention could quietly become a wrong number: a refund, which does know its
 * file; a charge typed straight into the ledger, which belongs to no file but
 * still takes its turn; and a part payment, which must leave the remainder
 * against the right file rather than clearing it.
 *
 * The invariant underneath all of it: what the files say is owed adds up to
 * what the statement says is owed.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class UncollectedTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Collections Admin';
        $this->admin->email = 'collections-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party();

        $this->type = new WorkTypeModel;
        $this->type->name = 'Collections Work '.uniqid();
        $this->type->is_active = 1;
        $this->type->save();
    }

    private function party(): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Customer '.uniqid().' who owes';
        $party->mobile = '93900'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A file, charged, and finished $finishedDaysAgo days ago unless said otherwise. */
    private function file(float $charged, ?int $finishedDaysAgo = 10, string $status = WorkFileModel::APPROVED, ?PartyModel $customer = null): WorkFileModel
    {
        $customer = $customer ?: $this->customer;

        $file = new WorkFileModel;
        $file->file_no = 'F-UC-'.uniqid();
        $file->received_date = now()->subDays(($finishedDaysAgo ?? 0) + 20)->toDateString();
        $file->registration_no = 'BR01UC'.random_int(1000, 9999);
        $file->work_type_id = $this->type->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = $charged;
        $file->status = $status;

        if ($status === WorkFileModel::RETURNED && $finishedDaysAgo !== null) {
            $file->returned_on = now()->subDays($finishedDaysAgo)->toDateString();
        }

        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->type->id;
        $item->customer_amount = $charged;
        $item->status = $status;
        $item->approved_on = ($status === WorkFileModel::APPROVED && $finishedDaysAgo !== null)
            ? now()->subDays($finishedDaysAgo)->toDateString()
            : null;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /** Money in, against the account, the way the counter takes it. */
    private function pay(float $amount, ?int $daysAgo = 1, ?PartyModel $customer = null): void
    {
        DB::table('party_ledger')->insert([
            'party_id' => ($customer ?: $this->customer)->id,
            'work_file_id' => null,
            'file_role' => null,
            'txn_date' => now()->subDays($daysAgo)->toDateString(),
            'entry_type' => 'credit',
            'amount' => $amount,
            'payment_mode' => 'cash',
            'particular' => 'Received on account',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, float> file id => still owed */
    private function owed(?PartyModel $customer = null): array
    {
        $customer = $customer ?: $this->customer;

        return PartyLedgerModel::outstandingByFile([$customer->id])[$customer->id] ?? [];
    }

    private function rows(array $query = [])
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('report.uncollected', $query))->assertOk()->json('props.rows'));
    }

    // ------------------------------------------------------------ the oldest first

    public function test_a_payment_settles_the_oldest_charge_first(): void
    {
        $old = $this->file(5000, 40);
        $new = $this->file(3000, 5);

        $this->pay(5000);

        $owed = $this->owed();

        $this->assertArrayNotHasKey($old->id, $owed, 'the older file was not settled first');
        $this->assertEquals(3000, $owed[$new->id]);
    }

    public function test_a_part_payment_leaves_the_rest_against_the_same_file(): void
    {
        $file = $this->file(5000, 30);

        $this->pay(2000);

        $this->assertEquals(3000, $this->owed()[$file->id]);
        $this->assertSame('part paid', $this->rows()->firstWhere('id', $file->id)['part_paid']);
    }

    public function test_paying_everything_empties_the_list(): void
    {
        $this->file(5000, 30);
        $this->file(3000, 10);

        $this->pay(8000);

        $this->assertSame([], $this->owed());
        $this->assertSame(0, $this->rows()->where('customer_id', $this->customer->id)->count());
    }

    /** Money paid before the work was charged is still money paid. */
    public function test_an_advance_covers_the_charge_that_follows_it(): void
    {
        $this->pay(5000, 60);
        $this->file(4000, 10);

        $this->assertSame([], $this->owed(), 'an advance did not cover the later charge');
    }

    // ----------------------------------------------------------------- the exceptions

    /**
     * A refund knows its file.
     *
     * Papers returned on a later file must not settle an earlier one — the
     * customer never paid that earlier charge, and the refund was not money
     * they handed over.
     */
    public function test_a_refund_comes_off_its_own_file_and_no_other(): void
    {
        $old = $this->file(5000, 40);
        $returned = $this->file(3000, 5, WorkFileModel::RETURNED);

        $owed = $this->owed();

        $this->assertEquals(5000, $owed[$old->id], 'a refund was applied to the wrong file');
        $this->assertArrayNotHasKey($returned->id, $owed, 'the refunded file is still owed for');
    }

    /**
     * A charge typed straight into the ledger belongs to no file, and still
     * takes its turn: leaving it out would make every file look better paid
     * than it is.
     */
    public function test_a_charge_with_no_file_still_takes_its_turn(): void
    {
        DB::table('party_ledger')->insert([
            'party_id' => $this->customer->id,
            'work_file_id' => null,
            'file_role' => null,
            'txn_date' => now()->subDays(70)->toDateString(),
            'entry_type' => 'debit',
            'amount' => 2000,
            'particular' => 'Opening balance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = $this->file(5000, 40);

        $this->pay(2000);

        // The file came in 60 days ago; this charge is older still, so the
        // payment goes to it and the file is untouched.
        $this->assertEquals(5000, $this->owed()[$file->id]);
    }

    public function test_a_cancelled_file_is_charged_nothing_and_owed_nothing(): void
    {
        $file = $this->file(5000, 20, WorkFileModel::CANCELLED);

        $this->assertArrayNotHasKey($file->id, $this->owed());
    }

    // ------------------------------------------------------------------ the totals

    /** What the files say is owed adds up to what the statement says. */
    public function test_the_files_add_up_to_the_balance(): void
    {
        $this->file(5000, 40);
        $this->file(3000, 20);
        $this->file(2500, 5);
        $this->pay(4000, 3);

        $this->assertEqualsWithDelta(
            PartyLedgerModel::currentBalance($this->customer->id),
            array_sum($this->owed()),
            0.01,
            'the files and the statement disagree about what is owed'
        );
    }

    // ------------------------------------------------------------------- the screen

    public function test_it_lists_finished_work_only_until_asked_for_everything(): void
    {
        $done = $this->file(5000, 12);
        $running = $this->file(4000, null, WorkFileModel::DISPATCHED);

        $finished = $this->rows();

        $this->assertNotNull($finished->firstWhere('id', $done->id));
        $this->assertNull($finished->firstWhere('id', $running->id), 'work in progress was called money not collected');

        $everything = $this->rows(['show' => 'all']);

        $this->assertNotNull($everything->firstWhere('id', $running->id));
    }

    public function test_the_longest_owed_is_at_the_top(): void
    {
        $recent = $this->file(1000, 3);
        $ancient = $this->file(1000, 90);
        $middle = $this->file(1000, 30);

        $mine = $this->rows()->whereIn('id', [$recent->id, $ancient->id, $middle->id])->pluck('id')->values();

        $this->assertSame([$ancient->id, $middle->id, $recent->id], $mine->all());
    }

    public function test_a_row_says_what_it_needs_to_say(): void
    {
        $file = $this->file(5000, 12);

        $row = $this->rows()->firstWhere('id', $file->id);

        $this->assertSame($file->file_no, $row['file_no']);
        $this->assertSame($this->customer->name, $row['customer']);
        $this->assertSame(now()->subDays(12)->format('d-m-Y'), $row['finished']);
        $this->assertSame('12 days', $row['days_text']);
        $this->assertEquals(5000, $row['outstanding']);
        $this->assertSame(route('party.statement', $this->customer->id), $row['customer_url']);
        // Papers still with the office are the ones there is something to hold.
        $this->assertSame('With the office', $row['handed_over']);
    }

    public function test_it_can_be_read_one_customer_at_a_time(): void
    {
        $mine = $this->file(5000, 20);

        $other = $this->party();
        $theirs = $this->file(4000, 20, WorkFileModel::APPROVED, $other);

        $rows = $this->rows(['party_id' => $this->customer->id]);

        $this->assertNotNull($rows->firstWhere('id', $mine->id));
        $this->assertNull($rows->firstWhere('id', $theirs->id), 'another customer is on the list');
    }

    public function test_the_page_loads_and_says_how_it_counts(): void
    {
        $this->file(5000, 20);

        $this->actingAs($this->admin)->get(route('report.uncollected'))
            ->assertOk()
            ->assertSee('Not Yet Collected')
            ->assertSee('oldest charge is')
            ->assertSee($this->customer->name);
    }

    public function test_it_is_reachable_from_the_menu(): void
    {
        $this->actingAs($this->admin)->get(route('report.profit'))
            ->assertOk()
            ->assertSee(route('report.uncollected'));
    }

    public function test_a_stranger_cannot_read_it(): void
    {
        $this->get(route('report.uncollected'))->assertRedirect();
    }
}

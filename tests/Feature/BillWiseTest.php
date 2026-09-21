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
 * Adjusting a payment against the files it was for.
 *
 * A dealer pays 10,000 "for F-00050 and F-00057". Before, nothing recorded
 * that and the oldest charges were treated as paid; now the office can say so,
 * and those files are the ones settled. Money nobody adjusts still settles the
 * oldest first — so with nothing adjusted, every figure is what it was.
 *
 * Read for three things: the files named are the ones settled, the files still
 * add up to the balance whatever happens to them afterwards, and nothing is
 * written from a payment the server refuses.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class BillWiseTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Bill Wise Admin';
        $this->admin->email = 'bill-wise-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' bill wise';
        $party->mobile = '93200'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A file of one work, charged to a customer (and given to a vendor, if one is named). */
    private function file(float $charge, string $received, ?PartyModel $customer = null, ?PartyModel $vendor = null, float $cost = 0): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-BW-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01BW'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = ($customer ?? $this->customer)->id;
        $file->customer_amount = $charge;
        $file->vendor_id = $vendor?->id;
        $file->vendor_amount = $vendor ? $cost : null;
        $file->vendor_date = $vendor ? $received : null;
        $file->status = $vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = $charge;
        $item->vendor_id = $vendor?->id;
        $item->vendor_amount = $vendor ? $cost : null;
        $item->vendor_date = $vendor ? $received : null;
        $item->status = $file->status;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /**
     * The Entry screen's post: a payment, adjusted against files.
     *
     * @param  array<int, float>  $against  file id => amount
     */
    private function pay(float $amount, array $against = [], ?PartyModel $party = null, array $extra = [])
    {
        $party ??= $this->customer;
        $alloc = [];

        foreach ($against as $fileId => $share) {
            $alloc[$fileId] = ['work_file_id' => $fileId, 'amount' => $share];
        }

        return $this->actingAs($this->admin)
            ->from(route('party.entry', $party->party_type))
            ->post(route('party.entry', $party->party_type), $extra + [
                'party_id' => $party->id,
                'entry_type' => $party->party_type === 'customer' ? 'credit' : 'debit',
                'txn_date' => '2026-09-20',
                'amount' => $amount,
                'payment_mode' => 'UPI',
                'particular' => 'Payment',
                'alloc' => $alloc,
            ]);
    }

    /** What is still owed, file by file, as Not Yet Collected reads it. */
    private function owed(?PartyModel $party = null, string $side = 'debit'): array
    {
        $party ??= $this->customer;

        return PartyLedgerModel::outstandingByFile([$party->id], $side)[$party->id] ?? [];
    }

    // --------------------------------------------------------------- the rule

    /** The whole point: the file named is the one settled, not the oldest. */
    public function test_a_payment_adjusted_against_a_file_settles_that_file_not_the_oldest(): void
    {
        $older = $this->file(3000, '2026-08-01');
        $newer = $this->file(5000, '2026-09-01');

        $this->pay(5000, [$newer->id => 5000])->assertSessionHas('success');

        $this->assertSame([$older->id => 3000.0], $this->owed());
    }

    /** Unadjusted money settles the oldest first, exactly as before. */
    public function test_money_not_adjusted_still_settles_the_oldest_first(): void
    {
        $older = $this->file(3000, '2026-08-01');
        $newer = $this->file(5000, '2026-09-01');

        $this->pay(5000)->assertSessionHas('success');

        $this->assertSame([$newer->id => 3000.0], $this->owed());
    }

    public function test_the_rest_of_a_part_adjusted_payment_settles_the_oldest(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(4000, '2026-08-15');
        $c = $this->file(5000, '2026-09-01');

        // 6,000: 5,000 for C, the other 1,000 on account, which takes A first.
        $this->pay(6000, [$c->id => 5000]);

        $this->assertSame([$a->id => 2000.0, $b->id => 4000.0], $this->owed());
    }

    /** An advance for a particular file, paid before it was even charged. */
    public function test_a_payment_dated_before_the_charge_still_settles_its_file(): void
    {
        $earlier = $this->file(3000, '2026-08-01');
        $later = $this->file(5000, '2026-09-25');

        $this->pay(5000, [$later->id => 5000], null, ['txn_date' => '2026-09-01']);

        $this->assertSame([$earlier->id => 3000.0], $this->owed());
    }

    /** Whatever happens to the files afterwards, they add up to the balance. */
    public function test_a_file_re_priced_below_what_was_adjusted_keeps_the_files_adding_up(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(5000, '2026-09-01');

        $this->pay(5000, [$b->id => 5000]);

        // B re-priced to 4,000: settled, and the 1,000 over goes on account — to A.
        $b->customer_amount = 4000;
        $b->save();
        $item = $b->items()->first();
        $item->customer_amount = 4000;
        $item->save();
        $b->syncLedger();

        $owed = $this->owed();

        $this->assertSame([$a->id => 2000.0], $owed);
        $this->assertEqualsWithDelta(array_sum($owed), PartyLedgerModel::currentBalance($this->customer->id), 0.001);
    }

    /** Cancelled, the file has nothing to settle and the money goes on account; un-cancelled, it comes back. */
    public function test_cancelling_and_un_cancelling_the_file_moves_the_adjustment_with_it(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(5000, '2026-09-01');

        $this->pay(5000, [$b->id => 5000]);

        $b->status = WorkFileModel::CANCELLED;
        $b->save();
        $b->items()->update(['status' => WorkFileModel::CANCELLED]);
        $b->syncLedger();

        // B is gone; the 5,000 is on account and settles A, with 2,000 over in credit.
        $this->assertSame([], $this->owed());

        $b->status = WorkFileModel::IN_OFFICE;
        $b->save();
        $b->items()->update(['status' => WorkFileModel::IN_OFFICE]);
        $b->syncLedger();

        $this->assertSame([$a->id => 3000.0], $this->owed(), 'the adjustment did not come back with the file');
    }

    /** The money is the payer's: it never follows a file to another customer. */
    public function test_a_file_given_to_another_customer_leaves_the_payment_with_the_one_who_paid(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(5000, '2026-09-01');
        $other = $this->party('customer');

        $this->pay(5000, [$b->id => 5000]);

        $b->customer_id = $other->id;
        $b->save();
        $b->syncLedger();

        // This customer's 5,000 is on account with them: A settled, 2,000 in credit.
        $this->assertSame([], $this->owed());
        $this->assertSame([$b->id => 5000.0], $this->owed($other), 'the payment followed the file');
    }

    public function test_an_adjustment_released_is_read_as_never_made(): void
    {
        $older = $this->file(3000, '2026-08-01');
        $newer = $this->file(5000, '2026-09-01');

        $this->pay(5000, [$newer->id => 5000]);

        DB::table('party_ledger_allocation')->where('party_id', $this->customer->id)->update(['released_at' => now()]);

        $this->assertSame([$newer->id => 3000.0], $this->owed());
    }

    // --------------------------------------------------------------- vendors

    /** The other way round: what the office owes a vendor, file by file. */
    public function test_a_payment_to_a_vendor_is_adjusted_against_their_files(): void
    {
        $vendor = $this->party('vendor');
        $older = $this->file(3000, '2026-08-01', null, $vendor, 1800);
        $newer = $this->file(5000, '2026-09-01', null, $vendor, 2500);

        $this->pay(2500, [$newer->id => 2500], $vendor)->assertSessionHas('success');

        $this->assertSame([$older->id => 1800.0], $this->owed($vendor, 'credit'));
    }

    // ------------------------------------------------------------- the save

    public function test_the_payment_and_its_adjustments_are_written_together_with_who_typed_them(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(5000, '2026-09-01');

        $this->pay(6000, [$a->id => 1000, $b->id => 5000]);

        $entry = PartyLedgerModel::where('party_id', $this->customer->id)->whereNull('work_file_id')->latest('id')->first();

        $this->assertSame($this->admin->id, (int) $entry->created_by);

        $lines = DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->orderBy('work_file_id')->get();

        $this->assertSame([$a->id, $b->id], $lines->pluck('work_file_id')->map(fn ($id) => (int) $id)->all());
        $this->assertEquals([1000, 5000], $lines->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertTrue($lines->every(fn ($line) => (int) $line->created_by === $this->admin->id && (int) $line->party_id === $this->customer->id));
    }

    /** Every refusal writes nothing at all — not the payment, not a line. */
    private function assertRefusedAndNothingWritten($response): void
    {
        $response->assertSessionHasErrors('alloc');

        $this->assertSame(0, PartyLedgerModel::where('party_id', $this->customer->id)->whereNull('work_file_id')->count(), 'the payment was saved');
        $this->assertSame(0, DB::table('party_ledger_allocation')->where('party_id', $this->customer->id)->count(), 'a line was saved');
    }

    public function test_files_adding_up_to_more_than_the_payment_are_refused(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(5000, '2026-09-01');

        $this->assertRefusedAndNothingWritten($this->pay(6000, [$a->id => 3000, $b->id => 5000]));
    }

    public function test_more_than_is_open_on_a_file_is_refused(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $this->assertRefusedAndNothingWritten($this->pay(5000, [$a->id => 3500]));
        $this->assertStringContainsString('only 3,000.00 left', session('errors')->first('alloc'));
    }

    public function test_another_customers_file_is_refused(): void
    {
        $theirs = $this->file(3000, '2026-08-01', $this->party('customer'));

        $this->assertRefusedAndNothingWritten($this->pay(3000, [$theirs->id => 3000]));
    }

    public function test_a_cancelled_file_is_refused(): void
    {
        $gone = $this->file(3000, '2026-08-01');
        $gone->status = WorkFileModel::CANCELLED;
        $gone->save();
        $gone->items()->update(['status' => WorkFileModel::CANCELLED]);
        $gone->syncLedger();

        $this->assertRefusedAndNothingWritten($this->pay(3000, [$gone->id => 3000]));
    }

    /** A charge is not a payment: nothing to adjust. */
    public function test_a_charge_cannot_be_adjusted_against_files(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $this->assertRefusedAndNothingWritten($this->pay(3000, [$a->id => 3000], null, ['entry_type' => 'debit']));
    }

    /** Checked against what the ledger says, not what the page showed: two payments cannot both take one file. */
    public function test_a_second_payment_cannot_take_what_the_first_already_took(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $this->pay(3000, [$a->id => 3000])->assertSessionHas('success');

        $this->pay(3000, [$a->id => 3000])->assertSessionHasErrors('alloc');
    }

    public function test_a_refused_save_puts_the_amounts_back_on_the_page(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $this->pay(5000, [$a->id => 3500]);

        $props = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->assertOk()->json('props');

        $this->assertEquals(['3500'], array_values($props['initialAlloc']));
        $this->assertSame((string) $a->id, (string) array_key_first($props['initialAlloc']));
    }

    // ------------------------------------------------- found in review: the save

    /** Empty boxes are not lines: a party with hundreds of files can still be paid. */
    public function test_hundreds_of_empty_lines_do_not_refuse_the_payment(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $alloc = [];

        for ($i = 1; $i <= 250; $i++) {
            $alloc[1000000 + $i] = ['work_file_id' => 1000000 + $i, 'amount' => ''];
        }

        $alloc[$a->id] = ['work_file_id' => $a->id, 'amount' => ''];

        $this->actingAs($this->admin)->from(route('party.entry', 'customer'))->post(route('party.entry', 'customer'), [
            'party_id' => $this->customer->id,
            'entry_type' => 'credit',
            'txn_date' => '2026-09-20',
            'amount' => 3000,
            'payment_mode' => 'UPI',
            'particular' => 'Payment',
            'alloc' => $alloc,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    /** Rounded before it is judged: less than a paisa is no line, not a line of 0.00. */
    public function test_an_amount_under_a_paisa_is_no_line_at_all(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $this->pay(3000, [$a->id => 0.004])->assertSessionHas('success');

        $this->assertSame(0, DB::table('party_ledger_allocation')->where('party_id', $this->customer->id)->count());
        $this->assertSame([], session('receipt')['against']);
    }

    // ------------------------------------------------------------ the list

    /**
     * Found in review: every file ever charged was offered, paid long ago by
     * money nobody adjusted. Only what is still owed, unless asked for more.
     */
    public function test_files_already_covered_by_money_on_account_are_listed_only_when_asked(): void
    {
        $old = $this->file(3000, '2026-08-01');
        $new = $this->file(5000, '2026-09-01');
        $this->pay(3000);

        $url = route('party.bills', $this->customer->id);

        $default = $this->actingAs($this->admin)->getJson($url)->assertOk()->json();
        $this->assertSame([$new->id], array_column($default['bills'], 'id'));
        $this->assertSame(1, $default['covered']);

        $all = $this->actingAs($this->admin)->getJson($url.'?all=1')->assertOk()->json('bills');
        $this->assertSame([$old->id, $new->id], array_column($all, 'id'));
        $this->assertEquals(0, $all[0]['due']);
        $this->assertEquals(3000, $all[0]['open']);
    }

    // ---------------------------------------------- found in review: the label

    /** A file given to someone else since: the payer's statement must not name their vehicle. */
    public function test_a_file_moved_to_another_customer_is_not_named_on_the_payers_statement(): void
    {
        $b = $this->file(5000, '2026-09-01');
        $this->pay(5000, [$b->id => 5000]);

        $b->customer_id = $this->party('customer')->id;
        $b->registration_no = 'BR01ZZ9999';
        $b->save();
        $b->syncLedger();

        $entry = PartyLedgerModel::where('party_id', $this->customer->id)->whereNull('work_file_id')->latest('id')->value('id');

        $this->assertSame([], PartyLedgerModel::againstFor([$entry]));

        $said = json_encode($this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))->json('props'));
        $this->assertStringNotContainsString('BR01ZZ9999', $said);
    }

    /** The charge above it leaves a cancelled work out; so does the line under it. */
    public function test_a_cancelled_work_is_not_named_against_a_payment(): void
    {
        $file = $this->file(3000, '2026-08-01');

        $hpa = new WorkTypeModel;
        $hpa->name = 'HPA '.uniqid();
        $hpa->is_active = 1;
        $hpa->save();

        $cancelled = new WorkFileItemModel;
        $cancelled->work_file_id = $file->id;
        $cancelled->work_type_id = $hpa->id;
        $cancelled->customer_amount = 0;
        $cancelled->status = WorkFileModel::CANCELLED;
        $cancelled->save();

        $this->pay(3000, [$file->id => 3000]);

        $label = session('receipt')['against'][0]['label'];

        $this->assertStringContainsString($this->tr->name, $label);
        $this->assertStringNotContainsString($hpa->name, $label);
    }

    /** A folder split between two vendors: each vendor's line names only their own works. */
    public function test_a_vendors_line_names_only_the_works_they_were_given(): void
    {
        $mine = $this->party('vendor');
        $theirs = $this->party('vendor');
        $file = $this->file(3000, '2026-08-01', null, $mine, 1800);

        $hpa = new WorkTypeModel;
        $hpa->name = 'HPA '.uniqid();
        $hpa->is_active = 1;
        $hpa->save();

        $other = new WorkFileItemModel;
        $other->work_file_id = $file->id;
        $other->work_type_id = $hpa->id;
        $other->customer_amount = 2000;
        $other->vendor_id = $theirs->id;
        $other->vendor_amount = 1200;
        $other->vendor_date = '2026-08-01';
        $other->status = WorkFileModel::DISPATCHED;
        $other->save();
        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->pay(1800, [$file->id => 1800], $mine)->assertSessionHas('success');

        $entry = PartyLedgerModel::where('party_id', $mine->id)->whereNull('work_file_id')->latest('id')->value('id');
        $label = PartyLedgerModel::againstFor([$entry])[$entry][0]['label'];

        $this->assertStringContainsString($this->tr->name, $label);
        $this->assertStringNotContainsString($hpa->name, $label, 'another vendor\'s work was named');

        $bills = $this->actingAs($this->admin)->getJson(route('party.bills', $mine->id).'?all=1')->json('bills');
        $this->assertStringNotContainsString($hpa->name, $bills[0]['works'] ?? '');
    }

    public function test_the_entry_screen_lists_the_files_still_open_oldest_first(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(5000, '2026-09-01');
        $paid = $this->file(1000, '2026-07-01');
        $this->pay(1000, [$paid->id => 1000]);

        $bills = $this->actingAs($this->admin)->getJson(route('party.bills', $this->customer->id))->assertOk()->json('bills');

        $this->assertSame([$a->id, $b->id], array_column($bills, 'id'), 'a settled file was offered');
        $this->assertSame($a->file_no, $bills[0]['fileNo']);
        $this->assertEquals(3000, $bills[0]['open']);
        $this->assertSame(route('workfile.edit', $a->id), $bills[0]['editUrl']);
    }

    public function test_nobody_signed_out_can_list_them(): void
    {
        $this->get(route('party.bills', $this->customer->id))->assertRedirect();
    }

    // ------------------------------------------------------------ the audit

    /** Ordinary use makes this, and the office should know where the money went. */
    public function test_the_audit_names_a_file_cancelled_after_it_was_adjusted_against(): void
    {
        $b = $this->file(5000, '2026-09-01');
        $this->pay(5000, [$b->id => 5000]);

        $b->status = WorkFileModel::CANCELLED;
        $b->save();
        $b->items()->update(['status' => WorkFileModel::CANCELLED]);
        $b->syncLedger();

        $this->artisan('files:audit')->expectsOutputToContain('no longer charges that party')->run();
    }

    /** Nothing on a screen can do this; a hand in the database could. */
    public function test_the_audit_names_a_payment_adjusted_for_more_than_it_was(): void
    {
        $b = $this->file(5000, '2026-09-01');
        $this->pay(3000, [$b->id => 3000]);

        DB::table('party_ledger_allocation')->where('party_id', $this->customer->id)->update(['amount' => 4000]);

        $this->artisan('files:audit')->expectsOutputToContain('but was for 3000')->run();
    }

    // -------------------------------------------------- what the customer sees

    public function test_the_receipt_says_which_files_the_payment_was_for(): void
    {
        $a = $this->file(3000, '2026-08-01');

        $this->pay(3000, [$a->id => 3000]);

        $against = session('receipt')['against'];

        $this->assertCount(1, $against);
        $this->assertStringContainsString($a->registration_no, $against[0]['label']);
        $this->assertEquals(3000, $against[0]['amount']);
    }

    public function test_the_statement_shows_what_each_payment_was_adjusted_against(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $this->pay(3000, [$a->id => 3000]);

        $props = $this->actingAs($this->admin)
            ->getJson(route('party.statement', $this->customer->id))->assertOk()->json('props');

        $this->assertContains('against', array_column($props['columns'], 'key'));

        $row = collect($props['rows'])->first(fn ($row) => $row['against'] !== null);

        $this->assertStringContainsString($a->registration_no, $row['against']);
        $this->assertStringContainsString('3,000.00', $row['against']);
    }

    public function test_a_statement_with_nothing_adjusted_has_no_against_column(): void
    {
        $this->file(3000, '2026-08-01');
        $this->pay(3000);

        $props = $this->actingAs($this->admin)
            ->getJson(route('party.statement', $this->customer->id))->assertOk()->json('props');

        $this->assertNotContains('against', array_column($props['columns'], 'key'));
    }
}

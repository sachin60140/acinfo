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
 * Taking back an entry typed by mistake.
 *
 * Never by deleting or editing it: a reversal is a new row on the other side
 * for the same amount, dated today, saying which entry it takes back. Read for
 * the balance coming back to where it was, a reversed payment no longer
 * settling files, nothing reversed twice or out of a file's own rows, and the
 * office's reason never reaching the customer.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReverseEntryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Reverse Admin';
        $this->admin->email = 'reverse-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' reversed';
        $this->customer->mobile = '93100'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    private function file(float $charge, string $received): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RV-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01RV'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = $charge;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = $charge;
        $item->status = WorkFileModel::IN_OFFICE;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /** A payment typed on the Entry screen, adjusted against files if asked. */
    private function pay(float $amount, array $against = [], string $date = '2026-09-10'): PartyLedgerModel
    {
        $alloc = [];

        foreach ($against as $fileId => $share) {
            $alloc[$fileId] = ['work_file_id' => $fileId, 'amount' => $share];
        }

        $this->actingAs($this->admin)->post(route('party.entry', 'customer'), [
            'party_id' => $this->customer->id,
            'entry_type' => 'credit',
            'txn_date' => $date,
            'amount' => $amount,
            'payment_mode' => 'UPI',
            'ref_no' => 'UTR-'.uniqid(),
            'particular' => 'Payment received',
            'alloc' => $alloc,
        ])->assertSessionHas('success');

        return PartyLedgerModel::where('party_id', $this->customer->id)->whereNull('work_file_id')->latest('id')->first();
    }

    private function reverse(PartyLedgerModel $entry, string $reason = 'Typed for the wrong customer', bool $correct = false)
    {
        return $this->actingAs($this->admin)
            ->from(route('party.statement', $entry->party_id))
            ->post(route('party.reverse', $entry->id), ['reason' => $reason, 'correct' => $correct ? '1' : '0']);
    }

    private function statementRows(): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('party.statement', $this->customer->id))->assertOk()->json('props.rows');
    }

    // ------------------------------------------------------------ the reversal

    public function test_a_reversal_takes_the_entry_back_with_a_row_of_its_own(): void
    {
        $this->file(5000, '2026-09-01');
        $before = PartyLedgerModel::currentBalance($this->customer->id);
        $entry = $this->pay(5000);

        $this->reverse($entry)->assertRedirect(route('party.statement', $this->customer->id))->assertSessionHas('success');

        $reversal = PartyLedgerModel::where('reverses_id', $entry->id)->firstOrFail();

        $this->assertSame('debit', $reversal->entry_type, 'not on the other side');
        $this->assertEquals(5000, $reversal->amount);
        $this->assertSame(now()->toDateString(), date('Y-m-d', strtotime($reversal->txn_date)), 'not dated today');
        $this->assertSame(PartyLedgerModel::REVERSAL, $reversal->entry_kind);
        $this->assertSame('Typed for the wrong customer', $reversal->note);
        $this->assertSame($this->admin->id, (int) $reversal->created_by);
        $this->assertStringContainsString('#'.$entry->id, $reversal->particular);

        // The original is untouched, and the balance is as if it had never been typed.
        $this->assertEquals(5000, $entry->fresh()->amount);
        $this->assertEqualsWithDelta($before, PartyLedgerModel::currentBalance($this->customer->id), 0.001);
    }

    /** Post-dated: never reversed before it was made, or statements up to its date would be wrong. */
    public function test_a_post_dated_entry_is_reversed_on_its_own_date_not_before_it(): void
    {
        $ahead = now()->addDays(10)->toDateString();
        $entry = $this->pay(3000, [], $ahead);

        $this->reverse($entry)->assertSessionHas('success');

        $reversal = PartyLedgerModel::where('reverses_id', $entry->id)->firstOrFail();

        $this->assertSame($ahead, date('Y-m-d', strtotime($reversal->txn_date)), 'reversed before it was made');

        // And the message says the date it has, not "today".
        $this->assertStringContainsString('dated '.date('d-m-Y', strtotime($ahead)), session('success'));
    }

    /** A reversed payment settles nothing: the file it paid is owed again. */
    public function test_a_reversed_payment_no_longer_settles_its_files(): void
    {
        $file = $this->file(5000, '2026-09-01');
        $entry = $this->pay(5000, [$file->id => 5000]);

        $this->assertSame([], PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id] ?? []);

        $this->reverse($entry);

        $this->assertSame([$file->id => 5000.0], PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id]);
        $this->assertSame(0, DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->whereNull('released_at')->count(), 'its adjustment was not released');
        $this->assertSame($this->admin->id, (int) DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->value('released_by'));
    }

    /** An unadjusted payment too: reversed, the oldest files are owed again. */
    public function test_a_reversed_payment_on_account_stops_settling_the_oldest(): void
    {
        $file = $this->file(3000, '2026-09-01');
        $entry = $this->pay(3000);

        $this->reverse($entry);

        $this->assertSame([$file->id => 3000.0], PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id]);
    }

    // --------------------------------------------------------------- refused

    public function test_a_reason_is_required(): void
    {
        $entry = $this->pay(3000);

        $this->reverse($entry, '')->assertSessionHasErrors('reason');

        $this->assertFalse(PartyLedgerModel::where('reverses_id', $entry->id)->exists());
    }

    public function test_an_entry_is_reversed_once(): void
    {
        $entry = $this->pay(3000);

        $this->reverse($entry)->assertSessionHas('success');
        $this->reverse($entry)->assertSessionHasErrors('reason');

        $this->assertSame(1, PartyLedgerModel::where('reverses_id', $entry->id)->count());
    }

    public function test_a_reversal_is_not_itself_reversed(): void
    {
        $entry = $this->pay(3000);
        $this->reverse($entry);

        $reversal = PartyLedgerModel::where('reverses_id', $entry->id)->firstOrFail();

        $this->reverse($reversal)->assertSessionHasErrors('reason');
        $this->assertFalse(PartyLedgerModel::where('reverses_id', $reversal->id)->exists());
    }

    /** A file's own rows are rewritten when the file is saved; they are put right on the file. */
    public function test_a_files_own_entry_is_put_right_on_the_file_not_reversed(): void
    {
        $file = $this->file(3000, '2026-09-01');
        $charge = PartyLedgerModel::where('work_file_id', $file->id)->firstOrFail();

        $this->reverse($charge)->assertSessionHasErrors('reason');

        $this->assertStringContainsString($file->file_no, session('errors')->first('reason'));
        $this->assertFalse(PartyLedgerModel::where('reverses_id', $charge->id)->exists());
    }

    // --------------------------------------------------------------- correct

    /** Reverse and enter it again: the Entry screen, filled in as it was, files and all. */
    public function test_correct_opens_the_entry_screen_with_the_entry_filled_in(): void
    {
        $file = $this->file(5000, '2026-09-01');
        $entry = $this->pay(4000, [$file->id => 4000], '2026-09-12');

        $this->reverse($entry, 'Amount typed wrong', true)
            ->assertRedirect(route('party.entry', 'customer'))
            ->assertSessionHas('success');

        $props = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props');

        $this->assertSame((string) $this->customer->id, $props['initial']['party_id']);
        $this->assertSame('credit', $props['initial']['entry_type']);
        $this->assertSame('4000', $props['initial']['amount']);
        $this->assertSame('UPI', $props['initial']['payment_mode']);
        $this->assertSame('Payment received', $props['initial']['particular']);
        $this->assertSame(['4000'], array_values((array) $props['initialAlloc']));
        $this->assertStringContainsString('2026-09-12', $props['dateField']);
    }

    /** A customer deactivated since: the Entry screen still offers them, or it could not be typed again. */
    public function test_correct_for_a_deactivated_party_still_offers_that_party(): void
    {
        $entry = $this->pay(2000);

        $this->customer->is_active = 0;
        $this->customer->save();

        $this->reverse($entry, 'Wrong amount', true)->assertRedirect(route('party.entry', 'customer'));

        $props = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props');

        $this->assertContains($this->customer->id, array_map('intval', array_column($props['parties'], 'id')), 'the party was not offered');
    }

    // ------------------------------------------------------------ statements

    /** Ctrl+P on the statement: the office's notes and the Change buttons stay off the paper. */
    public function test_the_printed_statement_leaves_out_the_office_notes_and_change(): void
    {
        $html = $this->actingAs($this->admin)->get(route('party.statement', $this->customer->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/@media print\s*\{.*\.party-statement \.grid__cellnote,\s*\.party-statement \.grid__action\s*\{\s*display: none !important;/s',
            $html
        );
    }

    public function test_the_statement_offers_change_only_on_entries_that_can_be_taken_back(): void
    {
        $file = $this->file(3000, '2026-09-01');
        $kept = $this->pay(1000);
        $gone = $this->pay(2000);
        $this->reverse($gone, 'Duplicate');

        $rows = collect($this->statementRows())->keyBy('id');
        $reversal = PartyLedgerModel::where('reverses_id', $gone->id)->value('id');
        $charge = PartyLedgerModel::where('work_file_id', $file->id)->value('id');

        $this->assertSame('Change', $rows[$kept->id]['change']);
        $this->assertNull($rows[$gone->id]['change'], 'a reversed entry offered Change');
        $this->assertNull($rows[$reversal]['change'], 'a reversal offered Change');
        $this->assertNull($rows[$charge]['change'], 'a file\'s own row offered Change');

        $this->assertSame('is-reversed', $rows[$gone->id]['row_state']);
        $this->assertStringContainsString('Reversed by #'.$reversal, $rows[$gone->id]['office_note']);
        $this->assertSame('Why: Duplicate', $rows[$reversal]['office_note']);
    }

    /** The customer sees the reversal line; the office's reason stays with the office. */
    public function test_the_customer_sees_the_reversal_but_never_the_reason(): void
    {
        $entry = $this->pay(2000);
        $this->reverse($entry, 'Clerk typed it twice, sorry');

        // Signed in to the portal the way the portal signs a customer in.
        $said = $this->withSession(['customer_id' => $this->customer->id])
            ->getJson(route('customer.statement'))->assertOk()->getContent();

        $this->assertStringContainsString('Reversal of entry #'.$entry->id, $said);
        $this->assertStringNotContainsString('typed it twice', $said);
    }
}

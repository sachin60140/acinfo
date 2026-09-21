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
 * Adjusting a payment already saved against files, or changing what it is for.
 *
 * A payment typed before payments could be adjusted settled the oldest files,
 * as every payment did; the dealer meant it for one of them. Or a payment was
 * adjusted against the wrong file. Put right here without moving any money:
 * the entry stays as it is, what it was adjusted against is released and kept
 * on record, and what it is for now is written anew.
 *
 * Read for: the file named is the one settled; the payment is checked as it
 * was when new — as if it had never been typed — and no other payment's
 * adjustment is taken; nothing is written when nothing changes or anything is
 * refused; and only a standing payment typed by hand is offered it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReadjustPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Readjust Admin';
        $this->admin->email = 'readjust-'.uniqid().'@example.com';
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
        $party->name = ucfirst($type).' '.uniqid().' readjust';
        $party->mobile = '93300'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A file of one work, charged to a customer (and given to a vendor, if one is named). */
    private function file(float $charge, string $received, ?PartyModel $vendor = null, float $cost = 0): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RA-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01RA'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
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

    /** A payment typed on the Entry screen, adjusted against files if asked. */
    private function pay(float $amount, array $against = [], ?PartyModel $party = null, string $date = '2026-09-10'): PartyLedgerModel
    {
        $party ??= $this->customer;

        $this->actingAs($this->admin)->post(route('party.entry', $party->party_type), [
            'party_id' => $party->id,
            'entry_type' => $party->party_type === 'customer' ? 'credit' : 'debit',
            'txn_date' => $date,
            'amount' => $amount,
            'payment_mode' => 'UPI',
            'particular' => 'Payment',
            'alloc' => $this->lines($against),
        ])->assertSessionHas('success');

        return PartyLedgerModel::where('party_id', $party->id)->whereNull('work_file_id')->latest('id')->first();
    }

    private function lines(array $against): array
    {
        $alloc = [];

        foreach ($against as $fileId => $share) {
            $alloc[$fileId] = ['work_file_id' => $fileId, 'amount' => $share];
        }

        return $alloc;
    }

    /** What the Adjust screen was drawn from, which it posts back. */
    private function drawn(PartyLedgerModel $entry): ?string
    {
        $page = $this->actingAs($this->admin)->getJson(route('party.adjust', $entry->id));

        // An entry that cannot be adjusted is sent back to its statement instead.
        return $page->isOk() ? $page->json('props.drawn') : null;
    }

    /** The Adjust screen's post, from a page drawn just now unless one drawn earlier is given. */
    private function adjust(PartyLedgerModel $entry, array $against, ?string $drawn = null)
    {
        $drawn ??= $this->drawn($entry);

        return $this->actingAs($this->admin)
            ->from(route('party.adjust', $entry->id))
            ->post(route('party.adjust', $entry->id), ['alloc' => $this->lines($against), 'drawn' => $drawn]);
    }

    private function owed(?PartyModel $party = null, string $side = 'debit'): array
    {
        $party ??= $this->customer;

        return PartyLedgerModel::outstandingByFile([$party->id], $side)[$party->id] ?? [];
    }

    /** What the payment is adjusted against now: file id => amount. */
    private function live(PartyLedgerModel $entry): array
    {
        return DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->whereNull('released_at')
            ->pluck('amount', 'work_file_id')->map(fn ($amount) => (float) $amount)->all();
    }

    // ------------------------------------------------------------ the change

    /** The whole point: an old payment that settled the oldest file, put on the one it was for. */
    public function test_a_payment_on_account_is_put_on_the_file_it_was_for(): void
    {
        $older = $this->file(3000, '2026-08-01');
        $newer = $this->file(5000, '2026-09-01');
        $entry = $this->pay(3000);

        $this->assertSame([$newer->id => 5000.0], $this->owed(), 'the oldest was not the one settled first');

        $this->adjust($entry, [$newer->id => 3000])
            ->assertRedirect(route('party.statement', $this->customer->id))
            ->assertSessionHas('success');

        $this->assertSame([$older->id => 3000.0, $newer->id => 2000.0], $this->owed());
        $this->assertSame([$newer->id => 3000.0], $this->live($entry));
        $this->assertStringContainsString($newer->registration_no, session('success'));

        // No money moved: the entry and the balance are as they were.
        $this->assertEquals(3000, $entry->fresh()->amount);
        $this->assertEqualsWithDelta(5000, PartyLedgerModel::currentBalance($this->customer->id), 0.001);
    }

    /** Moved from one file to another: the old line is let go, and kept, with who and when. */
    public function test_what_it_was_for_before_is_released_and_kept_on_record(): void
    {
        $first = $this->file(3000, '2026-08-01');
        $second = $this->file(3000, '2026-09-01');
        $entry = $this->pay(3000, [$first->id => 3000]);

        $this->adjust($entry, [$second->id => 3000])->assertSessionHas('success');

        $old = DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->where('work_file_id', $first->id)->first();

        $this->assertNotNull($old, 'the old line was deleted');
        $this->assertNotNull($old->released_at);
        $this->assertSame($this->admin->id, (int) $old->released_by);
        $this->assertSame([$second->id => 3000.0], $this->live($entry));
        $this->assertSame($this->admin->id, (int) DB::table('party_ledger_allocation')
            ->where('entry_id', $entry->id)->whereNull('released_at')->value('created_by'));
        $this->assertSame([$first->id => 3000.0], $this->owed());
    }

    /** Emptied: back on account, settling the oldest first. */
    public function test_emptied_it_goes_back_to_settling_the_oldest(): void
    {
        $older = $this->file(3000, '2026-08-01');
        $newer = $this->file(3000, '2026-09-01');
        $entry = $this->pay(3000, [$newer->id => 3000]);

        $this->adjust($entry, [])->assertSessionHas('success');

        $this->assertSame([], $this->live($entry));
        $this->assertSame([$newer->id => 3000.0], $this->owed());
        $this->assertStringContainsString('now on account', session('success'));
    }

    /** Checked as it was when new: what it already takes from a file is its own to keep. */
    public function test_it_is_checked_as_if_it_had_never_been_typed(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $other = $this->file(2000, '2026-09-01');
        $entry = $this->pay(3000, [$file->id => 3000]);

        // Nothing is open on the file with it; everything is without it.
        $this->assertEquals(0, PartyLedgerModel::bills($this->customer->id)['files'][$file->id]['open']);
        $this->assertEquals(3000, PartyLedgerModel::bills($this->customer->id, 'debit', $entry->id)['files'][$file->id]['open']);

        // So part of it can stay where it is and the rest move.
        $this->adjust($entry, [$file->id => 1000, $other->id => 2000])->assertSessionHas('success');

        $this->assertSame([$file->id => 1000.0, $other->id => 2000.0], $this->live($entry));
    }

    /** The files as they stood when it came in: money received after it does not cover them first. */
    public function test_only_money_received_before_it_counts_as_covering_the_files(): void
    {
        $january = $this->file(1000, '2026-08-01');
        $entry = $this->pay(1000, [], null, '2026-08-15');
        $march = $this->file(1000, '2026-09-01');
        $this->pay(1000, [], null, '2026-09-05');

        $bills = PartyLedgerModel::bills($this->customer->id, 'debit', $entry->id)['files'];

        // Both were owed when it came in, the older first — so Fill oldest first puts it on the older.
        $this->assertEquals(1000, $bills[$january->id]['due'], 'a later payment was counted as covering the older file');
        $this->assertEquals(1000, $bills[$march->id]['due']);

        // What may be taken is unchanged, and nothing about the ledger is.
        $this->assertEquals(1000, $bills[$january->id]['open']);
        $this->assertSame([], $this->owed());
    }

    /** An older payment keeps its line on a file a newer one also claims, after the file was re-priced. */
    public function test_a_line_it_has_already_may_stay_as_it_is(): void
    {
        $file = $this->file(16000, '2026-08-01');
        $other = $this->file(5000, '2026-08-02');
        $older = $this->pay(13000, [$file->id => 8000]);
        $newer = $this->pay(8000, [$file->id => 8000]);

        $file->customer_amount = 10000;
        $file->save();
        $item = $file->items()->first();
        $item->customer_amount = 10000;
        $item->save();
        $file->syncLedger();

        // Open without it is 2,000; it has 8,000 there, and may keep it while it adds the other file.
        $this->adjust($older, [$file->id => 8000, $other->id => 5000])->assertSessionHas('success');

        $this->assertSame([$file->id => 8000.0, $other->id => 5000.0], $this->live($older));
        $this->assertSame([$file->id => 8000.0], $this->live($newer), 'the newer payment\'s line was touched');

        // But not raised above what it had, where nothing more is open.
        $this->assertRefusedAndUnchanged($this->adjust($older, [$file->id => 9000, $other->id => 4000]), $older, [$file->id => 8000.0, $other->id => 5000.0], 1);
    }

    /** A file cancelled since: its line is kept for when it is charged again, and the page draws it. */
    public function test_a_line_on_a_file_cancelled_since_is_kept(): void
    {
        $cancelled = $this->file(5000, '2026-08-01');
        $open = $this->file(3000, '2026-08-02');
        $entry = $this->pay(8000, [$cancelled->id => 5000]);

        $cancelled->status = WorkFileModel::CANCELLED;
        $cancelled->save();
        $cancelled->items()->update(['status' => WorkFileModel::CANCELLED]);
        $cancelled->syncLedger();

        $lines = collect($this->actingAs($this->admin)->getJson(route('party.adjust', $entry->id))->json('props.currentLines'))->keyBy('id');

        $this->assertStringContainsString('Not charged', $lines[$cancelled->id]['why']);

        $this->adjust($entry, [$cancelled->id => 5000, $open->id => 3000])->assertSessionHas('success');

        $this->assertSame([$cancelled->id => 5000.0, $open->id => 3000.0], $this->live($entry));

        // Charged again, it is settled by the line that was kept.
        $cancelled->status = WorkFileModel::IN_OFFICE;
        $cancelled->save();
        $cancelled->items()->update(['status' => WorkFileModel::IN_OFFICE]);
        $cancelled->syncLedger();

        $this->assertSame([], $this->owed());
    }

    // --------------------------------------------------------- a page left open

    /** A page left open does not let go of what a colleague has put the payment against since. */
    public function test_a_page_left_open_is_refused_rather_than_overwrite_a_colleagues_change(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(3000, '2026-08-02');
        $entry = $this->pay(3000);

        $drawn = $this->drawn($entry);

        // A colleague puts it on A.
        $this->adjust($entry, [$a->id => 3000])->assertSessionHas('success');

        // The page drawn before that, saved with B.
        $this->adjust($entry, [$b->id => 3000], $drawn)
            ->assertRedirect(route('party.adjust', $entry->id))
            ->assertSessionHasErrors('alloc');

        $this->assertStringContainsString('Someone changed', session('errors')->first('alloc'));
        $this->assertSame([$a->id => 3000.0], $this->live($entry));
    }

    /** Pressed twice: the second finds it already so, and says what it is. */
    public function test_the_same_save_twice_is_nothing_changed_not_refused(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000);
        $drawn = $this->drawn($entry);

        $this->adjust($entry, [$a->id => 3000], $drawn)->assertSessionHas('success');
        $this->adjust($entry, [$a->id => 3000], $drawn)->assertSessionHasNoErrors();

        $this->assertStringContainsString('Nothing changed', session('success'));
        $this->assertSame(1, DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->count());
    }

    // ------------------------------------------------------------- refused

    private function assertRefusedAndUnchanged($response, PartyLedgerModel $entry, array $before, int $released = 0): void
    {
        $response->assertRedirect(route('party.adjust', $entry->id))->assertSessionHasErrors('alloc');

        $this->assertSame($before, $this->live($entry), 'what it was adjusted against changed');
        $this->assertSame($released, DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->whereNotNull('released_at')->count(), 'a line was released');
    }

    /** Another payment's adjustment is that payment's: it is not taken from under it. */
    public function test_another_payments_adjustment_is_not_taken(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $this->pay(3000, [$file->id => 3000]);
        $old = $this->pay(1000);

        $this->assertRefusedAndUnchanged($this->adjust($old, [$file->id => 1000]), $old, []);
        $this->assertStringContainsString('only 0.00 left', session('errors')->first('alloc'));
    }

    public function test_more_than_the_payment_is_refused(): void
    {
        $a = $this->file(3000, '2026-08-01');
        $b = $this->file(3000, '2026-09-01');
        $entry = $this->pay(4000, [$a->id => 3000]);

        $this->assertRefusedAndUnchanged($this->adjust($entry, [$a->id => 3000, $b->id => 2000]), $entry, [$a->id => 3000.0]);
    }

    public function test_another_customers_file_is_refused(): void
    {
        $theirs = $this->party('customer');
        $file = $this->file(3000, '2026-08-01');
        DB::table('work_file')->where('id', $file->id)->update(['customer_id' => $theirs->id]);
        WorkFileModel::find($file->id)->syncLedger();

        $entry = $this->pay(3000);

        $this->assertRefusedAndUnchanged($this->adjust($entry, [$file->id => 3000]), $entry, []);
    }

    /** The same files for the same amounts: nothing is let go and written again. */
    public function test_nothing_changed_writes_nothing(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000, [$file->id => 3000]);

        $this->adjust($entry, [$file->id => '3000.00'])
            ->assertRedirect(route('party.statement', $this->customer->id))
            ->assertSessionHas('success');

        $this->assertStringContainsString('Nothing changed', session('success'));
        $this->assertSame(1, DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->count());
    }

    /** A refused save comes back with what was typed, not with what is saved. */
    public function test_a_refused_save_puts_its_amounts_back(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000, [$file->id => 1000]);

        $this->adjust($entry, [$file->id => 5000])->assertSessionHasErrors('alloc');

        $props = $this->actingAs($this->admin)->getJson(route('party.adjust', $entry->id))->assertOk()->json('props');

        $this->assertSame(['5000'], array_values((array) $props['initialAlloc']));
        $this->assertSame(['1000.00'], array_values((array) $props['current']));
    }

    /** Only a standing payment typed by hand: never a charge, a file's row, a reversal, or one reversed. */
    public function test_only_a_standing_payment_typed_by_hand_is_adjusted(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $fileRow = PartyLedgerModel::where('work_file_id', $file->id)->firstOrFail();

        $charge = new PartyLedgerModel;
        $charge->party_id = $this->customer->id;
        $charge->txn_date = '2026-09-10';
        $charge->entry_type = 'debit';
        $charge->amount = 500;
        $charge->particular = 'Charge typed by hand';
        $charge->save();

        $reversed = $this->pay(1000);
        $this->actingAs($this->admin)->post(route('party.reverse', $reversed->id), ['reason' => 'Duplicate'])->assertSessionHas('success');
        $reversal = PartyLedgerModel::where('reverses_id', $reversed->id)->firstOrFail();

        foreach (['a file\'s own row' => $fileRow, 'a charge' => $charge, 'a reversed payment' => $reversed, 'a reversal' => $reversal] as $what => $entry) {
            $this->actingAs($this->admin)->get(route('party.adjust', $entry->id))
                ->assertRedirect(route('party.statement', $this->customer->id));

            $this->adjust($entry, [$file->id => 100])->assertSessionHasErrors('alloc');

            $this->assertSame(0, DB::table('party_ledger_allocation')->where('entry_id', $entry->id)->count(), "$what was adjusted");
        }
    }

    // ---------------------------------------------------------------- vendors

    /** The office's payment to a vendor, put on the vendor's bill it was for. */
    public function test_a_payment_to_a_vendor_is_put_on_their_file(): void
    {
        $vendor = $this->party('vendor');
        $older = $this->file(3000, '2026-08-01', $vendor, 1200);
        $newer = $this->file(3000, '2026-09-01', $vendor, 800);
        $entry = $this->pay(800, [], $vendor);

        $this->assertSame([$older->id => 400.0, $newer->id => 800.0], $this->owed($vendor, 'credit'));

        $this->adjust($entry, [$newer->id => 800])->assertSessionHas('success');

        $this->assertSame([$older->id => 1200.0], $this->owed($vendor, 'credit'));
    }

    // -------------------------------------------------------------- screens

    /** The list for the Adjust screen: the files as if this payment had never been typed. */
    public function test_the_list_leaves_the_payment_out_only_for_its_own_party(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000, [$file->id => 3000]);

        $open = fn ($query) => collect($this->actingAs($this->admin)
            ->getJson(route('party.bills', $this->customer->id).$query)->assertOk()->json('bills'))
            ->keyBy('id')->map(fn ($bill) => $bill['open'])->all();

        $this->assertSame([], $open('?all=1'));
        $this->assertEquals([$file->id => 3000], $open('?all=1&except='.$entry->id));

        // Another party's entry is no business of this list — not even its date,
        // which would otherwise stop this customer's money counting.
        $second = $this->file(2000, '2026-08-02');
        $this->pay(2000);
        $elsewhere = $this->pay(100, [], $this->party('customer'), '2026-07-01');

        $due = fn ($query) => collect($this->actingAs($this->admin)
            ->getJson(route('party.bills', $this->customer->id).$query)->assertOk()->json('bills'))
            ->keyBy('id')->map(fn ($bill) => $bill['due'])->all();

        $this->assertEquals([$second->id => 0], $due('?all=1&except='.$elsewhere->id));
    }

    /** Offered from the statement's Change dialog on a payment, and nowhere else. */
    public function test_the_statement_offers_it_only_on_standing_payments(): void
    {
        $this->file(3000, '2026-08-01');
        $payment = $this->pay(1000);
        $gone = $this->pay(500);
        $this->actingAs($this->admin)->post(route('party.reverse', $gone->id), ['reason' => 'Duplicate']);

        $charge = new PartyLedgerModel;
        $charge->party_id = $this->customer->id;
        $charge->txn_date = '2026-09-10';
        $charge->entry_type = 'debit';
        $charge->amount = 500;
        $charge->particular = 'Charge typed by hand';
        $charge->save();

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))
            ->assertOk()->json('props.rows'))->keyBy('id');

        $this->assertSame(route('party.adjust', $payment->id), $rows[$payment->id]['adjust_url']);
        $this->assertNull($rows[$charge->id]['adjust_url'], 'a charge was offered');
        $this->assertNull($rows[$gone->id]['adjust_url'], 'a reversed payment was offered');
    }

    public function test_the_adjust_screen_starts_with_what_it_is_for_now(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000, [$file->id => 2000]);

        $props = $this->actingAs($this->admin)->getJson(route('party.adjust', $entry->id))->assertOk()->json('props');

        $this->assertSame([(string) $file->id => '2000.00'], (array) $props['initialAlloc']);
        $this->assertSame($entry->id, $props['entry']['id']);
        $this->assertEquals(3000, $props['entry']['amount']);
        $this->assertSame($this->customer->name, $props['party']['name']);
    }

    /** For the office: when the files were changed, by whom, and what it was for before. */
    public function test_the_statement_says_when_a_payments_files_were_changed(): void
    {
        $first = $this->file(3000, '2026-08-01');
        $second = $this->file(3000, '2026-08-02');
        $moved = $this->pay(3000, [$first->id => 3000]);
        $untouched = $this->pay(1000, [$second->id => 1000]);

        $this->adjust($moved, [$second->id => 2000])->assertSessionHas('success');

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))
            ->assertOk()->json('props.rows'))->keyBy('id');

        $note = $rows[$moved->id]['office_note'];

        $this->assertStringContainsString('Files changed '.now()->format('d-m-Y').' by Readjust Admin', $note);
        $this->assertStringContainsString('was '.$first->file_no, $note);
        $this->assertNull($rows[$untouched->id]['office_note'], 'a payment adjusted when typed, and never since, has a note');

        // The customer's statement never carries it.
        $said = $this->withSession(['customer_id' => $this->customer->id])
            ->getJson(route('customer.statement'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Files changed', $said);
    }

    /** An old payment put on a file for the first time: it was on account. */
    public function test_a_first_adjustment_of_an_old_payment_says_it_was_on_account(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000);
        DB::table('party_ledger')->where('id', $entry->id)->update(['created_at' => now()->subDays(30)]);

        $this->adjust($entry, [$file->id => 3000])->assertSessionHas('success');

        $props = $this->actingAs($this->admin)->getJson(route('party.adjust', $entry->id))->json('props');

        $this->assertStringContainsString('was on account', $props['history']);
    }

    /** From a statement filtered to a period, and back to it. */
    public function test_the_statements_period_is_kept_there_and_back(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000);
        $period = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('party.statement', ['id' => $this->customer->id] + $period))
            ->json('props.rows'))->keyBy('id');

        $this->assertSame(route('party.adjust', ['id' => $entry->id] + $period), $rows[$entry->id]['adjust_url']);

        $props = $this->actingAs($this->admin)->getJson($rows[$entry->id]['adjust_url'])->json('props');

        $this->assertSame(route('party.statement', ['id' => $this->customer->id] + $period), $props['statementUrl']);

        $this->actingAs($this->admin)
            ->post($props['action'], ['alloc' => $this->lines([$file->id => 3000]), 'drawn' => $props['drawn']])
            ->assertRedirect(route('party.statement', ['id' => $this->customer->id] + $period));

        // A malformed date is left behind, not refused.
        $this->actingAs($this->admin)->getJson(route('party.adjust', ['id' => $entry->id, 'from' => '2026-13-45']))
            ->assertOk()->assertJsonPath('props.statementUrl', route('party.statement', $this->customer->id));
    }

    public function test_nobody_signed_out_can_adjust(): void
    {
        $file = $this->file(3000, '2026-08-01');
        $entry = $this->pay(3000);

        auth()->logout();

        $this->post(route('party.adjust', $entry->id), ['alloc' => $this->lines([$file->id => 3000])])->assertRedirect();
        $this->assertSame([], $this->live($entry));
    }
}

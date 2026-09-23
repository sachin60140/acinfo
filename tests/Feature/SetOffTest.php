<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One person who is both a customer and a vendor, and clearing what they owe
 * against what they are owed.
 *
 * A dealer owes the office 5,000 for files and is owed 3,000 for work done.
 * Set off, the 3,000 goes from both: a credit on the customer, as a receipt
 * would be, and a debit on the vendor, as a payment would be — the same
 * amount on the same day, each naming the other, and no money moving.
 *
 * The owner's decisions (2026-09-23): the two accounts are linked by hand,
 * never by mobile number; the customer reads "Adjusted against payment due to
 * you" and the vendor account "Adjusted against amount due from you"; what is
 * not named against bills settles the oldest, as a receipt does; a remark is
 * optional. And from the map of the code: the two halves are made, reversed
 * and entered again only together.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class SetOffTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    /** The dealer, as a customer. */
    private PartyModel $customer;

    /** The same dealer, as a vendor. */
    private PartyModel $vendor;

    /** Somebody else, whose files the dealer does work on. */
    private PartyModel $other;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Set Off Admin';
        $this->admin->email = 'setoff-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Dealer');
        $this->vendor = $this->party('vendor', 'Dealer Works');
        $this->other = $this->party('customer', 'Other');

        $this->link($this->customer, $this->vendor);

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid().' set-off';
        $party->mobile = '93700'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function link(PartyModel $customer, ?PartyModel $vendor): void
    {
        $customer->linked_vendor_id = $vendor?->id;
        $customer->save();
    }

    /** A file charged to a customer, and given to a vendor at a rate when one is named. */
    private function file(PartyModel $customer, float $charge, ?PartyModel $vendor = null, float $rate = 0, string $received = '2026-08-01'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-SO-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01SO'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = $charge;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = $charge;
        $item->status = $vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE;

        if ($vendor) {
            $item->vendor_id = $vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = $received;
        }

        $item->save();

        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** What the dealer owes as a customer: a file of theirs. */
    private function owing(float $charge, string $received = '2026-08-01'): WorkFileModel
    {
        return $this->file($this->customer, $charge, null, 0, $received);
    }

    /** What the office owes the dealer as a vendor: somebody else's file they worked on. */
    private function owed(float $rate, string $received = '2026-08-01'): WorkFileModel
    {
        return $this->file($this->other, $rate + 1000, $this->vendor, $rate, $received);
    }

    private function lines(array $against): array
    {
        $alloc = [];

        foreach ($against as $fileId => $share) {
            $alloc[$fileId] = ['work_file_id' => $fileId, 'amount' => $share];
        }

        return $alloc;
    }

    /** A set-off, as the Entry screen posts one with the box ticked. */
    private function setOff(float $amount, array $customerFiles = [], array $vendorFiles = [], array $extra = [], string $from = 'customer')
    {
        [$own, $other, $side] = $from === 'customer'
            ? [$this->customer, $this->vendor, 'credit']
            : [$this->vendor, $this->customer, 'debit'];

        [$alloc, $counter] = $from === 'customer' ? [$customerFiles, $vendorFiles] : [$vendorFiles, $customerFiles];

        return $this->actingAs($this->admin)
            ->from(route('party.entry', $from))
            ->post(route('party.entry', $from), $extra + [
                'party_id' => $own->id,
                'entry_type' => $side,
                'txn_date' => '2026-09-20',
                'amount' => $amount,
                'entry_kind' => 'setoff',
                'counterpart_id' => $other->id,
                'alloc' => $this->lines($alloc),
                'counter_alloc' => $this->lines($counter),
            ]);
    }

    private function balance(PartyModel $party): float
    {
        return round(PartyLedgerModel::currentBalance($party->id), 2);
    }

    /** @return array{0: ?PartyLedgerModel, 1: ?PartyLedgerModel} the customer's half and the vendor's */
    private function halves(): array
    {
        $find = fn (PartyModel $party) => PartyLedgerModel::where('party_id', $party->id)
            ->where('entry_kind', PartyLedgerModel::SETOFF)->latest('id')->first();

        return [$find($this->customer), $find($this->vendor)];
    }

    private function nothingWritten(): void
    {
        $this->assertSame(0, PartyLedgerModel::whereIn('party_id', [$this->customer->id, $this->vendor->id])
            ->whereNotNull('entry_kind')->count(), 'something was written');
    }

    private function reverse(PartyLedgerModel $entry, bool $correct = false, string $reason = 'Agreed the wrong figure')
    {
        return $this->actingAs($this->admin)
            ->from(route('party.statement', $entry->party_id))
            ->post(route('party.reverse', $entry->id), ['reason' => $reason, 'correct' => $correct ? '1' : '0']);
    }

    private function audit(): string
    {
        Artisan::call('files:audit');

        return Artisan::output();
    }

    // ------------------------------------------------------------- the point

    /** 5,000 owed to us and 3,000 owed to them: 3,000 goes from both, and nothing is paid. */
    public function test_a_set_off_clears_the_customer_and_the_vendor_by_the_same_amount(): void
    {
        $this->owing(5000);
        $this->owed(3000);

        $this->assertSame(5000.0, $this->balance($this->customer), 'the premise');
        $this->assertSame(-3000.0, $this->balance($this->vendor), 'the premise');

        $this->setOff(3000)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(2000.0, $this->balance($this->customer));
        $this->assertSame(0.0, $this->balance($this->vendor));

        [$mine, $theirs] = $this->halves();

        $this->assertSame('credit', $mine->entry_type);
        $this->assertSame('debit', $theirs->entry_type);
        $this->assertEquals(3000, $mine->amount);
        $this->assertEquals(3000, $theirs->amount);
        $this->assertSame('2026-09-20', date('Y-m-d', strtotime($mine->txn_date)));
        $this->assertSame('2026-09-20', date('Y-m-d', strtotime($theirs->txn_date)));

        // Each names the other.
        $this->assertSame((int) $theirs->id, (int) $mine->setoff_with_id);
        $this->assertSame((int) $mine->id, (int) $theirs->setoff_with_id);

        // The owner's words, and no money.
        $this->assertSame('Adjusted against payment due to you', $mine->particular);
        $this->assertSame('Adjusted against amount due from you', $theirs->particular);
        $this->assertSame('Set-off', $mine->payment_mode);
        $this->assertNotContains('Set-off', PartyLedgerModel::MONEY_MODES);
        $this->assertNotContains('Set-off', PartyLedgerModel::PAYMENT_MODES, 'it is never typed');
        $this->assertNull($mine->ref_no);
        $this->assertNull($mine->note, 'a remark is optional');
        $this->assertSame($this->admin->id, (int) $mine->created_by);
    }

    /** Typed on the vendor's account, it is the same two halves. */
    public function test_it_can_be_made_from_the_vendors_screen_too(): void
    {
        $this->owing(5000);
        $this->owed(3000);

        $this->setOff(1200, [], [], [], 'vendor')->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(3800.0, $this->balance($this->customer));
        $this->assertSame(-1800.0, $this->balance($this->vendor));

        [$mine, $theirs] = $this->halves();
        $this->assertSame('Adjusted against payment due to you', $mine->particular);
        $this->assertSame((int) $mine->id, (int) $theirs->setoff_with_id);

        // The customer is told, whichever screen it was typed on.
        $this->assertSame($this->customer->name, session('receipt')['name']);
    }

    /** A remark is the office's own, on both halves. */
    public function test_a_remark_is_kept_for_the_office_on_both_halves(): void
    {
        $this->owing(5000);
        $this->owed(3000);

        $this->setOff(1000, [], [], ['reason' => 'Agreed with him on the phone']);

        [$mine, $theirs] = $this->halves();
        $this->assertSame('Agreed with him on the phone', $mine->note);
        $this->assertSame('Agreed with him on the phone', $theirs->note);
    }

    // ------------------------------------------------------------ the bills

    /** Named on neither side, it settles the oldest on each, as a receipt and a payment would. */
    public function test_what_is_not_named_settles_the_oldest_bills_on_each_side(): void
    {
        $old = $this->owing(2000, '2026-07-01');
        $new = $this->owing(3000, '2026-08-01');
        $oldWork = $this->owed(1500, '2026-07-05');
        $newWork = $this->owed(2500, '2026-08-05');

        $this->setOff(1500)->assertSessionHasNoErrors();

        $owedByFile = PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id] ?? [];
        $this->assertSame([$old->id => 500.0, $new->id => 3000.0], $owedByFile);

        $owingByFile = PartyLedgerModel::outstandingByFile([$this->vendor->id], 'credit')[$this->vendor->id] ?? [];
        $this->assertSame([$newWork->id => 2500.0], $owingByFile, 'the oldest work is not the one cleared');
        $this->assertArrayNotHasKey($oldWork->id, $owingByFile);
    }

    /** Named, those are the bills it clears — each side's under its own account. */
    public function test_named_bills_are_cleared_on_each_side(): void
    {
        $old = $this->owing(2000, '2026-07-01');
        $new = $this->owing(3000, '2026-08-01');
        $oldWork = $this->owed(1500, '2026-07-05');
        $newWork = $this->owed(2500, '2026-08-05');

        $this->setOff(2500, [$new->id => 2500], [$newWork->id => 2500])->assertSessionHasNoErrors();

        $owedByFile = PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id] ?? [];
        $this->assertSame([$old->id => 2000.0, $new->id => 500.0], $owedByFile);

        $owingByFile = PartyLedgerModel::outstandingByFile([$this->vendor->id], 'credit')[$this->vendor->id] ?? [];
        $this->assertSame([$oldWork->id => 1500.0], $owingByFile);

        [$mine, $theirs] = $this->halves();

        $lines = DB::table('party_ledger_allocation')->whereIn('entry_id', [$mine->id, $theirs->id])->get()->groupBy('entry_id');
        $this->assertSame([[$this->customer->id, $new->id]], $lines[$mine->id]->map(fn ($l) => [(int) $l->party_id, (int) $l->work_file_id])->all());
        $this->assertSame([[$this->vendor->id, $newWork->id]], $lines[$theirs->id]->map(fn ($l) => [(int) $l->party_id, (int) $l->work_file_id])->all());
    }

    /**
     * One file can be the dealer's as a customer and theirs as a vendor —
     * their own vehicle, their own work. Its two lines stay apart, each
     * under its own account. Found in the map: one picker's field name for
     * both would have posted the two as one.
     */
    public function test_a_file_on_both_sides_is_cleared_on_each_under_its_own_account(): void
    {
        $both = $this->file($this->customer, 4000, $this->vendor, 1500);

        $this->setOff(1500, [$both->id => 1500], [$both->id => 1500])->assertSessionHasNoErrors();

        [$mine, $theirs] = $this->halves();

        $this->assertSame(1, DB::table('party_ledger_allocation')->where('entry_id', $mine->id)->where('party_id', $this->customer->id)->where('work_file_id', $both->id)->count());
        $this->assertSame(1, DB::table('party_ledger_allocation')->where('entry_id', $theirs->id)->where('party_id', $this->vendor->id)->where('work_file_id', $both->id)->count());
        $this->assertSame(2500.0, $this->balance($this->customer));
        $this->assertSame(0.0, $this->balance($this->vendor));
    }

    /** Another account's file, or more than is open on one, is refused — on the side it is on. */
    public function test_each_sides_bills_are_checked_against_that_side(): void
    {
        $mine = $this->owing(5000);
        $this->owed(3000);
        $strangers = $this->file($this->other, 9000);

        $this->setOff(1000, [], [$strangers->id => 1000])->assertSessionHasErrors('counter_alloc');
        $this->setOff(1000, [$strangers->id => 1000])->assertSessionHasErrors('alloc');
        $this->setOff(1000, [$mine->id => 800], [$mine->id => 100])->assertSessionHasErrors('counter_alloc');

        // Nor more against the other account's files than the set-off itself.
        $work = PartyLedgerModel::where('party_id', $this->vendor->id)->whereNotNull('work_file_id')->value('work_file_id');
        $this->setOff(1000, [], [$work => 1500])->assertSessionHasErrors('counter_alloc');

        $this->nothingWritten();
    }

    // -------------------------------------------------------------- refusals

    /** Only between two accounts the office linked by hand. */
    public function test_it_is_only_between_accounts_the_office_linked(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->link($this->customer, null);

        $this->setOff(1000)->assertSessionHasErrors('entry_kind');
        $this->assertStringContainsString('is not linked to a vendor account', session('errors')->first('entry_kind'));

        $this->nothingWritten();
    }

    /**
     * Not by a matching mobile number either. Found in the map: two people
     * share a phone, and a typo would set one person off against a stranger.
     */
    public function test_a_matching_mobile_number_is_not_a_link(): void
    {
        $this->link($this->customer, null);
        $this->vendor->mobile = $this->customer->mobile;
        $this->vendor->save();

        $this->owing(5000);
        $this->owed(3000);

        $this->setOff(1000)->assertSessionHasErrors('entry_kind');

        $this->nothingWritten();
    }

    /** A page drawn for one vendor account saves against no other. */
    public function test_a_link_changed_since_the_page_was_drawn_is_refused(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $stranger = $this->party('vendor', 'Stranger');

        $this->setOff(1000, [], [], ['counterpart_id' => $stranger->id])->assertSessionHasErrors('entry_kind');

        $this->nothingWritten();
    }

    /** Never more than the customer owes, or the office owes the vendor — read from the ledger. */
    public function test_it_is_never_more_than_either_side_owes(): void
    {
        $this->owing(2000);
        $this->owed(3000);

        $this->setOff(2500)->assertSessionHasErrors('amount');
        $this->nothingWritten();

        $this->owing(5000);
        $this->setOff(3500)->assertSessionHasErrors('amount');
        $this->nothingWritten();

        // Exactly the smaller of the two is fine.
        $this->setOff(3000)->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance($this->vendor));
    }

    public function test_nothing_is_set_off_against_an_inactive_account(): void
    {
        $this->owing(5000);
        $this->owed(3000);

        $this->vendor->is_active = 0;
        $this->vendor->save();

        $this->setOff(1000)->assertSessionHasErrors('entry_kind');

        $this->nothingWritten();
    }

    /** It lowers what each owes, so it is the payment side on either screen. */
    public function test_it_is_on_the_payment_side_only(): void
    {
        $this->owing(5000);
        $this->owed(3000);

        $this->setOff(1000, [], [], ['entry_type' => 'debit'])->assertSessionHasErrors('entry_kind');
        $this->setOff(1000, [], [], ['entry_type' => 'credit'], 'vendor')->assertSessionHasErrors('entry_kind');

        $this->nothingWritten();
    }

    /** An ordinary entry cannot read as a set-off to the customer, with no other half behind it. */
    public function test_an_ordinary_entry_cannot_be_worded_as_a_set_off(): void
    {
        $this->owing(5000);

        $this->actingAs($this->admin)
            ->from(route('party.entry', 'customer'))
            ->post(route('party.entry', 'customer'), [
                'party_id' => $this->customer->id,
                'entry_type' => 'credit',
                'txn_date' => '2026-09-20',
                'amount' => 500,
                'payment_mode' => 'Adjustment',
                'particular' => ' adjusted against PAYMENT due to you ',
            ])
            ->assertSessionHasErrors('particular');

        $this->assertSame(5000.0, $this->balance($this->customer));
    }

    // ------------------------------------------------------ what people read

    /** The customer is offered a message that says what it is, and names nothing of the vendor's. */
    public function test_the_customer_is_offered_a_message_that_says_what_it_is(): void
    {
        $mine = $this->owing(5000);
        $work = $this->owed(3000);

        $this->setOff(3000, [$mine->id => 3000], [$work->id => 3000])->assertSessionHasNoErrors();

        $receipt = session('receipt');

        $this->assertSame('setoff', $receipt['kind']);
        $this->assertSame($this->customer->name, $receipt['name']);
        $this->assertEquals(3000, $receipt['amount']);
        $this->assertEquals(2000, $receipt['balance']);
        $this->assertSame('', $receipt['mode']);

        // Their own file, and not the other customer's vehicle the vendor worked on.
        $this->assertCount(1, $receipt['against']);
        $this->assertStringContainsString($mine->registration_no, $receipt['against'][0]['label']);
        $this->assertStringNotContainsString($work->registration_no, json_encode($receipt));
        $this->assertStringNotContainsString($this->vendor->name, json_encode($receipt));
    }

    /** On the portal the customer reads the owner's words, and nothing of the vendor account. */
    public function test_the_customer_reads_the_owners_words_on_their_portal(): void
    {
        $mine = $this->owing(5000);
        $work = $this->owed(3000);

        $this->setOff(3000, [$mine->id => 3000], [$work->id => 3000]);

        $body = $this->withSession(['customer_id' => $this->customer->id])
            ->getJson(route('customer.statement'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Adjusted against payment due to you', $body);
        $this->assertStringNotContainsString('amount due from you', $body);
        $this->assertStringNotContainsString($work->registration_no, $body);
        $this->assertStringNotContainsString($this->vendor->name, $body);
    }

    /** The vendor account's own statement reads its own words, and the office sees the pair. */
    public function test_each_statement_tells_the_office_which_entry_is_the_other_half(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->setOff(1000, [], [], ['reason' => 'On the phone']);

        [$mine, $theirs] = $this->halves();

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))
            ->assertOk()->json('props.rows'))->keyBy('id');

        $this->assertSame('Adjusted against payment due to you', $rows[$mine->id]['particular']);
        $this->assertSame((int) $theirs->id, $rows[$mine->id]['setoff_with']);
        $this->assertStringContainsString('vendor entry #'.$theirs->id, $rows[$mine->id]['office_note']);
        $this->assertStringContainsString('On the phone', $rows[$mine->id]['office_note']);
        $this->assertStringNotContainsString($this->vendor->name, (string) $rows[$mine->id]['office_note']);

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->vendor->id))
            ->assertOk()->json('props.rows'))->keyBy('id');

        $this->assertSame('Adjusted against amount due from you', $rows[$theirs->id]['particular']);
        $this->assertSame((int) $mine->id, $rows[$theirs->id]['setoff_with']);
        $this->assertStringContainsString('customer entry #'.$mine->id, $rows[$theirs->id]['office_note']);
    }

    // ------------------------------------------------------ taking it back

    /** Reversed from either half, both go back — the same day, the same reason. */
    public function test_reversing_either_half_reverses_both(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $mine = $this->owing(5000);
            $work = $this->owed(3000);
            $before = [$this->balance($this->customer), $this->balance($this->vendor)];

            $this->setOff(3000, [$mine->id => 3000], [$work->id => 3000])->assertSessionHasNoErrors();

            [$customerHalf, $vendorHalf] = $this->halves();

            $this->reverse($side === 'customer' ? $customerHalf : $vendorHalf)->assertSessionHasNoErrors()->assertSessionHas('success');

            $this->assertSame($before, [$this->balance($this->customer), $this->balance($this->vendor)], $side);

            $reversals = PartyLedgerModel::whereIn('reverses_id', [$customerHalf->id, $vendorHalf->id])->get()->keyBy('reverses_id');
            $this->assertCount(2, $reversals, $side);
            $this->assertSame((int) $this->customer->id, (int) $reversals[$customerHalf->id]->party_id);
            $this->assertSame('debit', $reversals[$customerHalf->id]->entry_type);
            $this->assertSame((int) $this->vendor->id, (int) $reversals[$vendorHalf->id]->party_id);
            $this->assertSame('credit', $reversals[$vendorHalf->id]->entry_type);
            $this->assertSame($reversals[$customerHalf->id]->txn_date, $reversals[$vendorHalf->id]->txn_date);
            $this->assertSame('Agreed the wrong figure', $reversals[$vendorHalf->id]->note);

            // Both halves' files let go.
            $this->assertSame(0, DB::table('party_ledger_allocation')->whereIn('entry_id', [$customerHalf->id, $vendorHalf->id])->whereNull('released_at')->count());
        }
    }

    /** Entered again, it comes back as a set-off, against the same account, each side's files where they were. */
    public function test_correct_brings_it_back_as_a_set_off_with_both_sides_files(): void
    {
        $mine = $this->owing(5000);
        $work = $this->owed(3000);
        $this->setOff(2000, [$mine->id => 2000], [$work->id => 1500], ['reason' => 'On the phone']);

        [$customerHalf, $vendorHalf] = $this->halves();

        $this->reverse($vendorHalf, true)
            ->assertRedirect(route('party.entry', 'vendor'))
            ->assertSessionHasInput('entry_kind', 'setoff')
            ->assertSessionHasInput('party_id', (string) $this->vendor->id)
            ->assertSessionHasInput('entry_type', 'debit')
            ->assertSessionHasInput('counterpart_id', (string) $this->customer->id)
            ->assertSessionHasInput('reason', 'On the phone')
            ->assertSessionHasInput('particular', '')
            ->assertSessionHasInput('payment_mode', '')
            ->assertSessionHasInput('alloc', [$work->id => ['work_file_id' => $work->id, 'amount' => '1500']])
            ->assertSessionHasInput('counter_alloc', [$mine->id => ['work_file_id' => $mine->id, 'amount' => '2000']]);

        $this->assertSame(2, PartyLedgerModel::whereIn('reverses_id', [$customerHalf->id, $vendorHalf->id])->count());

        // And the screen it lands on offers it again, ticked.
        $props = $this->actingAs($this->admin)->getJson(route('party.entry', 'vendor'))->json('props');
        $this->assertSame('setoff', $props['initial']['entry_kind']);
        $this->assertSame((int) $this->customer->id, collect($props['counterparts'])->firstWhere('own_id', $this->vendor->id)['id']);
        $this->assertSame([$mine->id => '2000'], $props['initialCounterAlloc']);
    }

    /** Unlinked since, it could not be saved again: Correct is refused before anything is reversed. */
    public function test_correct_is_refused_once_the_accounts_are_no_longer_linked(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->setOff(1000);

        [$customerHalf, $vendorHalf] = $this->halves();
        $this->link($this->customer, null);

        $this->reverse($customerHalf, true)->assertSessionHasErrors('reason');
        $this->assertSame(0, PartyLedgerModel::whereIn('reverses_id', [$customerHalf->id, $vendorHalf->id])->count());

        // Reversed, it still can be: a set-off already made keeps its own pair.
        $this->reverse($customerHalf)->assertSessionHasNoErrors();
        $this->assertSame(2, PartyLedgerModel::whereIn('reverses_id', [$customerHalf->id, $vendorHalf->id])->count());
    }

    /** One half gone back alone — only by a hand in the database — is refused, and the audit names it. */
    public function test_a_half_reversed_alone_is_refused_and_the_audit_names_it(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->setOff(1000);

        [$customerHalf, $vendorHalf] = $this->halves();

        $alone = new PartyLedgerModel;
        $alone->party_id = $this->vendor->id;
        $alone->txn_date = '2026-09-21';
        $alone->entry_type = 'credit';
        $alone->amount = 1000;
        $alone->payment_mode = PartyLedgerModel::REVERSAL_MODE;
        $alone->particular = 'Reversal by hand';
        $alone->entry_kind = PartyLedgerModel::REVERSAL;
        $alone->reverses_id = $vendorHalf->id;
        $alone->save();

        $this->reverse($customerHalf)->assertSessionHasErrors('reason');
        $this->assertFalse(PartyLedgerModel::where('reverses_id', $customerHalf->id)->exists());

        $this->assertStringContainsString('had only #'.$vendorHalf->id.' reversed', $this->audit());
    }

    /** The audit names a half with no other half, and a pair whose halves disagree. */
    public function test_the_audit_names_a_set_off_that_is_not_a_whole_pair(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->setOff(1000);

        [$customerHalf, $vendorHalf] = $this->halves();

        $this->assertStringNotContainsString('set-off #', $this->audit(), 'a whole pair is flagged');

        DB::table('party_ledger')->where('id', $vendorHalf->id)->update(['amount' => 900]);
        $this->assertStringContainsString('has halves of', $this->audit());

        DB::table('party_ledger')->where('id', $customerHalf->id)->update(['setoff_with_id' => null]);
        $this->assertStringContainsString('set-off #'.$customerHalf->id.' ', $this->audit());

        // And a half whose other half no longer names it back is not reversed alone.
        $this->reverse($vendorHalf)->assertSessionHasErrors('reason');
        $this->assertFalse(PartyLedgerModel::where('reverses_id', $vendorHalf->id)->exists());
    }

    // --------------------------------------------------- changing its files

    /** A half's files can change as a receipt's can, and the other half is not touched. */
    public function test_a_halfs_files_can_be_changed_like_a_receipts(): void
    {
        $old = $this->owing(2000, '2026-07-01');
        $new = $this->owing(3000, '2026-08-01');
        $work = $this->owed(3000);
        $this->setOff(2000, [], [$work->id => 2000]);

        [$customerHalf, $vendorHalf] = $this->halves();

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))->json('props.rows'))->keyBy('id');
        $this->assertNotNull($rows[$customerHalf->id]['adjust_url']);

        $drawn = $this->actingAs($this->admin)->getJson(route('party.adjust', $customerHalf->id))->assertOk()->json('props.drawn');

        $this->actingAs($this->admin)
            ->from(route('party.adjust', $customerHalf->id))
            ->post(route('party.adjust', $customerHalf->id), ['alloc' => $this->lines([$new->id => 2000]), 'drawn' => $drawn])
            ->assertSessionHasNoErrors();

        $owedByFile = PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id] ?? [];
        $this->assertSame([$old->id => 2000.0, $new->id => 1000.0], $owedByFile);

        $this->assertSame([[$work->id, '2000.00']], DB::table('party_ledger_allocation')->where('entry_id', $vendorHalf->id)
            ->whereNull('released_at')->get()->map(fn ($l) => [(int) $l->work_file_id, (string) $l->amount])->all());
    }

    // ------------------------------------------------------------- the link

    /** Linked on the customer's Edit screen, and said by whom and when. */
    public function test_the_link_is_set_on_the_customers_edit_screen(): void
    {
        $this->link($this->customer, null);

        $save = fn (PartyModel $party, $vendorId) => $this->actingAs($this->admin)
            ->from(route('party.edit', $party->id))
            ->post(route('party.edit', $party->id), [
                'name' => $party->name,
                'mobile' => $party->mobile,
                'is_active' => '1',
                'linked_vendor_id' => $vendorId,
            ]);

        $save($this->customer, $this->vendor->id)->assertSessionHasNoErrors();

        $this->customer->refresh();
        $this->assertSame((int) $this->vendor->id, (int) $this->customer->linked_vendor_id);
        $this->assertSame($this->admin->id, (int) $this->customer->linked_by);
        $this->assertNotNull($this->customer->linked_at);

        // One vendor is one customer's.
        $save($this->other, $this->vendor->id)->assertSessionHasErrors('linked_vendor_id');
        $this->assertNull($this->other->fresh()->linked_vendor_id);

        // A customer is not a vendor.
        $save($this->other, $this->customer->id)->assertSessionHasErrors('linked_vendor_id');

        // The vendor's form says who, and the way there.
        $link = $this->actingAs($this->admin)->getJson(route('party.edit', $this->vendor->id))->json('props.link');
        $this->assertSame('vendor', $link['side']);
        $this->assertStringContainsString($this->customer->name, $link['linkedName']);

        // And a vendor linked elsewhere is not offered on another customer's form.
        $options = collect($this->actingAs($this->admin)->getJson(route('party.edit', $this->other->id))->json('props.link.options'));
        $this->assertFalse($options->contains('id', $this->vendor->id));

        // Unlinked, the set-off is no longer offered.
        $save($this->customer, '')->assertSessionHasNoErrors();
        $this->assertNull($this->customer->fresh()->linked_vendor_id);
    }

    /**
     * A vendor with a customer's mobile may be one person: the vendor's Edit
     * screen says so, and points to the customer's, where the link is made.
     * Asked, never assumed: opening it links nothing. Asked for by the owner
     * (2026-09-23).
     */
    public function test_a_vendor_is_told_of_a_customer_with_its_mobile(): void
    {
        $vendor = $this->party('vendor', 'Same Phone Works');
        $customer = $this->party('customer', 'Same Phone Dealer');
        $customer->mobile = $vendor->mobile;
        $customer->save();

        $link = $this->actingAs($this->admin)->getJson(route('party.edit', $vendor->id))->assertOk()->json('props.link');

        $this->assertSame($customer->name, $link['suggestName']);
        $this->assertSame(route('party.edit', $customer->id), $link['suggestUrl']);
        $this->assertNull($customer->fresh()->linked_vendor_id, 'opening the screen linked them');

        // Not a customer already linked to somebody else.
        $customer->linked_vendor_id = $this->vendor->id;
        $this->link($this->customer, null);
        $customer->save();

        $link = $this->actingAs($this->admin)->getJson(route('party.edit', $vendor->id))->json('props.link');
        $this->assertSame('', $link['suggestName']);

        // And nothing once the vendor is linked — even beside an unlinked
        // customer with its mobile: it says who it is linked to instead.
        $customer->linked_vendor_id = null;
        $customer->save();
        $this->link($this->customer, $vendor);

        $link = $this->actingAs($this->admin)->getJson(route('party.edit', $vendor->id))->json('props.link');
        $this->assertSame('', $link['suggestName']);
        $this->assertStringContainsString($this->customer->name, $link['linkedName']);
    }

    /** The Entry screen knows each party's other account, with its balance, and no one else's. */
    public function test_the_entry_screen_offers_it_only_for_linked_accounts(): void
    {
        $this->owed(3000);

        $props = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props');
        $linked = collect($props['counterparts'])->keyBy('own_id');

        $this->assertTrue($props['settable']);
        $this->assertTrue(array_is_list($props['counterparts']), 'keyed by party, the recorded shape names one party');
        $this->assertSame([
            'own_id' => (int) $this->customer->id,
            'id' => (int) $this->vendor->id,
            'name' => $this->vendor->name,
            'balance' => -3000,
            'active' => true,
        ], $linked[$this->customer->id]);
        $this->assertFalse($linked->has($this->other->id));

        $linked = collect($this->actingAs($this->admin)->getJson(route('party.entry', 'vendor'))->json('props.counterparts'))->keyBy('own_id');
        $this->assertSame((int) $this->customer->id, $linked[$this->vendor->id]['id']);
    }

    /**
     * Locked before anything is read, in the transaction that reverses. Found
     * in review: a plain read first fixed what every read after it saw, before
     * the locks were waited for — a reversal a colleague had just committed
     * went unseen, and the second was a 500 rather than "already reversed".
     * The race needs two connections; what is pinned here is the order.
     */
    public function test_a_reversal_locks_before_it_reads(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->setOff(1000);

        [$customerHalf] = $this->halves();

        $ordinary = new PartyLedgerModel;
        $ordinary->party_id = $this->customer->id;
        $ordinary->txn_date = '2026-09-21';
        $ordinary->entry_type = 'credit';
        $ordinary->amount = 100;
        $ordinary->payment_mode = 'UPI';
        $ordinary->particular = 'Payment';
        $ordinary->save();

        foreach ([$customerHalf, $ordinary] as $entry) {
            $inside = false;
            $first = null;

            DB::listen(function ($query) use (&$inside, &$first) {
                if ($inside && $first === null) {
                    $first = $query->sql;
                }
            });

            \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$inside) {
                $inside = true;
            });

            $this->reverse($entry)->assertSessionHasNoErrors();

            $this->assertNotNull($first, 'nothing was read in the transaction');
            $this->assertStringContainsString('for update', strtolower($first), 'entry #'.$entry->id.': the first read in the transaction is not a lock');
        }
    }

    /** Rolling the migration back is refused while a set-off stands on it. */
    public function test_rolling_back_is_refused_while_a_set_off_exists(): void
    {
        $this->owing(5000);
        $this->owed(3000);
        $this->setOff(1000);

        /*
         * Its schema changes are not rolled back with the test, so it is run
         * only once the set-off it must refuse over is known to be there.
         * Found in review: had the set-off been refused, down() would have
         * dropped the columns from the database the tests share.
         */
        $this->assertTrue(DB::table('party_ledger')->whereNotNull('setoff_with_id')->exists(), 'the premise: a set-off stands');

        $migration = require database_path('migrations/2026_09_23_000200_let_a_customer_be_set_off_against_their_vendor_account.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}

<?php

namespace Tests\Feature;

use App\Models\OfficeSettingModel;
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
 * Giving up the rest of a bill.
 *
 * A customer owes 5,000, pays 4,950, and the 50 sits on their statement for
 * ever. Written off, the bill closes: the charge stays as it was — it was the
 * right charge — and the 50 is a Discount on the customer's statement, with
 * why it was given kept for the office.
 *
 * Read for: the bill leaves Not Yet Collected; nothing larger than the office's
 * own limit goes, at one time or on one bill in total; a bill can only be
 * forgiven what it is still owed; and the Profit report says what was given up,
 * in the period the charge was booked.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class WriteOffTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Write Off Admin';
        $this->admin->email = 'writeoff-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();

        // The office's own limit, as the Setup screen sets it.
        $this->cap(500);
    }

    private function cap(float $amount): void
    {
        OfficeSettingModel::put(OfficeSettingModel::WRITEOFF_CAP, number_format($amount, 2, '.', ''), $this->admin->id);
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' write-off';
        $party->mobile = '93600'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function file(float $charge, string $received = '2026-08-01'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-WO-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01WO'.random_int(1000, 9999);
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

    private function lines(array $against): array
    {
        $alloc = [];

        foreach ($against as $fileId => $share) {
            $alloc[$fileId] = ['work_file_id' => $fileId, 'amount' => $share];
        }

        return $alloc;
    }

    /** A payment, as the Entry screen posts one. */
    private function pay(float $amount, array $against = [], ?PartyModel $party = null)
    {
        $party ??= $this->customer;

        return $this->actingAs($this->admin)->post(route('party.entry', $party->party_type), [
            'party_id' => $party->id,
            'entry_type' => $party->party_type === 'customer' ? 'credit' : 'debit',
            'txn_date' => '2026-09-10',
            'amount' => $amount,
            'payment_mode' => 'UPI',
            'particular' => 'Payment',
            'alloc' => $this->lines($against),
        ]);
    }

    /** A write-off, as the same screen posts one with the box ticked. */
    private function writeOff(float $amount, array $against, array $extra = [], ?PartyModel $party = null)
    {
        $party ??= $this->customer;

        return $this->actingAs($this->admin)
            ->from(route('party.entry', $party->party_type))
            ->post(route('party.entry', $party->party_type), $extra + [
                'party_id' => $party->id,
                'entry_type' => $party->party_type === 'customer' ? 'credit' : 'debit',
                'txn_date' => '2026-09-20',
                'amount' => $amount,
                'entry_kind' => 'writeoff',
                'reason' => 'Rounded off, customer paid in full',
                'alloc' => $this->lines($against),
            ]);
    }

    private function owed(): array
    {
        return PartyLedgerModel::outstandingByFile([$this->customer->id])[$this->customer->id] ?? [];
    }

    private function written(): ?PartyLedgerModel
    {
        return PartyLedgerModel::where('party_id', $this->customer->id)
            ->where('entry_kind', PartyLedgerModel::WRITEOFF)->latest('id')->first();
    }

    // ------------------------------------------------------------- the point

    /** The 50 left on a 5,000 bill, given up: the bill closes and nothing is owed. */
    public function test_the_rest_of_a_bill_is_given_up_and_the_bill_closes(): void
    {
        $file = $this->file(5000);
        $this->pay(4950, [$file->id => 4950])->assertSessionHas('success');

        $this->assertSame([$file->id => 50.0], $this->owed(), 'the premise: 50 left on it');

        $this->writeOff(50, [$file->id => 50])->assertSessionHas('success');

        $this->assertSame([], $this->owed(), 'the bill is still owed');
        $this->assertEqualsWithDelta(0, PartyLedgerModel::currentBalance($this->customer->id), 0.001);

        $entry = $this->written();
        $this->assertSame('credit', $entry->entry_type);
        $this->assertEquals(50, $entry->amount);
        $this->assertSame('Discount', $entry->particular, 'the customer reads something else');
        $this->assertNull($entry->payment_mode, 'a write-off moved no money');
        $this->assertSame('Rounded off, customer paid in full', $entry->note);
        $this->assertSame($this->admin->id, (int) $entry->created_by);

        // The charge is untouched: it was the right charge.
        $this->assertEquals(5000, PartyLedgerModel::where('work_file_id', $file->id)->where('entry_type', 'debit')->value('amount'));
    }

    /** The office sees why; the customer sees only that it was a discount. */
    public function test_the_office_sees_why_and_the_customer_never_does(): void
    {
        $file = $this->file(1000);
        $this->pay(900, [$file->id => 900]);
        $this->writeOff(100, [$file->id => 100]);

        $entry = $this->written();

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $this->customer->id))
            ->assertOk()->json('props.rows'))->keyBy('id');

        $this->assertSame('Discount', $rows[$entry->id]['particular']);
        $this->assertSame('Why: Rounded off, customer paid in full', $rows[$entry->id]['office_note']);

        $said = $this->withSession(['customer_id' => $this->customer->id])
            ->getJson(route('customer.statement'))->assertOk()->getContent();

        $this->assertStringContainsString('Discount', $said);
        $this->assertStringNotContainsString('Rounded off', $said);
    }

    /**
     * Forgiveness is not money.
     *
     * The bill it was given up on can go — the papers returned, the work
     * struck off, the price brought down under it — and what it forgave then
     * settles nothing. Found in review: treated as money on account, the 50
     * given up on one bill quietly closed 50 of another.
     */
    public function test_what_it_forgave_never_settles_another_bill(): void
    {
        // Small enough that what was paid cannot cover the other bill by itself.
        $returned = $this->file(100);
        $other = $this->file(2000, '2026-08-02');

        $this->pay(50, [$returned->id => 50]);
        $this->writeOff(50, [$returned->id => 50])->assertSessionHas('success');

        $this->assertSame([$other->id => 2000.0], $this->owed(), 'the premise: the other bill stands');

        // The papers go back, so the charge it forgave is credited in full.
        $returned->status = WorkFileModel::RETURNED;
        $returned->returned_on = now()->toDateString();
        $returned->save();
        $returned->items()->update(['status' => WorkFileModel::RETURNED]);
        $returned->fresh()->syncLedger();

        // The 50 paid settles 50 of the other bill; the 50 forgiven settles nothing.
        $this->assertSame([$other->id => 1950.0], $this->owed(), 'the discount walked on to another bill');

        // And the office is told to take it back, rather than left to notice.
        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('files:audit'));
        $said = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString($returned->file_no, $said);
        $this->assertStringContainsString('settles nothing', $said);
    }

    /** Taken back after that, the account says only what was really paid. */
    public function test_taking_it_back_squares_the_account(): void
    {
        $file = $this->file(5000);
        $this->pay(4950, [$file->id => 4950]);
        $this->writeOff(50, [$file->id => 50]);

        $file->status = WorkFileModel::RETURNED;
        $file->returned_on = now()->toDateString();
        $file->save();
        $file->items()->update(['status' => WorkFileModel::RETURNED]);
        $file->fresh()->syncLedger();

        $this->actingAs($this->admin)
            ->post(route('party.reverse', $this->written()->id), ['reason' => 'The papers went back'])
            ->assertSessionHas('success');

        // 4,950 came in and 5,000 went back out: the office owes 4,950.
        $this->assertEqualsWithDelta(-4950, PartyLedgerModel::currentBalance($this->customer->id), 0.005);
    }

    /** Entered again after a reversal, a write-off comes back as one. */
    public function test_reverse_and_enter_it_again_keeps_it_a_write_off(): void
    {
        $file = $this->file(1000);
        $this->pay(900, [$file->id => 900]);
        $this->writeOff(100, [$file->id => 100]);

        $this->actingAs($this->admin)
            ->post(route('party.reverse', $this->written()->id), ['reason' => 'Wrong bill', 'correct' => '1'])
            ->assertRedirect(route('party.entry', 'customer'));

        $initial = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props.initial');

        $this->assertSame('writeoff', $initial['entry_kind'], 'it came back as an ordinary credit');
        // Its own reason comes back with it, to be kept or changed.
        $this->assertSame('Rounded off, customer paid in full', $initial['reason']);
        $this->assertSame('', $initial['particular'], 'the word Discount was handed back to be typed');
    }

    /** Nothing else may call itself a Discount on a customer's statement. */
    public function test_an_ordinary_entry_cannot_be_called_a_discount(): void
    {
        $this->actingAs($this->admin)
            ->from(route('party.entry', 'customer'))
            ->post(route('party.entry', 'customer'), [
                'party_id' => $this->customer->id,
                'entry_type' => 'credit',
                'txn_date' => '2026-09-20',
                'amount' => 100,
                'payment_mode' => 'Cash',
                'particular' => 'discount',
            ])
            ->assertSessionHasErrors('particular');

        $this->assertSame(0, PartyLedgerModel::where('party_id', $this->customer->id)->where('particular', 'discount')->count());
    }

    /** The per-bill limit is this customer's own: a file given to somebody else brings none of theirs. */
    public function test_the_per_bill_limit_does_not_follow_a_file_to_another_customer(): void
    {
        $file = $this->file(5000);
        $this->writeOff(500, [$file->id => 500])->assertSessionHas('success');

        $theirs = $this->party('customer');
        $file->customer_id = $theirs->id;
        $file->save();
        $file->fresh()->syncLedger();

        $this->actingAs($this->admin)
            ->from(route('party.entry', 'customer'))
            ->post(route('party.entry', 'customer'), [
                'party_id' => $theirs->id,
                'entry_type' => 'credit',
                'txn_date' => '2026-09-21',
                'amount' => 500,
                'entry_kind' => 'writeoff',
                'reason' => 'Their own rounding',
                'alloc' => $this->lines([$file->id => 500]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PartyLedgerModel::where('party_id', $theirs->id)->where('entry_kind', PartyLedgerModel::WRITEOFF)->count());
    }

    // ---------------------------------------------------------------- refused

    private function assertRefusedAndNothingWritten($response): void
    {
        $response->assertRedirect(route('party.entry', 'customer'))->assertSessionHasErrors();

        $this->assertNull($this->written(), 'a write-off was written anyway');
    }

    public function test_more_than_the_limit_at_one_time_is_refused(): void
    {
        $file = $this->file(5000);

        $this->assertRefusedAndNothingWritten($this->writeOff(501, [$file->id => 501]));
        $this->assertStringContainsString('500.00 can be written off at one time', session('errors')->first('alloc'));

        // And exactly the limit goes.
        $this->writeOff(500, [$file->id => 500])->assertSessionHas('success');
    }

    /** The limit holds per bill too, or a debt goes in lots of fifty. */
    public function test_more_than_the_limit_on_one_bill_in_total_is_refused(): void
    {
        $file = $this->file(5000);

        $this->writeOff(300, [$file->id => 300])->assertSessionHas('success');

        $again = $this->writeOff(300, [$file->id => 300]);

        $again->assertSessionHasErrors('alloc');
        $this->assertStringContainsString('already had 300.00 written off', session('errors')->first('alloc'));
        $this->assertSame(1, PartyLedgerModel::where('party_id', $this->customer->id)->where('entry_kind', PartyLedgerModel::WRITEOFF)->count());
    }

    /**
     * Only what the bill is still owed.
     *
     * Not what is "open" on it, which is what an ordinary adjustment may take:
     * open counts money on account as not yet spoken for, and forgiving that
     * would be giving up what the customer has already paid.
     */
    public function test_more_than_the_bill_is_owed_is_refused(): void
    {
        $file = $this->file(1000);
        $this->pay(300, [$file->id => 300]);
        // Paid without saying which bill; it covers this one, the only one there is.
        $this->pay(400);

        $bill = PartyLedgerModel::bills($this->customer->id)['files'][$file->id];
        $this->assertEquals(700, $bill['open'], 'the premise: 700 not spoken for');
        $this->assertEquals(300, $bill['due'], 'the premise: 300 still owed');

        $this->assertRefusedAndNothingWritten($this->writeOff(500, [$file->id => 500]));
        $this->assertStringContainsString('owed only 300.00 now', session('errors')->first('alloc'));

        // And what it is owed goes.
        $this->writeOff(300, [$file->id => 300])->assertSessionHas('success');
    }

    public function test_a_bill_covered_by_money_on_account_has_nothing_to_write_off(): void
    {
        $file = $this->file(1000);
        // Paid without saying which bill: the file is covered, though nothing is adjusted.
        $this->pay(1000);

        $this->assertRefusedAndNothingWritten($this->writeOff(50, [$file->id => 50]));
    }

    public function test_it_must_say_which_bill_and_cover_the_whole_of_itself(): void
    {
        $file = $this->file(1000);
        $this->pay(900, [$file->id => 900]);

        $this->assertRefusedAndNothingWritten($this->writeOff(100, []));
        $this->assertStringContainsString('which bill', session('errors')->first('alloc'));

        $this->assertRefusedAndNothingWritten($this->writeOff(100, [$file->id => 60]));
        $this->assertStringContainsString('has to be against bills', session('errors')->first('alloc'));
    }

    public function test_a_reason_is_required(): void
    {
        $file = $this->file(1000);
        $this->pay(900, [$file->id => 900]);

        $this->writeOff(100, [$file->id => 100], ['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertNull($this->written());
    }

    public function test_a_vendors_bill_is_not_written_off_here(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file(1000);

        $this->writeOff(50, [$file->id => 50], [], $vendor)->assertSessionHasErrors('alloc');

        $this->assertStringContainsString('vendor', strtolower(session('errors')->first('alloc')));
        $this->assertSame(0, PartyLedgerModel::where('party_id', $vendor->id)->where('entry_kind', PartyLedgerModel::WRITEOFF)->count());
    }

    public function test_a_charge_is_not_written_off(): void
    {
        $file = $this->file(1000);

        $this->assertRefusedAndNothingWritten($this->writeOff(50, [$file->id => 50], ['entry_type' => 'debit']));

        // Said as a write-off's own rule, not as "only a payment can be adjusted".
        $this->assertStringContainsString('lowers what they owe', session('errors')->first('alloc'));
    }

    /** Nought in Setup → Limits turns it off, and the screen stops offering it. */
    public function test_nothing_is_written_off_while_the_limit_is_nought(): void
    {
        $file = $this->file(1000);
        $this->pay(900, [$file->id => 900]);
        $this->cap(0);

        $this->assertRefusedAndNothingWritten($this->writeOff(100, [$file->id => 100]));
        $this->assertStringContainsString('off until a limit above nought is set', session('errors')->first('alloc'));

        $props = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props');
        $this->assertEquals(0, $props['writeOffCap']);
    }

    public function test_the_entry_screen_says_the_limit_for_customers_only(): void
    {
        $this->assertEquals(500, $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props.writeOffCap'));
        $this->assertEquals(0, $this->actingAs($this->admin)->getJson(route('party.entry', 'vendor'))->json('props.writeOffCap'));
    }

    // ------------------------------------------------------------ the report

    /** What was given up, on its own line, in the period the charge was booked. */
    public function test_the_profit_report_says_what_was_given_up(): void
    {
        $file = $this->file(5000, '2026-08-05');
        $this->pay(4950, [$file->id => 4950]);
        $this->writeOff(50, [$file->id => 50])->assertSessionHas('success');

        $line = fn (array $query) => collect($this->actingAs($this->admin)
            ->getJson(route('report.profit').'?'.http_build_query($query))->assertOk()->json('props.rows'))
            ->first(fn ($row) => str_starts_with((string) $row['label'], 'Discounts & write-offs'));

        $given = $line(['group' => 'month']);

        $this->assertNotNull($given, 'nothing said about what was given up');
        $this->assertEquals(50, $given['cost']);
        $this->assertEquals(-50, $given['margin']);

        // Dated by the file, so it lands where the 5,000 was counted — not in
        // the month the discount happened to be given.
        $this->assertNotNull($line(['group' => 'month', 'from' => '2026-08-01', 'to' => '2026-08-31']));
        $this->assertNull($line(['group' => 'month', 'from' => '2026-09-01', 'to' => '2026-09-30']));

        // The same figure however the report is cut.
        $this->assertEquals(50, $line(['group' => 'customer'])['cost']);
        $this->assertEquals(50, $line(['group' => 'work_type'])['cost']);
    }

    /** Cut by month, each month's discounts sit with that month. */
    public function test_each_period_carries_its_own_discounts(): void
    {
        $august = $this->file(1000, '2026-08-05');
        $september = $this->file(1000, '2026-09-05');

        $this->pay(900, [$august->id => 900]);
        $this->pay(900, [$september->id => 900]);
        $this->writeOff(100, [$august->id => 100])->assertSessionHas('success');
        $this->writeOff(60, [$september->id => 60])->assertSessionHas('success');

        $rows = collect($this->actingAs($this->admin)->getJson(route('report.profit').'?group=month')
            ->assertOk()->json('props.rows'));

        $given = $rows->filter(fn ($row) => str_starts_with((string) $row['label'], 'Discounts & write-offs'));

        $this->assertSame(100.0, (float) $given->first(fn ($row) => str_contains($row['label'], 'Aug 2026'))['cost']);
        $this->assertSame(60.0, (float) $given->first(fn ($row) => str_contains($row['label'], 'Sep 2026'))['cost']);

        // Each under the month whose margin it corrects.
        $keys = $rows->pluck('label')->values()->all();
        $this->assertSame('Aug 2026', $keys[array_search('Discounts & write-offs · Aug 2026', $keys, true) - 1] ?? null);
    }

    /** The dashboard's own figures say the same as the report they link to. */
    public function test_the_dashboard_counts_what_was_given_up(): void
    {
        $file = $this->file(1000, now()->startOfMonth()->toDateString());
        $this->pay(900, [$file->id => 900]);

        $before = WorkFileModel::summary()['month_margin'];
        $chartBefore = collect(WorkFileModel::monthlyMoney())->firstWhere('month', now()->format('Y-m'));

        $this->writeOff(100, [$file->id => 100])->assertSessionHas('success');

        $after = WorkFileModel::summary()['month_margin'];
        $chartAfter = collect(WorkFileModel::monthlyMoney())->firstWhere('month', now()->format('Y-m'));

        $this->assertEqualsWithDelta($before - 100, $after, 0.005, 'the tile still reads as though nothing was given up');
        $this->assertEqualsWithDelta($chartBefore['margin'] - 100, $chartAfter['margin'], 0.005);
        $this->assertEqualsWithDelta($chartBefore['cost'] + 100, $chartAfter['cost'], 0.005);
    }

    /** Taken back, it gave up nothing: the bill is owed again and the report says so. */
    public function test_a_write_off_taken_back_is_owed_again(): void
    {
        $file = $this->file(1000, '2026-08-05');
        $this->pay(900, [$file->id => 900]);
        $this->writeOff(100, [$file->id => 100]);

        $entry = $this->written();

        $this->actingAs($this->admin)
            ->post(route('party.reverse', $entry->id), ['reason' => 'Written off by mistake'])
            ->assertSessionHas('success');

        $this->assertSame([$file->id => 100.0], $this->owed());

        $given = collect($this->actingAs($this->admin)->getJson(route('report.profit').'?group=month')
            ->json('props.rows'))->first(fn ($row) => str_starts_with((string) $row['label'], 'Discounts & write-offs'));

        $this->assertNull($given, 'a write-off taken back is still counted as given up');
    }
}

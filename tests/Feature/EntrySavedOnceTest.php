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
 * Save Entry pressed twice.
 *
 * A double click, or Enter pressed twice in the amount box, sends the same
 * form twice, and each was saved: the customer's statement showed the payment
 * twice and the balance was wrong by the whole of it, the WhatsApp receipt
 * quoted that balance, a set-off cleared twice the amount on both accounts,
 * and a vendor paid from Vendor Payments read as paid twice. Nothing said so.
 *
 * The page now sends a token of its own with what it posts (once), and the
 * server saves one page's entry once: checked under the party's lock, so a
 * second press waits for the first and then finds it. The second press is
 * told the entry was already saved, and which one it is.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class EntrySavedOnceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Saved Once Admin';
        $this->admin->email = 'saved-once-'.uniqid().'@example.com';
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
        $party->name = ucfirst($type).' '.uniqid().' saved once';
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** What a customer owes, or the office owes a vendor, typed straight into the ledger. */
    private function owing(PartyModel $party, float $amount): void
    {
        DB::table('party_ledger')->insert([
            'party_id' => $party->id,
            'txn_date' => '2026-09-01',
            'entry_type' => $party->party_type === 'customer' ? 'debit' : 'credit',
            'amount' => $amount,
            'particular' => 'Work charged',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A file of one work charged to a customer, given to a vendor at a rate when one is named. */
    private function file(PartyModel $customer, float $charge, ?PartyModel $vendor = null, float $rate = 0): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-SV-'.uniqid();
        $file->received_date = '2026-08-01';
        $file->registration_no = 'BR01SV'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = $charge;
        $file->status = $vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = $charge;
        $item->status = $file->status;

        if ($vendor) {
            $item->vendor_id = $vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = '2026-08-01';
        }

        $item->save();

        $file->rollUp();
        $file->save();
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

    /** The Entry screen's post, as one page sends it: $once is that page's token. */
    private function send(PartyModel $party, ?string $once, array $entry = [])
    {
        return $this->actingAs($this->admin)
            ->from(route('party.entry', $party->party_type))
            ->post(route('party.entry', $party->party_type), $entry + [
                'party_id' => $party->id,
                'entry_type' => $party->party_type === 'customer' ? 'credit' : 'debit',
                'txn_date' => '2026-09-21',
                'amount' => 5000,
                'payment_mode' => 'UPI',
                'ref_no' => '412345678901',
                'particular' => 'Payment',
            ] + ($once === null ? [] : ['once' => $once]));
    }

    /** The entries typed on the Entry screen for a party: everything but its files' own. */
    private function typed(PartyModel $party)
    {
        return PartyLedgerModel::where('party_id', $party->id)
            ->whereNull('work_file_id')
            ->where('particular', '!=', 'Work charged')
            ->orderBy('id')
            ->get();
    }

    private function balance(PartyModel $party): float
    {
        return round(PartyLedgerModel::currentBalance($party->id), 2);
    }

    // ------------------------------------------------------------- the point

    /** A customer's 5,000 sent twice from one page: one receipt, and the balance moves once. */
    public function test_a_payment_sent_twice_from_one_page_is_saved_once(): void
    {
        $this->owing($this->customer, 7500);

        $this->send($this->customer, 'page-a')->assertSessionHasNoErrors();
        $again = $this->send($this->customer, 'page-a')->assertSessionHasNoErrors();

        $typed = $this->typed($this->customer);

        $this->assertCount(1, $typed, 'the payment was saved twice');
        $this->assertSame(2500.0, $this->balance($this->customer));

        // The second press is told it went, and which entry it is.
        $again->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved')
            && str_contains($said, '#'.$typed[0]->id));
    }

    /**
     * Whichever answer the browser shows is the one the office reads — and a
     * second press's is the one it shows. So it still offers the receipt, with
     * the balance the customer is really left on.
     */
    public function test_the_second_press_still_offers_the_receipt_with_the_true_balance(): void
    {
        $this->owing($this->customer, 7500);

        $this->send($this->customer, 'page-a');

        $this->send($this->customer, 'page-a')->assertSessionHas('receipt', fn ($receipt) => $receipt['amount'] === 5000.0
            && $receipt['balance'] === 2500.0
            && $receipt['mode'] === 'UPI');
    }

    /** Every side of both screens: a receipt, a charge, a payment to a vendor and a vendor's bill. */
    public function test_each_side_of_either_screen_is_saved_once(): void
    {
        $vendor = $this->party('vendor');
        $this->owing($vendor, 9000);

        foreach ([
            [$this->customer, 'credit'],
            [$this->customer, 'debit'],
            [$vendor, 'debit'],
            [$vendor, 'credit'],
        ] as $i => [$party, $side]) {
            $once = 'page-side-'.$i;
            $entry = ['entry_type' => $side, 'amount' => 1000 + $i];

            $this->send($party, $once, $entry)->assertSessionHasNoErrors();
            $this->send($party, $once, $entry)->assertSessionHasNoErrors()
                ->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved'));

            $this->assertSame(1, $this->typed($party)->where('entry_type', $side)->count(), "{$party->party_type} {$side} was saved twice");
        }
    }

    /** Record payment on Vendor Payments opens this screen for one vendor: paid once. */
    public function test_a_vendor_paid_twice_from_one_page_is_paid_once(): void
    {
        $vendor = $this->party('vendor');
        $this->owing($vendor, 9000);

        $this->actingAs($this->admin)
            ->get(route('party.entry', ['type' => 'vendor', 'party_id' => $vendor->id, 'pay' => 1]))
            ->assertOk();

        $this->send($vendor, 'page-pay', ['amount' => 4000]);
        $this->send($vendor, 'page-pay', ['amount' => 4000]);

        $this->assertCount(1, $this->typed($vendor), 'the vendor was paid twice');
        $this->assertSame(-5000.0, $this->balance($vendor));
    }

    /**
     * A payment adjusted against the whole of a bill was not doubled — the
     * second found the bill closed — but it was answered with a refusal, "has
     * only 0.00 left", on a page saying nothing was saved. It was saved.
     */
    public function test_a_payment_adjusted_against_a_whole_bill_is_told_it_was_saved(): void
    {
        $file = $this->file($this->customer, 5000);
        $post = ['alloc' => $this->lines([$file->id => 5000])];

        $this->send($this->customer, 'page-b', $post)->assertSessionHasNoErrors();
        $this->send($this->customer, 'page-b', $post)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved'));

        $this->assertCount(1, $this->typed($this->customer));
        $this->assertSame(1, DB::table('party_ledger_allocation')->where('party_id', $this->customer->id)->count());
    }

    /** 50 given up on a 5,000 bill, sent twice: 50 given up. */
    public function test_a_write_off_sent_twice_is_written_off_once(): void
    {
        OfficeSettingModel::put(OfficeSettingModel::WRITEOFF_CAP, '500.00', $this->admin->id);

        $file = $this->file($this->customer, 5000);

        $writeOff = [
            'amount' => 50,
            'entry_kind' => 'writeoff',
            'reason' => 'Rounded off',
            'payment_mode' => '',
            'ref_no' => '',
            'particular' => '',
            'alloc' => $this->lines([$file->id => 50]),
        ];

        $this->send($this->customer, 'page-c', $writeOff)->assertSessionHasNoErrors();
        $again = $this->send($this->customer, 'page-c', $writeOff)->assertSessionHasNoErrors();

        $this->assertSame(1, PartyLedgerModel::where('party_id', $this->customer->id)
            ->where('entry_kind', PartyLedgerModel::WRITEOFF)->count(), 'written off twice');
        $this->assertSame(4950.0, $this->balance($this->customer));

        // Told as a write-off is, a Discount.
        $again->assertSessionHas('receipt', fn ($receipt) => ($receipt['kind'] ?? null) === 'writeoff');
    }

    // ------------------------------------------------------------- set-offs

    /** @return array{0: PartyModel, 1: PartyModel} a dealer who owes 5,000 and is owed 3,000 */
    private function dealer(): array
    {
        $vendor = $this->party('vendor');
        $this->customer->linked_vendor_id = $vendor->id;
        $this->customer->save();

        $this->owing($this->customer, 5000);
        $this->owing($vendor, 3000);

        return [$this->customer, $vendor];
    }

    private function setOff(PartyModel $from, PartyModel $other, string $once, float $amount = 2000)
    {
        return $this->send($from, $once, [
            'entry_type' => $from->party_type === 'customer' ? 'credit' : 'debit',
            'amount' => $amount,
            'entry_kind' => 'setoff',
            'counterpart_id' => $other->id,
            'payment_mode' => '',
            'ref_no' => '',
            'particular' => '',
        ]);
    }

    private function setOffs(PartyModel $party): int
    {
        return PartyLedgerModel::where('party_id', $party->id)->where('entry_kind', PartyLedgerModel::SETOFF)->count();
    }

    /** 2,000 set off twice cleared 4,000 from both accounts: now 2,000, once. */
    public function test_a_set_off_sent_twice_from_the_customer_is_made_once(): void
    {
        [$customer, $vendor] = $this->dealer();

        $this->setOff($customer, $vendor, 'page-d')->assertSessionHasNoErrors();
        $again = $this->setOff($customer, $vendor, 'page-d')->assertSessionHasNoErrors();

        $this->assertSame(1, $this->setOffs($customer), 'set off twice on the customer');
        $this->assertSame(1, $this->setOffs($vendor), 'set off twice on the vendor');
        $this->assertSame(3000.0, $this->balance($customer));
        $this->assertSame(-1000.0, $this->balance($vendor));

        $mine = PartyLedgerModel::where('party_id', $customer->id)->where('entry_kind', PartyLedgerModel::SETOFF)->first();

        $again->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved')
            && str_contains($said, '#'.$mine->id)
            && str_contains($said, '#'.$mine->setoff_with_id));
        $again->assertSessionHas('receipt', fn ($receipt) => ($receipt['kind'] ?? null) === 'setoff'
            && $receipt['balance'] === 3000.0);
    }

    /** Typed on the vendor's account it is the same two halves, and made once. */
    public function test_a_set_off_sent_twice_from_the_vendor_is_made_once(): void
    {
        [$customer, $vendor] = $this->dealer();

        $this->setOff($vendor, $customer, 'page-e')->assertSessionHasNoErrors();
        $this->setOff($vendor, $customer, 'page-e')->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved'));

        $this->assertSame(1, $this->setOffs($customer));
        $this->assertSame(1, $this->setOffs($vendor));
        $this->assertSame(3000.0, $this->balance($customer));
        $this->assertSame(-1000.0, $this->balance($vendor));
    }

    /**
     * Set off for all the vendor is owed, the second press found nothing left
     * to set off and said "nothing to set off" — about a save that went.
     */
    public function test_a_set_off_of_all_there_was_is_told_it_was_saved(): void
    {
        [$customer, $vendor] = $this->dealer();

        $this->setOff($customer, $vendor, 'page-f', 3000)->assertSessionHasNoErrors();
        $this->setOff($customer, $vendor, 'page-f', 3000)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved'));

        $this->assertSame(1, $this->setOffs($customer));
    }

    // ------------------------------------------------------- under the lock

    /**
     * Where the second press looks for the first: as SQL, in the order sent,
     * with how deep in a transaction each was.
     *
     * On the database cache, which is the office's own (CACHE_STORE in .env).
     * The tests otherwise run on an array, which would hide a key or a value
     * that the cache table cannot hold.
     *
     * @return array{0: int, 1: int, 2: bool} the last party lock, the look, and whether it was inside the save's transaction
     */
    private function lookedUp(string $once, callable $press): array
    {
        $base = DB::transactionLevel();
        $sent = [];

        DB::listen(function ($query) use (&$sent) {
            $sent[] = [$query->sql, $query->bindings, $query->connection->transactionLevel()];
        });

        $press();

        $sent = collect($sent);

        $locks = $sent->keys()->filter(fn ($i) => str_contains($sent[$i][0], '`party`') && str_contains($sent[$i][0], 'for update'));
        $look = $sent->search(fn ($query) => str_contains($query[0], '`cache`')
            && collect($query[1])->contains(fn ($binding) => str_ends_with((string) $binding, 'party-entry-once.'.$once)));

        $this->assertNotEmpty($locks, 'no party was locked');
        $this->assertNotFalse($look, 'the page was never looked up');

        return [$locks->max(), $look, $sent[$look][2] > $base];
    }

    /**
     * The two presses of a double click arrive together. Looked up before the
     * party's lock, both would find nothing and both would save; looked up
     * after it, inside the save's own transaction, the second waits there for
     * the first and finds what it wrote.
     */
    public function test_a_payment_looks_for_its_page_under_the_party_lock(): void
    {
        config(['cache.default' => 'database']);

        $this->owing($this->customer, 7500);
        $this->send($this->customer, 'page-lock')->assertSessionHasNoErrors();

        [$lock, $look, $inside] = $this->lookedUp('page-lock', fn () => $this->send($this->customer, 'page-lock')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved')));

        $this->assertGreaterThan($lock, $look, 'looked up before the party was locked');
        $this->assertTrue($inside, 'looked up outside the save\'s transaction');
        $this->assertCount(1, $this->typed($this->customer));
    }

    /** A set-off locks both accounts first, and looks after both. */
    public function test_a_set_off_looks_for_its_page_under_both_locks(): void
    {
        config(['cache.default' => 'database']);

        [$customer, $vendor] = $this->dealer();
        $this->setOff($vendor, $customer, 'page-lock-2')->assertSessionHasNoErrors();

        [$lock, $look, $inside] = $this->lookedUp('page-lock-2', fn () => $this->setOff($vendor, $customer, 'page-lock-2')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'already saved')));

        $this->assertGreaterThan($lock, $look, 'looked up before both accounts were locked');
        $this->assertTrue($inside, 'looked up outside the save\'s transaction');
        $this->assertSame(1, $this->setOffs($customer));
    }

    // ------------------------------------------------------- not a repeat

    /** The same payment typed again on a new page is a second payment, and is saved. */
    public function test_the_same_payment_typed_again_on_a_new_page_is_saved(): void
    {
        $this->owing($this->customer, 12000);

        $this->send($this->customer, 'page-g')->assertSessionHasNoErrors();
        $this->send($this->customer, 'page-h')->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => ! str_contains($said, 'already saved'));

        $this->assertCount(2, $this->typed($this->customer));
        $this->assertSame(2000.0, $this->balance($this->customer));
    }

    /**
     * A page come back to with Back, changed and saved again, is not the save
     * it made. Told it was already saved, the office would believe the new
     * figure was in. Refused, saying so, with what was typed put back on a new
     * page — where one more press saves it.
     */
    public function test_a_different_entry_from_a_page_that_saved_one_is_not_taken_for_it(): void
    {
        $this->owing($this->customer, 12000);

        $this->send($this->customer, 'page-i')->assertSessionHasNoErrors();
        $first = $this->typed($this->customer)->first();

        $this->send($this->customer, 'page-i', ['amount' => 3000])
            ->assertSessionHasErrors(['once' => 'This page already saved entry #'.$first->id
                .', so nothing was saved this time. What you typed is below: check it, and press Save Entry again to save it as a new entry.'])
            ->assertSessionHasInput('amount', 3000);

        $this->assertCount(1, $this->typed($this->customer));

        // The page it comes back on is a new page.
        $this->send($this->customer, 'page-j', ['amount' => 3000])->assertSessionHasNoErrors();

        $this->assertCount(2, $this->typed($this->customer));
        $this->assertSame(4000.0, $this->balance($this->customer));
    }

    /**
     * Only a save uses the page up. A press refused under the lock — here, more
     * written off than the bill is owed — wrote nothing, so the same page
     * corrected and pressed again is saved.
     */
    public function test_a_refused_press_does_not_use_the_page_up(): void
    {
        OfficeSettingModel::put(OfficeSettingModel::WRITEOFF_CAP, '500.00', $this->admin->id);

        $file = $this->file($this->customer, 5000);
        $this->send($this->customer, null, ['amount' => 4800, 'alloc' => $this->lines([$file->id => 4800])]);

        $writeOff = fn (float $amount) => [
            'amount' => $amount,
            'entry_kind' => 'writeoff',
            'reason' => 'Rounded off',
            'alloc' => $this->lines([$file->id => $amount]),
        ];

        $this->send($this->customer, 'page-k', $writeOff(300))->assertSessionHasErrors('alloc');
        $this->send($this->customer, 'page-k', $writeOff(200))->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($said) => ! str_contains($said, 'already saved'));

        $this->assertSame(0.0, $this->balance($this->customer));
    }

    /** A page opened before this came in sends no token, and is saved as it always was. */
    public function test_a_page_with_no_token_is_saved_as_before(): void
    {
        $this->owing($this->customer, 7500);

        $this->send($this->customer, null)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertCount(1, $this->typed($this->customer));
    }
}

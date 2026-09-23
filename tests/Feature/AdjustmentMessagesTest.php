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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The customer is told when their account changes, not only when they pay.
 *
 * A payment has always offered a receipt, and a set-off a message. Asked for
 * by the owner (2026-09-23): a difference written off, and an entry taken
 * back, change what a customer owes as much as a payment does, and offered
 * nothing. Each now offers a message on WhatsApp — pre-filled, never sent
 * from here — in the words the customer's statement already uses: Discount,
 * and which entry was reversed. Never why: that is the office's. A vendor is
 * never sent one.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class AdjustmentMessagesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Messages Admin';
        $this->admin->email = 'messages-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Rakesh Ji Madhubani');

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();

        OfficeSettingModel::put(OfficeSettingModel::WRITEOFF_CAP, '500.00', $this->admin->id);
    }

    private function party(string $type, string $name, ?string $mobile = null): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = $mobile ?? '93700'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function file(float $charge): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-AM-'.uniqid();
        $file->received_date = '2026-08-01';
        $file->registration_no = 'BR01AM'.random_int(1000, 9999);
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

    /** An entry as the Entry screen posts one. */
    private function enter(PartyModel $party, array $entry)
    {
        return $this->actingAs($this->admin)
            ->from(route('party.entry', $party->party_type))
            ->post(route('party.entry', $party->party_type), $entry + [
                'party_id' => $party->id,
                'entry_type' => $party->party_type === 'customer' ? 'credit' : 'debit',
                'txn_date' => '2026-09-20',
                'payment_mode' => 'UPI',
                'ref_no' => '412345678901',
                'particular' => 'Received, paid late again',
            ]);
    }

    private function latest(PartyModel $party): PartyLedgerModel
    {
        return PartyLedgerModel::where('party_id', $party->id)->latest('id')->first();
    }

    private function reverse(PartyLedgerModel $entry, bool $correct = false)
    {
        return $this->actingAs($this->admin)
            ->from(route('party.statement', $entry->party_id))
            ->post(route('party.reverse', $entry->id), ['reason' => 'Typed for the wrong customer', 'correct' => $correct ? '1' : '0']);
    }

    // ------------------------------------------------------------ write-off

    /** A difference written off: their statement says Discount, and so does the message. */
    public function test_a_write_off_offers_the_customer_a_message(): void
    {
        $file = $this->file(5000);
        $this->enter($this->customer, ['amount' => 4950, 'alloc' => [$file->id => ['work_file_id' => $file->id, 'amount' => 4950]]]);

        $this->enter($this->customer, [
            'amount' => 50,
            'entry_kind' => 'writeoff',
            'reason' => 'Rounded off, paid in full',
            'payment_mode' => '',
            'particular' => '',
            'ref_no' => '',
            'alloc' => [$file->id => ['work_file_id' => $file->id, 'amount' => 50]],
        ])->assertSessionHasNoErrors();

        $message = session('receipt');

        $this->assertSame('writeoff', $message['kind']);
        $this->assertSame($this->customer->name, $message['name']);
        $this->assertEquals(50, $message['amount']);
        $this->assertEquals(0, $message['balance']);
        $this->assertSame('', $message['mode']);
        $this->assertCount(1, $message['against']);
        $this->assertStringContainsString($file->registration_no, $message['against'][0]['label']);

        // Why it was given is the office's, and is not in it.
        $this->assertStringNotContainsString('Rounded off', json_encode($message));
    }

    // ------------------------------------------------------------ reversals

    /** A payment taken back: which one, their own reference to find it by, and where they stand now. */
    public function test_reversing_a_customers_payment_offers_them_a_message(): void
    {
        $this->file(7500);
        $this->enter($this->customer, ['amount' => 5000]);
        $payment = $this->latest($this->customer);

        $this->reverse($payment)->assertSessionHasNoErrors()->assertSessionHas('receipt', [
            'kind' => 'reversal',
            'name' => $this->customer->name,
            'mobile' => $this->customer->mobile,
            'amount' => 5000.0,
            'dateLabel' => '20-09-2026',
            'mode' => '',
            'reference' => '412345678901',
            'balance' => 7500.0,
            'todayLabel' => now()->format('d-m-Y'),
            'against' => [],
            'entryNo' => (int) $payment->id,
        ]);

        $this->assertStringNotContainsString('wrong customer', json_encode(session('receipt')));
    }

    /** Shown where the office lands — the statement — once, and handed the props the component takes. */
    public function test_the_statement_shows_it_after_reversing(): void
    {
        $this->file(7500);
        $this->enter($this->customer, ['amount' => 5000]);
        $this->reverse($this->latest($this->customer));

        $html = $this->actingAs($this->admin)->get(route('party.statement', $this->customer->id))->assertOk()->getContent();

        $this->assertTrue((bool) preg_match('#data-vue="vue-customer-receipt" data-props="(.*?)"#s', $html, $mount), 'no message on the statement');
        $passed = array_keys(json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true));

        $component = file_get_contents(resource_path('js/components/CustomerReceipt.vue'));
        preg_match('#defineProps\(\{(.*?)\n\}\);#s', $component, $block);
        preg_match_all('#^\s{4}(\w+):\s*\{([^}]*)\}#m', $block[1], $props, PREG_SET_ORDER);

        $declared = [];

        foreach ($props as $prop) {
            $declared[$prop[1]] = str_contains($prop[2], 'required: true');
        }

        $this->assertSame([], array_values(array_diff($passed, array_keys($declared))), 'passed but not declared');
        $this->assertSame([], array_values(array_diff(array_keys(array_filter($declared)), $passed)), 'required but not passed');

        // And not again on the next load.
        $again = $this->actingAs($this->admin)->get(route('party.statement', $this->customer->id))->getContent();
        $this->assertStringNotContainsString('vue-customer-receipt', $again);
    }

    /** Taken back to be entered again, it is offered on the Entry screen the office lands on. */
    public function test_correct_offers_it_too(): void
    {
        $this->file(7500);
        $this->enter($this->customer, ['amount' => 5000]);
        $payment = $this->latest($this->customer);

        $this->reverse($payment, true)->assertRedirect(route('party.entry', 'customer'))
            ->assertSessionHas('receipt.kind', 'reversal')
            ->assertSessionHas('receipt.entryNo', (int) $payment->id);
    }

    /** A vendor is never sent one. */
    public function test_a_vendor_is_never_sent_one(): void
    {
        $vendor = $this->party('vendor', 'Parwez Ji Works');
        $this->enter($vendor, ['amount' => 700, 'entry_type' => 'debit']);

        $this->reverse($this->latest($vendor))->assertSessionHasNoErrors()->assertSessionMissing('receipt');
    }

    /** A set-off taken back from the vendor's statement: the customer's half is the one they are told of. */
    public function test_a_set_off_reversed_from_the_vendors_side_tells_the_customer(): void
    {
        $vendor = $this->party('vendor', 'Rakesh Works');
        $this->customer->linked_vendor_id = $vendor->id;
        $this->customer->save();

        $this->file(5000);
        $vendorFile = new WorkFileModel;
        $vendorFile->file_no = 'F-AM-'.uniqid();
        $vendorFile->received_date = '2026-08-01';
        $vendorFile->registration_no = 'BR01AV'.random_int(1000, 9999);
        $vendorFile->work_type_id = $this->tr->id;
        $vendorFile->customer_id = $this->party('customer', 'Somebody Else')->id;
        $vendorFile->customer_amount = 4000;
        $vendorFile->vendor_id = $vendor->id;
        $vendorFile->vendor_amount = 3000;
        $vendorFile->vendor_date = '2026-08-02';
        $vendorFile->status = WorkFileModel::DISPATCHED;
        $vendorFile->save();
        $vendorFile->syncLedger();

        $this->enter($this->customer, [
            'amount' => 1000,
            'entry_kind' => 'setoff',
            'counterpart_id' => $vendor->id,
            'payment_mode' => '',
            'particular' => '',
            'ref_no' => '',
        ])->assertSessionHasNoErrors();

        $customerHalf = $this->latest($this->customer);
        $vendorHalf = $this->latest($vendor);

        $this->reverse($vendorHalf)->assertSessionHasNoErrors()
            ->assertSessionHas('receipt.kind', 'reversal')
            ->assertSessionHas('receipt.name', $this->customer->name)
            ->assertSessionHas('receipt.entryNo', (int) $customerHalf->id);
    }

    /** Their reference is typed by hand, and read for vendors before it goes, as their statement reads it. */
    public function test_a_reference_naming_a_vendor_is_read_for_vendors(): void
    {
        $vendor = $this->party('vendor', 'Shailendra Pandey Motihari', '94310'.random_int(10000, 99999));
        $this->file(7500);

        $this->enter($this->customer, ['amount' => 5000, 'ref_no' => 'via '.$vendor->name])
            ->assertSessionHas('receipt.reference', 'via …');

        $this->reverse($this->latest($this->customer))->assertSessionHas('receipt.reference', 'via …');
    }
}

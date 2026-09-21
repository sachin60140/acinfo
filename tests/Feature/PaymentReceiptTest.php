<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The WhatsApp receipt offered after a customer's payment is saved.
 *
 * The message is written in the browser and tested there
 * (customer-share.test.js). This is when it is offered, and what it is handed:
 * only for money actually received from a customer — never a charge, never a
 * correction booked as a credit, never a vendor — and with the balance as it
 * stands today, after the payment.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PaymentReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Receipt Admin';
        $this->admin->email = 'receipt-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
    }

    private function party(string $type, ?string $whatsapp = null): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' paying';
        $party->mobile = '93600'.random_int(10000, 99999);
        $party->whatsapp = $whatsapp;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function charge(PartyModel $party, float $amount): void
    {
        DB::table('party_ledger')->insert([
            'party_id' => $party->id,
            'txn_date' => '2026-09-01',
            'entry_type' => 'debit',
            'amount' => $amount,
            'particular' => 'Work charged',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The entry form's post, as the Save button makes it. */
    private function book(PartyModel $party, array $entry = [])
    {
        return $this->actingAs($this->admin)
            ->from(route('party.entry', $party->party_type))
            ->post(route('party.entry', $party->party_type), $entry + [
                'party_id' => $party->id,
                'entry_type' => 'credit',
                'txn_date' => '2026-09-21',
                'amount' => 5000,
                'payment_mode' => 'UPI',
                'ref_no' => '412345678901',
                'particular' => 'Received — paid late again',
            ]);
    }

    // ---------------------------------------------------------------- offered

    public function test_a_customer_payment_offers_a_receipt(): void
    {
        $this->charge($this->customer, 7500);

        $this->book($this->customer)->assertSessionHas('receipt', [
            'name' => $this->customer->name,
            'mobile' => $this->customer->mobile,
            'amount' => 5000.0,
            'dateLabel' => '21-09-2026',
            'mode' => 'UPI',
            'reference' => '412345678901',
            'balance' => 2500.0,
            'todayLabel' => now()->format('d-m-Y'),
        ]);
    }

    /** The office's own description of the entry is not handed over at all. */
    public function test_the_particular_is_never_handed_to_the_receipt(): void
    {
        $this->book($this->customer);

        $this->assertStringNotContainsString('paid late', json_encode(session('receipt')));
    }

    public function test_it_goes_to_a_saved_whatsapp_number(): void
    {
        $customer = $this->party('customer', '94310'.random_int(10000, 99999));

        $this->book($customer)->assertSessionHas('receipt.mobile', $customer->whatsapp);
    }

    /** A payment dated last week still reports what is owed now. */
    public function test_the_balance_is_todays_whatever_day_the_payment_is_dated(): void
    {
        $this->charge($this->customer, 7500);

        $this->book($this->customer, ['txn_date' => '2026-08-01'])
            ->assertSessionHas('receipt.balance', 2500.0)
            ->assertSessionHas('receipt.dateLabel', '01-08-2026');
    }

    /**
     * What the page hands the receipt is what the component takes.
     *
     * VueMountTest checks this for every screen, but only as each first
     * loads — and this mount appears only after a save, so it never sees it.
     * Checked the same way here: every prop passed is declared, every required
     * one is passed, and the key is registered to this component.
     */
    public function test_the_page_hands_the_component_exactly_the_props_it_declares(): void
    {
        $this->book($this->customer);

        $html = $this->actingAs($this->admin)->get(route('party.entry', 'customer'))->assertOk()->getContent();

        preg_match('#data-vue="vue-customer-receipt" data-props="(.*?)"#s', $html, $mount);
        $passed = array_keys(json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true));

        $this->assertMatchesRegularExpression(
            "#'vue-customer-receipt':\s*CustomerReceipt,#",
            file_get_contents(resource_path('js/mounts.js')),
            'the mount key is not registered to CustomerReceipt'
        );

        $component = file_get_contents(resource_path('js/components/CustomerReceipt.vue'));
        preg_match('#defineProps\(\{(.*?)\n\}\);#s', $component, $block);
        preg_match_all('#^\s{4}(\w+):\s*\{([^}]*)\}#m', $block[1], $props, PREG_SET_ORDER);

        $declared = [];

        foreach ($props as $prop) {
            $declared[$prop[1]] = str_contains($prop[2], 'required: true');
        }

        $this->assertSame([], array_values(array_diff($passed, array_keys($declared))), 'passed but not declared');
        $this->assertSame([], array_values(array_diff(array_keys(array_filter($declared)), $passed)), 'required but not passed');
    }

    public function test_the_page_after_saving_shows_it_once(): void
    {
        $this->book($this->customer);

        $this->actingAs($this->admin)->get(route('party.entry', 'customer'))
            ->assertOk()
            ->assertSee('data-vue="vue-customer-receipt"', false);

        // And not again on the next visit.
        $this->actingAs($this->admin)->get(route('party.entry', 'customer'))
            ->assertOk()
            ->assertDontSee('data-vue="vue-customer-receipt"', false);
    }

    // ------------------------------------------------------------ not offered

    public function test_a_charge_is_not_a_payment(): void
    {
        $this->book($this->customer, ['entry_type' => 'debit'])->assertSessionMissing('receipt');
    }

    /** Corrections booked as credits: no money changed hands, so no thanks for it. */
    public function test_a_credit_that_is_not_money_offers_nothing(): void
    {
        foreach (['Adjustment', 'Credit / Invoice', ''] as $mode) {
            $this->book($this->customer, ['payment_mode' => $mode])->assertSessionMissing('receipt');
        }
    }

    public function test_a_vendor_is_never_sent_one(): void
    {
        $this->book($this->party('vendor'))->assertSessionMissing('receipt');
    }

    public function test_every_money_mode_is_one_the_form_offers(): void
    {
        $this->assertSame([], array_diff(
            \App\Models\PartyLedgerModel::MONEY_MODES,
            \App\Models\PartyLedgerModel::PAYMENT_MODES
        ), 'a receipt mode the entry form can never post');
    }
}

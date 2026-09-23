<?php

namespace Tests\Feature;

use App\Http\Controllers\CloseClientLedgerController;
use App\Models\ClientModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The old Client Ledger, closed.
 *
 * The owner's decision (2026-09-23): money is recorded on the Customer/Vendor
 * Ledger's Entry screen only. The old book's Receipt, Payment and Add Client
 * say where to go instead and save nothing. What it still holds for or against
 * each client is carried to the customer who is that client, one line on each
 * side, and nothing is deleted. Its clients, statements and portal stay.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class CloseClientLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Old Book Admin';
        $this->admin->email = 'old-book-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        // Only this test's clients hold a balance.
        DB::table('client_ledger')->delete();
    }

    /** A client of the old book, and what it holds for them: positive held for them, negative owed. */
    private function client(string $name, float ...$amounts): ClientModel
    {
        $client = new ClientModel;
        $client->name = $name.' '.uniqid();
        $client->mobile = '92600'.random_int(10000, 99999);
        $client->password = Hash::make('password-for-tests');
        $client->address = 'Near the RTO, Motihari';
        $client->save();

        foreach ($amounts as $amount) {
            DB::table('client_ledger')->insert([
                'client_id' => $client->id,
                'payment_by' => '1',
                'amount' => $amount,
                'particular' => 'Old entry',
                'txn_date' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $client;
    }

    private function customer(string $name, ?string $mobile = null): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = $name.' '.uniqid();
        $party->mobile = $mobile ?? '92700'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function carry(ClientModel $client, string $customer)
    {
        return $this->actingAs($this->admin)
            ->from(route('client.closebook'))
            ->post(route('client.carry', $client->id), ['customer' => $customer]);
    }

    private function oldBalance(ClientModel $client): float
    {
        return round((float) DB::table('client_ledger')->where('client_id', $client->id)->sum('amount'), 2);
    }

    // ------------------------------------------------------ the closed screens

    /** Receipt, Payment and Add Client say where to go instead. */
    public function test_the_closed_screens_say_where_to_go_instead(): void
    {
        foreach ([
            'admin/receipt' => route('party.entry', 'customer'),
            'admin/payment' => route('party.entry', 'vendor'),
            'admin/add-clients' => route('party.create', 'customer'),
        ] as $url => $instead) {
            $page = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('The old Client Ledger is closed', $page, $url);
            $this->assertStringContainsString('href="'.$instead.'"', $page, $url);

            // And no form to type into.
            foreach (['vue-payment-receipt', 'vue-payment-form', 'vue-client-form'] as $form) {
                $this->assertStringNotContainsString('data-vue="'.$form.'"', $page, $url);
            }
        }
    }

    /** A post to one — a page left open, a bookmark — saves nothing and says so. */
    public function test_nothing_is_saved_there_now(): void
    {
        $client = $this->client('Old Client');
        $entries = DB::table('client_ledger')->count();
        $clients = DB::table('client')->count();

        $this->actingAs($this->admin)->from(url('admin/receipt'))->post(url('admin/receipt'), [
            'client_name' => $client->id,
            'paymentMode' => '1',
            'amount' => 500,
            'txn_date' => '2026-09-20',
            'remarks' => 'Received',
        ])->assertRedirect(url('admin/receipt'))->assertSessionHas('error');

        $this->actingAs($this->admin)->post(url('admin/payment'), ['client_name' => $client->id, 'amount' => 500])->assertSessionHas('error');

        $this->actingAs($this->admin)->post(url('admin/add-clients'), [
            'name' => 'New Client',
            'mobile_number' => '9260099999',
            'password' => 'password-for-tests',
            'password_confirmation' => 'password-for-tests',
            'address' => 'Somewhere',
        ])->assertSessionHas('error');

        $this->assertSame($entries, DB::table('client_ledger')->count());
        $this->assertSame($clients, DB::table('client')->count());
    }

    // ------------------------------------------------------ closing the book

    /** Every client with a balance, and none without one. */
    public function test_the_close_screen_lists_each_client_with_a_balance(): void
    {
        $owes = $this->client('Owes Client', -2000, 500);
        $held = $this->client('Held Client', 500);
        $clear = $this->client('Clear Client', -300, 300);

        $page = $this->actingAs($this->admin)->get(route('client.closebook'))->assertOk()->getContent();

        $this->assertStringContainsString($owes->name, $page);
        $this->assertStringContainsString('1,500.00 Dr', $page);
        $this->assertStringContainsString($held->name, $page);
        $this->assertStringContainsString('500.00 Cr', $page);
        $this->assertStringNotContainsString($clear->name, $page);
    }

    /** A customer with the client's own mobile is offered first, and not made again. */
    public function test_a_customer_with_the_clients_mobile_is_offered_first(): void
    {
        $client = $this->client('Same Mobile', -1000);
        $same = $this->customer('Same Mobile Customer', $client->mobile);

        $page = $this->actingAs($this->admin)->get(route('client.closebook'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="'.$same->id.'"\s+selected#', $page);
        $this->assertStringNotContainsString('New customer: '.$client->name, $page);
    }

    /**
     * An inactive customer with the client's mobile is offered too. Found in
     * checking it: they were not on the list, and a new customer could not be
     * made with their mobile, so the client could never be carried over.
     */
    public function test_an_inactive_customer_with_the_clients_mobile_is_offered(): void
    {
        $client = $this->client('Inactive Match', -1000);
        $asleep = $this->customer('Inactive Customer', $client->mobile);
        $asleep->is_active = 0;
        $asleep->save();

        $page = $this->actingAs($this->admin)->get(route('client.closebook'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="'.$asleep->id.'"\s+selected#', $page);
        $this->assertStringContainsString($asleep->name.' (inactive)', $page);

        $this->carry($client, (string) $asleep->id)->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->oldBalance($client));
    }

    /** What a client owes carries to their customer as a debit, and the old book comes to nothing. */
    public function test_what_a_client_owes_carries_as_a_debit(): void
    {
        $client = $this->client('Owes Client', -2000, 500);
        $customer = $this->customer('Owes Customer');

        $this->carry($client, (string) $customer->id)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(0.0, $this->oldBalance($client));

        $closing = DB::table('client_ledger')->where('client_id', $client->id)->latest('id')->first();
        $this->assertSame(CloseClientLedgerController::CARRIED, $closing->particular);
        $this->assertEquals(1500, $closing->amount);
        $this->assertSame(now()->toDateString(), date('Y-m-d', strtotime($closing->txn_date)));

        $line = PartyLedgerModel::where('party_id', $customer->id)->latest('id')->first();
        $this->assertSame('debit', $line->entry_type);
        $this->assertEquals(1500, $line->amount);
        $this->assertSame(CloseClientLedgerController::BROUGHT, $line->particular);
        $this->assertStringContainsString('client #'.$client->id, $line->note);
        $this->assertSame($this->admin->id, (int) $line->created_by);
        $this->assertEqualsWithDelta(1500, PartyLedgerModel::currentBalance($customer->id), 0.001);
    }

    /** Money the old book held for a client carries as a credit: in advance on their account. */
    public function test_money_held_for_a_client_carries_as_a_credit(): void
    {
        $client = $this->client('Held Client', 500);
        $customer = $this->customer('Held Customer');

        $this->carry($client, (string) $customer->id)->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->oldBalance($client));
        $this->assertSame('credit', PartyLedgerModel::where('party_id', $customer->id)->latest('id')->value('entry_type'));
        $this->assertEqualsWithDelta(-500, PartyLedgerModel::currentBalance($customer->id), 0.001);
    }

    /** No customer yet: one is made from the client, their name, mobile and address. */
    public function test_a_customer_can_be_made_from_the_client(): void
    {
        $client = $this->client('Brand New', -800);

        $this->carry($client, 'new')->assertSessionHasNoErrors();

        $made = PartyModel::where('party_type', 'customer')->where('mobile', $client->mobile)->first();
        $this->assertNotNull($made);
        $this->assertSame($client->name, $made->name);
        $this->assertSame($client->address, $made->address);
        $this->assertEqualsWithDelta(800, PartyLedgerModel::currentBalance($made->id), 0.001);
    }

    /** Not where a customer has that mobile already: that one is picked instead. */
    public function test_a_customer_is_not_made_twice(): void
    {
        $client = $this->client('Already There', -800);
        $this->customer('Already There Customer', $client->mobile);

        $this->carry($client, 'new')->assertSessionHasErrors('customer');

        $this->assertSame(-800.0, $this->oldBalance($client));
        $this->assertSame(1, PartyModel::where('party_type', 'customer')->where('mobile', $client->mobile)->count());
    }

    /** Carried once. A second go — pressed twice, a page left open — finds nothing left. */
    public function test_a_balance_is_carried_only_once(): void
    {
        $client = $this->client('Twice Client', -1000);
        $customer = $this->customer('Twice Customer');

        $this->carry($client, (string) $customer->id)->assertSessionHasNoErrors();
        $this->carry($client, (string) $customer->id)->assertSessionHasErrors('customer');

        $this->assertSame(2, DB::table('client_ledger')->where('client_id', $client->id)->count());
        $this->assertSame(1, PartyLedgerModel::where('party_id', $customer->id)->count());
    }

    /** Only to a customer. */
    public function test_only_a_customer_can_take_it(): void
    {
        $client = $this->client('Vendor Pick', -1000);

        $vendor = new PartyModel;
        $vendor->party_type = 'vendor';
        $vendor->name = 'A Vendor '.uniqid();
        $vendor->mobile = '92800'.random_int(10000, 99999);
        $vendor->is_active = 1;
        $vendor->save();

        $this->carry($client, (string) $vendor->id)->assertSessionHasErrors('customer');

        $this->assertSame(-1000.0, $this->oldBalance($client));
        $this->assertSame(0, PartyLedgerModel::where('party_id', $vendor->id)->count());
    }

    // ------------------------------------------------------------- around it

    /** The dashboard says how many are left, and nothing once none are. */
    public function test_the_dashboard_says_what_is_left_to_carry(): void
    {
        $client = $this->client('Dashboard Client', -1000);
        $this->client('Dashboard Client Two', 250);

        $tiles = collect($this->actingAs($this->admin)->getJson('/admin/dashboard')->assertOk()->json('props.tiles'))->keyBy('label');
        $this->assertSame(2, $tiles['Left in Old Book']['value']);
        $this->assertSame(route('client.closebook'), $tiles['Left in Old Book']['href']);
        $this->assertFalse($tiles->has('Net Movement'), 'the old book no longer moves');

        DB::table('client_ledger')->delete();

        $tiles = collect($this->actingAs($this->admin)->getJson('/admin/dashboard')->assertOk()->json('props.tiles'))->keyBy('label');
        $this->assertFalse($tiles->has('Left in Old Book'));
    }

    /** The old portal stays: the client sees the book close, and nothing owed in it. */
    public function test_the_old_portal_shows_the_book_closed(): void
    {
        $client = $this->client('Portal Client', -1500);
        $this->carry($client, (string) $this->customer('Portal Customer')->id)->assertSessionHasNoErrors();

        $page = $this->withSession(['userid' => $client->id])->getJson(route('userstatement'))->assertOk();
        $rows = collect($page->json('props.rows'));

        $this->assertSame(CloseClientLedgerController::CARRIED, $rows->last()['particular']);
        $this->assertEqualsWithDelta(0, $rows->last()['balance'], 0.001);
    }
}

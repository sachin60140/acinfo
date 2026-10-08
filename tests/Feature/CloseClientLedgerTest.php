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

    /**
     * On a phone, a card a client rather than a table 777px wide to scroll
     * sideways (mobile audit, 2026-09-28): the rows say what each cell is,
     * which the cards print beside it, and the picker is not held to its
     * narrowest by w-auto, which would outrank the card's full width.
     */
    public function test_the_close_screen_stacks_on_a_phone(): void
    {
        $this->client('Stacked Client', -900);

        $page = $this->actingAs($this->admin)->get(route('client.closebook'))->assertOk()->getContent();

        $this->assertStringContainsString('class="table align-middle mb-0 cb-table"', $page);

        foreach (['Client', 'Mobile', 'Balance'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $page);
        }

        $this->assertStringContainsString('form-select form-select-sm cb-pick', $page);
        $this->assertStringNotContainsString('w-auto', $page);
        $this->assertMatchesRegularExpression('/@media \(max-width: 767\.98px\)\s*\{\s*\.cb-table thead\s*\{\s*display: none;/', $page);

        // The header hidden alone leaves a table as wide as before, headerless
        // (found in review): the rows have to become blocks, each value labelled.
        $this->assertMatchesRegularExpression('/\.cb-table,\s*\.cb-table tbody,\s*\.cb-table tr,\s*\.cb-table td\s*\{\s*display: block;/', $page);
        $this->assertMatchesRegularExpression('/\.cb-table td\[data-label\]::before\s*\{[^}]*content: attr\(data-label\)/', $page);
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

    // ------------------------------------------------ taking a carried line back

    /** The customer's "brought from" line, as the carry wrote it. */
    private function brought(PartyModel $customer): PartyLedgerModel
    {
        return PartyLedgerModel::where('party_id', $customer->id)
            ->where('particular', CloseClientLedgerController::BROUGHT)
            ->latest('id')
            ->firstOrFail();
    }

    private function takeBack(PartyLedgerModel $line, bool $correct)
    {
        return $this->actingAs($this->admin)
            ->from(route('party.statement', $line->party_id))
            ->post(route('party.reverse', $line->id), ['reason' => 'Carried to the wrong customer', 'correct' => $correct ? '1' : '0']);
    }

    private function collectionRow(PartyModel $customer): ?array
    {
        return collect($this->actingAs($this->admin)->getJson(route('report.collection'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $customer->id);
    }

    /**
     * Reversed on its own, a carried balance is in neither book: the old book
     * still says it was carried, so the close-book screen no longer offers the
     * client, and the customer's statement no longer holds it. Found in the
     * health check (2026-10): the 2,500 was gone from both, and a second carry
     * was refused as "carried over already". Only Correct takes it back.
     */
    public function test_a_carried_balance_is_not_reversed_on_its_own(): void
    {
        $client = $this->client('Reverse Client', -2500);
        $customer = $this->customer('Reverse Customer');
        $this->carry($client, (string) $customer->id)->assertSessionHasNoErrors();
        $line = $this->brought($customer);

        $this->takeBack($line, false)
            ->assertRedirect(route('party.statement', $customer->id))
            ->assertSessionHasErrors('reason');

        $this->assertStringContainsString('Reverse and enter it again', session('errors')->first('reason'));
        // Found in review: the Entry screen picks only a customer already on
        // the list, and nothing said to add the right one before pressing it.
        $this->assertStringContainsString('not on the Customers list yet, add them first', session('errors')->first('reason'));
        $this->assertFalse(PartyLedgerModel::where('reverses_id', $line->id)->exists());
        $this->assertEqualsWithDelta(2500, PartyLedgerModel::currentBalance($customer->id), 0.001);
        $this->assertSame(0.0, $this->oldBalance($client), 'the old book was changed');
    }

    /**
     * Carried to the wrong customer, and put right with Correct: entered again
     * for the right one, it still says which client it came from, so the
     * Collection List dates it from the old book's own charges, not the day it
     * was carried. Found in the health check: the note was left behind, and the
     * debt read as owed since the carry day.
     */
    public function test_correct_enters_a_carried_balance_again_with_the_client_it_came_from(): void
    {
        $client = $this->client('Wrong Customer Client', -2500);
        $wrong = $this->customer('Wrong Customer');
        $right = $this->customer('Right Customer');
        $this->carry($client, (string) $wrong->id)->assertSessionHasNoErrors();
        $line = $this->brought($wrong);

        $this->takeBack($line, true)
            ->assertRedirect(route('party.entry', ['type' => 'customer', 'corrects' => $line->id]))
            ->assertSessionHasNoErrors();

        $initial = $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props.initial');

        $this->assertSame(CloseClientLedgerController::BROUGHT, $initial['particular']);

        // Typed again as the screen posts it, for the right customer.
        $this->actingAs($this->admin)->post(route('party.entry', 'customer'), [
            'party_id' => $right->id,
            'entry_type' => $initial['entry_type'],
            'txn_date' => now()->toDateString(),
            'amount' => $initial['amount'],
            'payment_mode' => '',
            'ref_no' => '',
            'particular' => $initial['particular'],
            'corrects' => $initial['corrects'] ?? '',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $again = $this->brought($right);
        $this->assertSame($line->note, $again->note);
        $this->assertStringContainsString('client #'.$client->id, (string) $again->note);

        $row = $this->collectionRow($right);
        $this->assertSame('01-05-2026', $row['since'], 'dated from the day it was carried, not from the old book');
        $this->assertSame('2,500.00 from the old Client Ledger', $row['loose_note']);
        $this->assertNull($this->collectionRow($wrong), 'the wrong customer still owes it');
        $this->assertSame(0.0, $this->oldBalance($client));

        // Which line it was, for the screen to post back.
        $this->assertSame((string) $line->id, $initial['corrects'] ?? null);
    }

    /**
     * Which client it came from is read from the line taken back, never from
     * the form: a line still standing lends nothing, nor does one entered again
     * under other Particulars, or on a vendor's account, where the Collection
     * List would not look for it.
     */
    public function test_only_a_carried_line_taken_back_lends_its_client(): void
    {
        $client = $this->client('Standing Client', -900);
        $standing = $this->customer('Standing Customer');
        $other = $this->customer('Other Customer');
        $this->carry($client, (string) $standing->id)->assertSessionHasNoErrors();
        $line = $this->brought($standing);

        $vendor = new PartyModel;
        $vendor->party_type = 'vendor';
        $vendor->name = 'Carried Vendor '.uniqid();
        $vendor->mobile = '92800'.random_int(10000, 99999);
        $vendor->is_active = 1;
        $vendor->save();

        $post = fn (PartyModel $party, string $particular, string $corrects) => $this->actingAs($this->admin)
            ->from(route('party.entry', $party->party_type))
            ->post(route('party.entry', $party->party_type), [
                'party_id' => $party->id,
                'entry_type' => $party->party_type === 'customer' ? 'debit' : 'credit',
                'txn_date' => now()->toDateString(),
                'amount' => '900',
                'particular' => $particular,
                'corrects' => $corrects,
            ]);

        $post($other, CloseClientLedgerController::BROUGHT, (string) $line->id)->assertSessionHasNoErrors();
        $this->assertNull($this->brought($other)->note, 'a line still standing lent its client');

        $this->takeBack($line, true)->assertSessionHasNoErrors();

        $post($other, 'Old balance', (string) $line->id)->assertSessionHasNoErrors();
        $this->assertNull(PartyLedgerModel::where('party_id', $other->id)->latest('id')->value('note'), 'lent to a line that does not say where it came from');

        $post($vendor, CloseClientLedgerController::BROUGHT, (string) $line->id)->assertSessionHasNoErrors();
        $this->assertNull(PartyLedgerModel::where('party_id', $vendor->id)->latest('id')->value('note'), 'lent to a vendor');

        // Only a line's number is ever posted.
        $post($other, CloseClientLedgerController::BROUGHT, 'client #'.$client->id)->assertSessionHasErrors('corrects');
    }

    /**
     * Carried to the wrong customer because the right one had not been made:
     * taken back with Correct, and the Entry screen left to add them, or
     * reloaded. Found in review: what Correct filled in lasted one page, so
     * which old client it came from was lost on the way back, and the debt
     * read as owed since the day it was typed again. Correct opens the screen
     * at the carried line's own address, and there it is filled in again from
     * the line — until it has been entered, and only once.
     */
    public function test_a_carried_balance_taken_back_outlasts_leaving_the_entry_screen(): void
    {
        $client = $this->client('Left Screen Client', -2500);
        $wrong = $this->customer('Left Screen Wrong');
        $this->carry($client, (string) $wrong->id)->assertSessionHasNoErrors();
        $line = $this->brought($wrong);
        $address = route('party.entry', ['type' => 'customer', 'corrects' => $line->id]);

        $this->takeBack($line, true)->assertRedirect($address);

        // Away to add the right customer: what Correct filled in is spent.
        $this->actingAs($this->admin)->get(route('party.create', 'customer'))->assertOk();
        $right = $this->customer('Left Screen Right');
        $this->assertSame('', $this->actingAs($this->admin)->getJson(route('party.entry', 'customer'))->json('props.initial.corrects'));

        // Back at its address, it is filled in again as Correct filled it.
        $initial = $this->actingAs($this->admin)->getJson($address)->assertOk()->json('props.initial');

        $this->assertSame((string) $line->id, $initial['corrects']);
        $this->assertSame(CloseClientLedgerController::BROUGHT, $initial['particular']);
        $this->assertSame('2500', $initial['amount']);
        $this->assertSame('debit', $initial['entry_type']);
        $this->assertSame((string) $wrong->id, $initial['party_id']);

        $this->actingAs($this->admin)->from($address)->post(route('party.entry', 'customer'), [
            'party_id' => $right->id,
            'entry_type' => $initial['entry_type'],
            'txn_date' => now()->toDateString(),
            'amount' => $initial['amount'],
            'particular' => $initial['particular'],
            'corrects' => $initial['corrects'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('party.entry', 'customer'));

        $this->assertSame($line->note, $this->brought($right)->note);
        $this->assertSame('01-05-2026', $this->collectionRow($right)['since'], 'dated from the day it was typed again');

        // Entered: its address fills in nothing more, and lends its client to no other line.
        $initial = $this->actingAs($this->admin)->getJson($address)->json('props.initial');

        $this->assertSame('', $initial['corrects']);
        $this->assertSame('', $initial['particular']);
        $this->assertSame('', $initial['amount']);

        $other = $this->customer('Left Screen Other');

        $this->actingAs($this->admin)->from($address)->post(route('party.entry', 'customer'), [
            'party_id' => $other->id,
            'entry_type' => 'debit',
            'txn_date' => now()->toDateString(),
            'amount' => '2500',
            'particular' => CloseClientLedgerController::BROUGHT,
            'corrects' => (string) $line->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->brought($other)->note, 'lent its client a second time');
    }

    /**
     * Saved from a carried balance's address under other Particulars, it was
     * not entered again as carried, and the address would fill it in again:
     * the screen comes back without it, or it could be saved twice. A line not
     * carried, or not taken back, fills in nothing there.
     */
    public function test_the_entry_screen_does_not_come_back_filled_in_with_it(): void
    {
        $client = $this->client('Other Words Client', -1200);
        $customer = $this->customer('Other Words Customer');
        $this->carry($client, (string) $customer->id)->assertSessionHasNoErrors();
        $line = $this->brought($customer);
        $address = route('party.entry', ['type' => 'customer', 'corrects' => $line->id]);

        // Still standing: nothing to enter again.
        $this->assertSame('', $this->actingAs($this->admin)->getJson($address)->json('props.initial.corrects'));

        $this->takeBack($line, true)->assertRedirect($address);

        $this->actingAs($this->admin)->from($address)->post(route('party.entry', 'customer'), [
            'party_id' => $customer->id,
            'entry_type' => 'debit',
            'txn_date' => now()->toDateString(),
            'amount' => '1200',
            'particular' => 'Old balance',
            'corrects' => (string) $line->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('party.entry', 'customer'));

        // An ordinary line's address is no carried balance's.
        $typed = PartyLedgerModel::where('party_id', $customer->id)->where('particular', 'Old balance')->value('id');
        $this->assertSame('', $this->actingAs($this->admin)->getJson(route('party.entry', ['type' => 'customer', 'corrects' => $typed]))
            ->json('props.initial.corrects'));

        // Any other save there still goes back where it came from.
        $this->actingAs($this->admin)->from(route('party.entry', ['type' => 'customer', 'party_id' => $customer->id]))
            ->post(route('party.entry', 'customer'), [
                'party_id' => $customer->id,
                'entry_type' => 'debit',
                'txn_date' => now()->toDateString(),
                'amount' => '10',
                'particular' => 'Typed by hand',
            ])->assertRedirect(route('party.entry', ['type' => 'customer', 'party_id' => $customer->id]));
    }

    /** The statement's Change dialog is told the line was carried, and says so before anything is pressed. */
    public function test_the_statement_marks_a_carried_line(): void
    {
        $client = $this->client('Statement Client', -700);
        $customer = $this->customer('Statement Customer');
        $this->carry($client, (string) $customer->id)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('party.entry', 'customer'), [
            'party_id' => $customer->id,
            'entry_type' => 'debit',
            'txn_date' => now()->toDateString(),
            'amount' => '40',
            'particular' => 'Typed by hand',
        ])->assertSessionHasNoErrors();

        $rows = collect($this->actingAs($this->admin)->getJson(route('party.statement', $customer->id))->assertOk()->json('props.rows'))->keyBy('id');
        $carried = $this->brought($customer)->id;
        $typed = PartyLedgerModel::where('party_id', $customer->id)->where('particular', 'Typed by hand')->value('id');

        $this->assertSame('Change', $rows[$carried]['change']);
        $this->assertTrue($rows[$carried]['carried'] ?? null);
        $this->assertFalse($rows[$typed]['carried'] ?? null);
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

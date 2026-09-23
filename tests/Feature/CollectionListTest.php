<?php

namespace Tests\Feature;

use App\Http\Controllers\CloseClientLedgerController;
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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Collection List: every customer who owes, the longest-owed first.
 *
 * One row a customer, owing what their statement says — not just finished
 * files, as Not Yet Collected lists — and dated from the oldest charge still
 * unpaid once their payments have settled what they were adjusted against and
 * then the oldest charges first.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class CollectionListTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private WorkTypeModel $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Collection List Admin';
        $this->admin->email = 'collection-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->type = new WorkTypeModel;
        $this->type->name = 'Collection Work '.uniqid();
        $this->type->is_active = 1;
        $this->type->save();
    }

    private function party(string $type = 'customer'): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' on the list';
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A file charged to $customer, its papers in $daysAgo days ago. */
    private function file(PartyModel $customer, float $charged, int $daysAgo, string $status = WorkFileModel::APPROVED): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-CL-'.uniqid();
        $file->received_date = now()->subDays($daysAgo)->toDateString();
        $file->registration_no = 'BR01CL'.random_int(1000, 9999);
        $file->work_type_id = $this->type->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = $charged;
        $file->status = $status;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->type->id;
        $item->customer_amount = $charged;
        $item->status = $status;
        $item->approved_on = $status === WorkFileModel::APPROVED ? now()->subDay()->toDateString() : null;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /** A line typed on the ledger, as the Entry screen writes one. */
    private function entry(PartyModel $party, string $side, float $amount, int $daysAgo, string $particular = 'Typed by hand'): void
    {
        DB::table('party_ledger')->insert([
            'party_id' => $party->id,
            'txn_date' => now()->subDays($daysAgo)->toDateString(),
            'entry_type' => $side,
            'amount' => $amount,
            'payment_mode' => $side === 'credit' ? 'cash' : null,
            'particular' => $particular,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A payment through the Entry screen, adjusted against files if asked. */
    private function pay(PartyModel $customer, float $amount, array $against = [], array $extra = [])
    {
        $alloc = [];

        foreach ($against as $fileId => $share) {
            $alloc[$fileId] = ['work_file_id' => $fileId, 'amount' => $share];
        }

        return $this->actingAs($this->admin)
            ->from(route('party.entry', 'customer'))
            ->post(route('party.entry', 'customer'), $extra + [
                'party_id' => $customer->id,
                'entry_type' => 'credit',
                'txn_date' => now()->toDateString(),
                'amount' => $amount,
                'payment_mode' => 'UPI',
                'particular' => 'Payment',
                'alloc' => $alloc,
            ])->assertSessionHasNoErrors();
    }

    private function page()
    {
        return $this->actingAs($this->admin)->getJson(route('report.collection'))->assertOk();
    }

    private function row(PartyModel $customer): ?array
    {
        return collect($this->page()->json('props.rows'))->firstWhere('id', $customer->id);
    }

    public function test_it_lists_a_customer_owing_only_for_work_still_being_done(): void
    {
        $customer = $this->party();
        $this->file($customer, 1200, 5, WorkFileModel::IN_OFFICE);

        $row = $this->row($customer);

        $this->assertNotNull($row, 'owed on the statement, so on the list — finished or not');
        $this->assertSame(1200.0, (float) $row['owes']);
        $this->assertSame(0.0, (float) $row['finished'], 'none of it for finished work');
        $this->assertSame(1, $row['files']);
        $this->assertSame(route('report.uncollected', ['party_id' => $customer->id, 'show' => 'all']), $row['files_url']);
    }

    public function test_it_lists_a_customer_owing_only_an_opening_balance(): void
    {
        $customer = $this->party();
        $this->entry($customer, 'debit', 800, 60, 'Opening Balance');

        $row = $this->row($customer);

        $this->assertNotNull($row);
        $this->assertSame(800.0, (float) $row['owes']);
        $this->assertSame(0, $row['files']);
        $this->assertNull($row['files_url'], 'no files to open');
        $this->assertSame('800.00 not on a file', $row['loose_note']);
        $this->assertSame(now()->subDays(60)->format('d-m-Y'), $row['since']);
        $this->assertSame('60 days', $row['days_text']);
    }

    public function test_it_leaves_out_customers_who_are_settled_or_in_advance(): void
    {
        $settled = $this->party();
        $this->file($settled, 500, 10);
        $this->entry($settled, 'credit', 500, 2);

        $ahead = $this->party();
        $this->entry($ahead, 'credit', 300, 2);

        $rows = collect($this->page()->json('props.rows'));

        $this->assertNull($rows->firstWhere('id', $settled->id));
        $this->assertNull($rows->firstWhere('id', $ahead->id));
    }

    public function test_money_received_settles_the_oldest_charge_and_moves_since_on(): void
    {
        $customer = $this->party();
        $this->file($customer, 1000, 40);
        $this->file($customer, 600, 20);

        $this->assertSame(now()->subDays(40)->format('d-m-Y'), $this->row($customer)['since']);

        // Not adjusted against anything: it clears the older file.
        $this->entry($customer, 'credit', 1000, 1);

        $row = $this->row($customer);
        $this->assertSame(600.0, (float) $row['owes']);
        $this->assertSame(now()->subDays(20)->format('d-m-Y'), $row['since'], 'owed since the next charge, not the first ever');
        $this->assertSame(1, $row['files']);
    }

    /**
     * A file can carry a second charge, booked later. Once its first is paid
     * the file is owed since the second — and a bill typed in between is
     * then the older debt, whatever order the file first came in.
     */
    public function test_since_is_the_oldest_charge_unpaid_not_the_oldest_bill(): void
    {
        $customer = $this->party();
        $file = $this->file($customer, 1000, 40);
        $this->entry($customer, 'debit', 300, 30);

        DB::table('party_ledger')->insert([
            'party_id' => $customer->id,
            'work_file_id' => $file->id,
            'txn_date' => now()->subDays(10)->toDateString(),
            'entry_type' => 'debit',
            'amount' => 200,
            'particular' => 'Extra charge on the file',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entry($customer, 'credit', 1000, 1);

        $row = $this->row($customer);

        $this->assertSame(500.0, (float) $row['owes']);
        $this->assertSame(now()->subDays(30)->format('d-m-Y'), $row['since'], 'the 300 from 30 days ago, not the file\'s paid first charge nor its 200 from 10');

        $bills = PartyLedgerModel::dueBills([$customer->id])[$customer->id];
        $this->assertSame([now()->subDays(30)->toDateString(), now()->subDays(10)->toDateString()], array_column($bills, 'since'));
    }

    public function test_a_payment_adjusted_against_the_newer_file_leaves_the_older_one_owed(): void
    {
        $customer = $this->party();
        $old = $this->file($customer, 600, 40);
        $new = $this->file($customer, 1000, 20);

        // Exactly the older file's amount: not adjusted, it would clear that.
        $this->pay($customer, 600, [$new->id => 600]);

        $row = $this->row($customer);
        $this->assertSame(1000.0, (float) $row['owes']);
        $this->assertSame(now()->subDays(40)->format('d-m-Y'), $row['since'], 'the older file is still owed in full');
        $this->assertSame(2, $row['files']);

        $bills = PartyLedgerModel::dueBills([$customer->id])[$customer->id];
        $this->assertSame([$old->id => 600.0, $new->id => 400.0], array_column($bills, 'due', 'file_id'));
    }

    /**
     * Carried to Customers today, owed since the old book says. The carrying
     * line is dated the day it was carried so a statement already sent does
     * not change — which alone would put the oldest debts at the bottom.
     */
    public function test_a_balance_from_the_old_book_is_owed_since_the_old_book_says(): void
    {
        $client = DB::table('client')->insertGetId([
            'name' => 'Old Debt Client '.uniqid(),
            'mobile' => '93802'.random_int(10000, 99999),
            'password' => Hash::make('password-for-tests'),
            'address' => 'Near the RTO, Motihari',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[400, -3000], [300, 1000], [200, -2000]] as [$daysAgo, $amount]) {
            DB::table('client_ledger')->insert([
                'client_id' => $client,
                'payment_by' => '1',
                'amount' => $amount,
                'particular' => 'Old book',
                'txn_date' => now()->subDays($daysAgo)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->admin)
            ->from(route('client.closebook'))
            ->post(route('client.carry', $client), ['customer' => 'new'])
            ->assertSessionHasNoErrors();

        $customer = PartyModel::where('party_type', 'customer')->latest('id')->first();

        $recent = $this->party();
        $this->file($recent, 900, 7);

        $rows = collect($this->page()->json('props.rows'));
        $row = $rows->firstWhere('id', $customer->id);

        // 3,000 then 2,000 charged, 1,000 paid: the first charge is part unpaid.
        $this->assertSame(4000.0, (float) $row['owes']);
        $this->assertSame(now()->subDays(400)->format('d-m-Y'), $row['since']);
        $this->assertSame('400 days', $row['days_text']);
        $this->assertLessThan($rows->search(fn ($r) => $r['id'] === $recent->id), $rows->search(fn ($r) => $r['id'] === $customer->id));

        // Paying 2,500 off it since finishes the first charge: owed since the second.
        $this->entry($customer, 'credit', 2500, 0);

        $this->assertSame(now()->subDays(200)->format('d-m-Y'), $this->row($customer)['since']);
    }

    public function test_a_charge_dated_ahead_says_so_rather_than_today(): void
    {
        $customer = $this->party();
        $this->entry($customer, 'debit', 350, -7);

        $row = $this->row($customer);

        $this->assertSame(now()->addDays(7)->format('d-m-Y'), $row['since']);
        $this->assertSame('dated ahead', $row['days_text']);
        $this->assertSame(0, $row['days']);
    }

    public function test_remind_says_which_chat_it_opens(): void
    {
        $mobile = $this->party();
        $mobile->mobile = '9835230001';
        $mobile->save();
        $this->file($mobile, 500, 5);

        // The mobile column shows a mobile; the WhatsApp number is a landline.
        $landline = $this->party();
        $landline->mobile = '9835230002';
        $landline->whatsapp = '0612222333';
        $landline->save();
        $this->file($landline, 500, 5);

        $page = $this->page();
        $rows = collect($page->json('props.rows'));

        $this->assertSame('on +91 98352 30001', $rows->firstWhere('id', $mobile->id)['remind_note']);
        $this->assertStringContainsString('Nothing is sent until you press Send', $rows->firstWhere('id', $mobile->id)['remind_title']);
        $this->assertSame('no WhatsApp number — you pick the chat', $rows->firstWhere('id', $landline->id)['remind_note']);

        $remind = collect($page->json('props.columns'))->firstWhere('key', 'remind');
        $this->assertSame('remind_note', $remind['sub']);
        $this->assertSame('remind_title', $remind['titleFrom']);
    }

    public function test_it_says_the_order_it_arrives_in(): void
    {
        $this->assertSame('since', $this->page()->json('props.sortedBy'));
    }

    /**
     * Owing Longest, on the dashboard beside the tile that opens this list,
     * names the list's first customer and the same day. A customer since long
     * ago who paid everything and owes for last week has owed for a week.
     */
    public function test_owing_longest_is_the_lists_first_row(): void
    {
        $probe = $this->party();
        $this->entry($probe, 'debit', 1000, 17000);
        $this->entry($probe, 'credit', 1000, 16999);
        $this->entry($probe, 'debit', 500, 5);

        $page = $this->page();
        $first = $page->json('props.rows.0');

        $tile = collect($this->actingAs($this->admin)->getJson('admin/dashboard')->json('props.tiles'))
            ->firstWhere('label', 'Owing Longest');

        $this->assertSame(route('party.statement', $first['id']), $tile['href']);
        $this->assertStringContainsString('since '.$first['since'], $tile['note']);
        $this->assertSame($page->json('page.totals.oldest'), $first['days']);

        $oldest = PartyModel::oldestUnpaid();
        $this->assertSame($first['id'], $oldest['id']);
        $this->assertLessThan(17000, $oldest['days'], 'not dated from a charge paid long ago');
        $this->assertSame(5, $this->row($probe)['days']);
    }

    public function test_the_longest_owed_comes_first(): void
    {
        $recent = $this->party();
        $this->file($recent, 5000, 3);

        $older = $this->party();
        $this->file($older, 200, 90);

        $ids = collect($this->page()->json('props.rows'))->pluck('id');

        $this->assertLessThan($ids->search($recent->id), $ids->search($older->id), '200 from three months ago before 5,000 from last week');
    }

    public function test_it_owes_what_the_statement_says(): void
    {
        $customer = $this->party();
        $this->file($customer, 1000, 30);
        $this->file($customer, 450, 10, WorkFileModel::IN_OFFICE);
        $this->entry($customer, 'debit', 75, 5);
        $this->entry($customer, 'credit', 300, 2);

        $row = $this->row($customer);
        $balance = PartyLedgerModel::balancesFor([$customer->id])[$customer->id];

        $this->assertSame(round((float) $balance, 2), (float) $row['owes']);
        $this->assertSame(1225.0, (float) $row['owes']);
        $this->assertSame(700.0, (float) $row['finished'], 'the rest of the finished file; the file in the office is not finished');
        $this->assertSame('75.00 not on a file', $row['loose_note']);
    }

    /**
     * A write-off whose file was re-priced under it comes off the balance and
     * takes nothing from any file, so the files say more is due than is owed.
     * What can be asked for on finished work is never more than is owed.
     */
    public function test_finished_work_is_never_more_than_is_owed(): void
    {
        OfficeSettingModel::put(OfficeSettingModel::WRITEOFF_CAP, '500.00', $this->admin->id);

        $customer = $this->party();
        $this->file($customer, 1000, 30);
        $small = $this->file($customer, 100, 20);

        $this->pay($customer, 100, [$small->id => 100], [
            'entry_kind' => 'writeoff',
            'reason' => 'Given up',
            'payment_mode' => null,
        ]);

        // Re-priced to nothing after: the write-off has nothing left to take.
        WorkFileItemModel::where('work_file_id', $small->id)->update(['customer_amount' => 0]);
        $small->customer_amount = 0;
        $small->save();
        $small->fresh()->syncLedger();

        $row = $this->row($customer);

        $this->assertSame(900.0, (float) $row['owes']);
        $this->assertSame(900.0, (float) $row['finished'], 'the 1,000 file is due in full, but only 900 is owed');
    }

    public function test_a_balance_carried_from_the_old_book_says_so(): void
    {
        $customer = $this->party();
        $this->entry($customer, 'debit', 2500, 0, CloseClientLedgerController::BROUGHT);
        $this->entry($customer, 'debit', 40, 0);

        $row = $this->row($customer);

        $this->assertSame('2,500.00 from the old Client Ledger · 40.00 not on a file', $row['loose_note']);
    }

    public function test_a_linked_vendor_account_the_office_owes_is_noted_without_its_name(): void
    {
        if (! Schema::hasColumn('party', 'linked_vendor_id')) {
            $this->markTestSkipped('set-off not migrated');
        }

        $customer = $this->party();
        $this->file($customer, 900, 15);

        $vendor = $this->party('vendor');
        $vendor->name = 'Zorawar Distinct Vendor Works';
        $vendor->save();
        $this->entry($vendor, 'credit', 1400, 10);

        DB::table('party')->where('id', $customer->id)->update(['linked_vendor_id' => $vendor->id]);

        $response = $this->page();
        $row = collect($response->json('props.rows'))->firstWhere('id', $customer->id);

        $this->assertSame('You owe them 1,400.00 as a vendor', $row['setoff_note']);
        $this->assertStringNotContainsString('Zorawar', json_encode($response->json('props.rows')));
    }

    public function test_the_page_totals_and_the_dashboard_agree(): void
    {
        $customer = $this->party();
        $this->file($customer, 1000, 12);

        $page = $this->page();
        $rows = collect($page->json('props.rows'));

        $this->assertEqualsWithDelta($rows->sum('owes'), $page->json('page.totals.owes'), 0.01);
        $this->assertSame($rows->count(), $page->json('page.totals.customers'));

        $tile = collect($this->actingAs($this->admin)->getJson('admin/dashboard')->json('props.tiles'))
            ->firstWhere('label', 'Receivable');

        $this->assertEqualsWithDelta($page->json('page.totals.owes'), $tile['value'], 0.01, 'the tile is the list\'s total');
        $this->assertSame(route('report.collection'), $tile['href'], 'and opens it');
        $this->assertSame('owed by '.$rows->count().' '.($rows->count() === 1 ? 'customer' : 'customers'), $tile['note']);
    }

    public function test_the_remind_button_is_on_every_row_and_never_exported(): void
    {
        $customer = $this->party();
        $this->file($customer, 1000, 12);

        $page = $this->page();
        $remind = collect($page->json('props.columns'))->firstWhere('key', 'remind');

        $this->assertSame('action', $remind['type']);
        $this->assertFalse($remind['exportable']);
        $this->assertSame('Remind', collect($page->json('props.rows'))->firstWhere('id', $customer->id)['remind']);
    }

    public function test_it_says_what_is_owed_and_not_on_it_yet(): void
    {
        // A file not priced for the customer posts nothing to the ledger.
        $customer = $this->party();
        $file = $this->file($customer, 0, 3, WorkFileModel::IN_OFFICE);
        $this->assertSame(0.0, (float) $file->customer_amount);

        $this->actingAs($this->admin)->get(route('report.collection'))
            ->assertOk()
            ->assertSee('work not yet priced for the customer')
            ->assertSee(route('workfile.index', ['pending' => 'customer']), false);
    }

    public function test_it_points_to_balances_still_in_the_old_book(): void
    {
        $client = DB::table('client')->insertGetId([
            'name' => 'Old Book Client '.uniqid(),
            'mobile' => '93801'.random_int(10000, 99999),
            'password' => Hash::make('password-for-tests'),
            'address' => 'Near the RTO, Motihari',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_ledger')->insert([
            'client_id' => $client,
            'payment_by' => '1',
            'amount' => -700,
            'particular' => 'Old work',
            'txn_date' => '2026-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('report.collection'))
            ->assertOk()
            ->assertSee('still in the old Client Ledger')
            ->assertSee(route('client.closebook'), false);
    }

    public function test_it_is_on_the_menu_and_shut_to_strangers(): void
    {
        // By name: the Receivable tile links here too, so the address alone
        // would be on the page without the menu item.
        $this->actingAs($this->admin)->get('admin/dashboard')
            ->assertOk()
            ->assertSee('Collection List');

        $this->actingAs($this->admin)->get(route('report.collection'))
            ->assertOk()
            ->assertSee('data-vue="vue-collection-list"', false)
            // The figures above the list, under the class their styles hang off.
            ->assertSee('class="statement-summary owed-summary"', false);

        auth()->logout();

        $this->get(route('report.collection'))->assertRedirect(url('/admin'));
    }
}

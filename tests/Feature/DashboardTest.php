<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dashboard headline figures, and that every admin screen actually loads.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Test Admin';
        $user->email = 'test-admin-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function party(string $type, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.$mobile;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function entry(int $partyId, string $side, float $amount): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $partyId;
        $entry->txn_date = now()->toDateString();
        $entry->entry_type = $side;
        $entry->amount = $amount;
        $entry->particular = 'Test entry';
        $entry->save();
    }

    /**
     * Receivable and payable are summed per party in the direction that party
     * sits. Netting a debtor against a creditor would report a business with
     * nothing outstanding when in fact it has money to collect and money to pay.
     */
    public function test_receivable_and_payable_are_not_netted_against_each_other(): void
    {
        $base = PartyModel::outstanding();

        $owing = $this->party('customer', '9000000301');
        $this->entry($owing->id, 'debit', 10000);

        $inCredit = $this->party('customer', '9000000302');
        $this->entry($inCredit->id, 'credit', 4000);

        $vendor = $this->party('vendor', '9000000303');
        $this->entry($vendor->id, 'credit', 7000);

        $now = PartyModel::outstanding();

        $this->assertSame(10000.0, $now['receivable'] - $base['receivable'], 'the credit customer must not reduce it');
        $this->assertSame(7000.0, $now['payable'] - $base['payable']);
        $this->assertSame(2, $now['customers'] - $base['customers']);
        $this->assertSame(1, $now['vendors'] - $base['vendors']);
    }

    public function test_work_file_summary_counts_open_files_and_this_month_margin(): void
    {
        $base = WorkFileModel::summary();

        $type = new WorkTypeModel;
        $type->name = 'Dashboard Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        $customer = $this->party('customer', '9000000304');
        $vendor = $this->party('vendor', '9000000305');

        $make = function (string $status, float $charged, ?float $cost) use ($type, $customer, $vendor) {
            $file = new WorkFileModel;
            $file->file_no = 'F-DASH-'.uniqid();
            $file->received_date = now()->toDateString();
            $file->work_type_id = $type->id;
            $file->customer_id = $customer->id;
            $file->customer_amount = $charged;
            $file->vendor_id = $cost === null ? null : $vendor->id;
            $file->vendor_amount = $cost;
            $file->status = $status;
            $file->save();
        };

        $make('in_office', 5000, 3500);          // open,   margin 1500
        $make('under_verification', 2000, null); // open,   margin 2000
        $make('approval_done', 1000, 400);      // closed, margin  600
        $make('cancelled', 9999, 9999);    // ignored entirely

        $now = WorkFileModel::summary();

        $this->assertSame(2, $now['open'] - $base['open'], 'delivered and cancelled are not open');
        $this->assertSame(8000.0, $now['month_billed'] - $base['month_billed'], 'cancelled is excluded');
        $this->assertSame(4100.0, $now['month_margin'] - $base['month_margin']);
    }

    public function test_a_file_received_in_another_month_is_outside_this_month_totals(): void
    {
        $base = WorkFileModel::summary();

        $type = new WorkTypeModel;
        $type->name = 'Old Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        $file = new WorkFileModel;
        $file->file_no = 'F-OLD-'.uniqid();
        $file->received_date = now()->copy()->subMonths(2)->toDateString();
        $file->work_type_id = $type->id;
        $file->customer_id = $this->party('customer', '9000000306')->id;
        $file->customer_amount = 5000;
        $file->status = 'in_office';
        $file->save();

        $now = WorkFileModel::summary();

        $this->assertSame(1, $now['open'] - $base['open'], 'still an open file');
        $this->assertSame(0.0, $now['month_billed'] - $base['month_billed'], 'but not this month');
    }

    public function test_every_admin_screen_loads(): void
    {
        $this->actingAs($this->admin());

        $customer = $this->party('customer', '9000000401');
        $this->entry($customer->id, 'debit', 1500);

        $screens = [
            'admin/dashboard',
            'admin/parties/customer',
            'admin/parties/vendor',
            'admin/party/add/customer',
            'admin/party/entry/vendor',
            'admin/party/edit/'.$customer->id,
            'admin/party/statement/'.$customer->id,
            'admin/files',
            'admin/file/receive',
            'admin/file/assign',
            'admin/file/vendor-return',
            'admin/file/customer-return',
            'admin/file/status',
            'admin/work-types',
            // The client ledger screens predate this work but share the date
            // field and the layout, so a change to either has to be caught here.
            'admin/view-clients',
            'admin/receipt',
            'admin/payment',
        ];

        foreach ($screens as $screen) {
            $this->get($screen)->assertOk("$screen should load");
        }
    }

    public function test_a_filtered_statement_loads_and_shows_indian_dates(): void
    {
        $this->actingAs($this->admin());

        $customer = $this->party('customer', '9000000402');
        $this->entry($customer->id, 'debit', 2500);

        $response = $this->get('admin/party/statement/'.$customer->id.'?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString());

        $response->assertOk();
        // dd-mm-yyyy everywhere, never the ISO or the month-name form.
        $response->assertSee(now()->format('d-m-Y'));
        $response->assertDontSee(now()->format('d-M-Y'));
    }

    /**
     * The mobile layout is pure CSS driven by markup, so it breaks silently if
     * either half goes missing. These assert both halves are still there.
     */
    public function test_pages_carry_the_mobile_stylesheet(): void
    {
        $this->actingAs($this->admin());

        $this->get('admin/dashboard')
            ->assertOk()
            ->assertSee('assets/css/responsive.css', false);
    }

    public function test_wide_tables_are_marked_up_for_stacking_on_mobile(): void
    {
        $this->actingAs($this->admin());

        $customer = $this->party('customer', '9000000403');
        $this->entry($customer->id, 'debit', 750);

        // A data table: label left, value right.
        $this->get('admin/parties/customer')
            ->assertOk()
            ->assertSee('party-table rt', false)
            ->assertSee('data-label="Balance"', false);

        $this->get('admin/party/statement/'.$customer->id)
            ->assertOk()
            ->assertSee('statement-table rt', false)
            ->assertSee('data-label="Debit"', false);

        // A table of form controls: label above, control full width.
        $this->get('admin/file/status')
            ->assertOk()
            ->assertSee('rt-form', false);
    }

    /**
     * A DataTables table must never contain a body row with fewer cells than
     * the header — it stops the table initialising and throws "Incorrect column
     * count" at the user. The old "no records" placeholder did exactly that, and
     * only showed up once a table happened to be empty: filter a statement to a
     * quiet period and the page looked fine while the table was dead.
     */
    public function test_an_empty_table_renders_no_placeholder_row(): void
    {
        $this->actingAs($this->admin());

        $customer = $this->party('customer', '9000000404');
        $this->entry($customer->id, 'debit', 100);

        $screens = [
            // A period the entry above cannot fall into.
            'admin/party/statement/'.$customer->id.'?from=2020-01-01&to=2020-01-31',
            'admin/files?status=cancelled',
        ];

        foreach ($screens as $screen) {
            $html = $this->get($screen)->assertOk()->getContent();

            $this->assertSame(1, preg_match('#<table[^>]*id="example".*?</table>#s', $html, $table), $screen.' has the table');
            $this->assertSame(1, preg_match('#<tbody>(.*?)</tbody>#s', $table[0], $body), $screen.' has a body');

            $this->assertStringNotContainsString('<td colspan', $body[1], $screen.' must not place a merged cell in the body');
        }
    }

    public function test_an_unknown_party_type_is_not_found(): void
    {
        $this->actingAs($this->admin());

        $this->get('admin/parties/supplier')->assertNotFound();
        $this->get('admin/party/add/supplier')->assertNotFound();
    }

    public function test_the_new_screens_require_an_admin_login(): void
    {
        $this->get('admin/parties/customer')->assertRedirect(url('/admin'));
        $this->get('admin/files')->assertRedirect(url('/admin'));
        $this->get('admin/work-types')->assertRedirect(url('/admin'));
    }
}

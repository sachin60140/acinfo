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

    /**
     * A wide table has to become a stack of cards on a phone, or it grows a
     * sideways scrollbar and the right-hand columns — which on this app are the
     * money — sit off screen.
     *
     * This used to check the `rt` classes in responsive.css, then check either
     * those or a Vue mount point while screens moved across one at a time. They
     * have all moved, so the Blade half is gone: the rule now lives in each
     * component's own stylesheet, and VueMountTest checks it there, against the
     * stylesheet rather than the page — the CSS is in the bundle, so a rendered
     * page cannot show whether it survived.
     *
     * What is left worth asserting here is the thing a component cannot do for
     * itself: that a screen carrying a wide table actually mounts something.
     * A screen that quietly stopped mounting would render an empty space and
     * still pass every component-level check.
     */
    public function test_every_wide_table_screen_mounts_a_component(): void
    {
        $this->actingAs($this->admin());

        $customer = $this->party('customer', '9000000403');
        $this->entry($customer->id, 'debit', 750);

        $screens = [
            'admin/parties/customer',
            'admin/party/statement/'.$customer->id,
            'admin/files',
            'admin/file/assign',
            'admin/file/customer-return',
            'admin/file/vendor-return',
            'admin/file/status',
            'admin/reports/files',
        ];

        foreach ($screens as $screen) {
            $html = $this->get($screen)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/data-vue="vue-[\w-]+"\s+data-props="/',
                $html,
                "$screen shows a wide table but mounts nothing to draw it, so the page renders ".
                'an empty space where the table should be.'
            );
        }
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

            /*
             * Converted screens cannot have this fault. It was a DataTables
             * fault specifically — it counts cells per row and stops dead when
             * one falls short. The grid that replaced it does its own layout and
             * spans headings across the full width, so there is nothing to
             * miscount. VueMountTest covers those screens instead.
             */
            if (str_contains($html, 'data-vue="vue-')) {
                continue;
            }

            $this->assertSame(1, preg_match('#<table[^>]*id="example".*?</table>#s', $html, $table), $screen.' has the table');
            $this->assertSame(1, preg_match('#<tbody>(.*?)</tbody>#s', $table[0], $body), $screen.' has a body');

            $this->assertStringNotContainsString('<td colspan', $body[1], $screen.' must not place a merged cell in the body');
        }
    }

    /**
     * The tile counts work in hand, so following it must land on that same set.
     * It linked to the unfiltered list, which showed every file ever received —
     * a count of 3 opening onto 200 rows.
     */
    public function test_the_open_files_tile_lands_on_the_files_it_counted(): void
    {
        $this->actingAs($this->admin());

        $type = new WorkTypeModel;
        $type->name = 'Tile Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        $customer = $this->party('customer', '9000000501');

        $make = function (string $status) use ($type, $customer) {
            $file = new WorkFileModel;
            $file->file_no = 'F-TILE-'.uniqid();
            $file->received_date = now()->toDateString();
            $file->work_type_id = $type->id;
            $file->customer_id = $customer->id;
            $file->customer_amount = 1000;
            $file->status = $status;
            $file->save();

            return $file;
        };

        $open = $make('paper_pendency');
        $done = $make('approval_done');

        // The count and the filtered list are the same set.
        $listed = WorkFileModel::listing('open');
        $this->assertTrue($listed->contains('id', $open->id));
        $this->assertFalse($listed->contains('id', $done->id));

        // And the list accepts the filter the tile links with.
        $this->get(route('workfile.index', ['status' => 'open']))
            ->assertOk()
            ->assertSee($open->file_no)
            ->assertDontSee($done->file_no);
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

    /**
     * The tiles a dashboard shows, keyed by label.
     */
    private function tiles(): array
    {
        $html = $this->get('admin/dashboard')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('#data-vue="vue-dashboard" data-props="(.*?)"></div>#s', $html, $mount),
            'the dashboard does not mount its tiles'
        );

        $props = json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true);

        return collect($props['tiles'])->keyBy('label')->all();
    }

    /**
     * A file waiting on a price is missing from every other figure on this
     * screen without any of them looking wrong: it posts nothing to either
     * ledger, so it is not receivable, not payable, and adds nothing to margin.
     * The only way it is ever noticed is if something says so.
     */
    public function test_the_dashboard_reports_files_waiting_on_a_price(): void
    {
        $this->actingAs($this->admin());

        $type = new WorkTypeModel;
        $type->name = 'Pending Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        $customer = $this->party('customer', '9000000601');

        $this->assertArrayNotHasKey(
            'Awaiting Price',
            $this->tiles(),
            'a tile reading zero every day is one nobody reads'
        );

        $file = new WorkFileModel;
        $file->file_no = 'F-PEND-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->work_type_id = $type->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = 0;      // taken in, price not agreed
        $file->status = 'in_office';
        $file->save();

        $tiles = $this->tiles();

        $this->assertArrayHasKey('Awaiting Price', $tiles, 'it appears once something is waiting');
        $this->assertSame(1, $tiles['Awaiting Price']['value']);
        $this->assertStringContainsString('not billed', $tiles['Awaiting Price']['note']);

        // And it lands on exactly the files it counted.
        $this->get($tiles['Awaiting Price']['href'])
            ->assertOk()
            ->assertSee($file->file_no);
    }

    /**
     * The file it counts is invisible everywhere else, which is the reason the
     * tile exists. If this ever stops being true the tile is redundant — and if
     * it silently starts being false, a file nobody priced is being counted as
     * money somewhere.
     */
    public function test_a_file_waiting_on_a_price_moves_no_other_dashboard_figure(): void
    {
        $this->actingAs($this->admin());

        $before = $this->tiles();

        $type = new WorkTypeModel;
        $type->name = 'Silent Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        $file = new WorkFileModel;
        $file->file_no = 'F-SILENT-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->work_type_id = $type->id;
        $file->customer_id = $this->party('customer', '9000000602')->id;
        $file->customer_amount = 0;
        $file->status = 'in_office';
        $file->save();

        $after = $this->tiles();

        foreach (['Net Outstanding', 'Receivable', 'Payable', 'File Margin'] as $label) {
            $this->assertSame(
                $before[$label]['value'],
                $after[$label]['value'],
                "$label moved for a file that has no agreed price"
            );
        }

        // Open Files does count it — it is work in hand, priced or not.
        $this->assertSame($before['Open Files']['value'] + 1, $after['Open Files']['value']);
    }

}

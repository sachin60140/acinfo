<?php

namespace Tests\Feature;

use App\Models\ExpenseTypeModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileExpenseModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The figures behind the dashboard's new tiles and charts.
 *
 * Everything here is measured as a difference rather than an absolute: the
 * suite runs against a real database with whatever work is already in it, so a
 * test that asserted "billed in August is 58,500" would be reporting the
 * machine it ran on. What it can say is what its own two files did to the
 * figure, which is the thing the code is actually responsible for.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class DashboardFiguresTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Figures Admin';
        $this->admin->email = 'figures-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->vendor = $this->party('vendor');
        $this->type = $this->anyWorkType();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for figures';
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * A file, priced or not, given out or not.
     *
     * @param  array{received?: string, charged?: float, cost?: ?float, vendor?: bool, status?: string, returned_on?: string}  $how
     */
    private function file(array $how = []): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-DF-'.uniqid();
        $file->received_date = $how['received'] ?? now()->toDateString();
        $file->registration_no = 'BR01DF'.random_int(1000, 9999);
        $file->work_type_id = $this->type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = $how['charged'] ?? 5000;
        $file->status = $how['status'] ?? WorkFileModel::IN_OFFICE;

        if ($how['vendor'] ?? false) {
            $file->vendor_id = $this->vendor->id;
            $file->vendor_date = $how['vendor_date'] ?? now()->toDateString();
            $file->vendor_amount = $how['cost'] ?? null;
        }

        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->type->id;
        $item->customer_amount = $file->customer_amount;
        $item->vendor_amount = $file->vendor_amount;
        $item->vendor_id = $file->vendor_id;
        $item->vendor_date = $file->vendor_date;
        $item->status = $file->status;
        $item->approved_on = $how['approved_on'] ?? null;
        $item->save();

        return $file->fresh();
    }

    /** One line on a party's ledger. Set field by field: nothing here is fillable. */
    private function entry(PartyModel $party, string $date, string $side, float $amount): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $party->id;
        $entry->txn_date = $date;
        $entry->entry_type = $side;
        $entry->amount = $amount;
        $entry->particular = 'Figures fixture';
        $entry->save();
    }

    /** This month's row out of the chart. */
    private function thisMonth(array $months): array
    {
        return collect($months)->firstWhere('month', now()->format('Y-m'));
    }

    // -------------------------------------------------------- money by month

    /**
     * The one property that matters most on this screen.
     *
     * The File Margin tile and the chart under it are two drawings of the same
     * month, and a tile reading 40,000 above a chart reading 62,000 is worse
     * than either being wrong alone. They share unsettled(); this is what says
     * so out loud.
     */
    public function test_the_chart_and_the_tile_agree_about_this_month(): void
    {
        $this->file(['charged' => 9000, 'cost' => 4000, 'vendor' => true]);
        $this->file(['charged' => 3000]);

        $summary = WorkFileModel::summary();
        $month = $this->thisMonth(WorkFileModel::monthlyMoney(12));

        $this->assertEqualsWithDelta($summary['month_billed'], $month['billed'], 0.01);
        $this->assertEqualsWithDelta($summary['month_margin'], $month['margin'], 0.01);
        $this->assertSame($summary['month_files'], $month['files']);
    }

    public function test_it_returns_every_month_in_the_window_oldest_first(): void
    {
        $months = WorkFileModel::monthlyMoney(12);

        $this->assertCount(12, $months);
        $this->assertSame(now()->format('Y-m'), end($months)['month'], 'the newest month is not last');
        $this->assertSame(
            now()->startOfMonth()->subMonths(11)->format('Y-m'),
            $months[0]['month'],
            'the window does not start where it should'
        );

        // Never a gap: a quiet month is a fact about the business, and a chart
        // that closes it up is telling a different story.
        $this->assertSame(12, collect($months)->pluck('month')->unique()->count());
    }

    public function test_a_priced_file_adds_its_margin(): void
    {
        $before = $this->thisMonth(WorkFileModel::monthlyMoney(2));

        $this->file(['charged' => 9000, 'cost' => 4000, 'vendor' => true]);

        $after = $this->thisMonth(WorkFileModel::monthlyMoney(2));

        $this->assertEqualsWithDelta(9000, $after['billed'] - $before['billed'], 0.01);
        $this->assertEqualsWithDelta(4000, $after['cost'] - $before['cost'], 0.01);
        $this->assertEqualsWithDelta(5000, $after['margin'] - $before['margin'], 0.01);
    }

    /**
     * A file out with a vendor at no agreed rate is not free work.
     *
     * It is billed and it has no margin yet, because reading "not agreed" as
     * nothing reported the entire charge as profit — on the one figure the
     * business is run from.
     */
    public function test_work_with_no_agreed_rate_is_billed_but_earns_no_margin_yet(): void
    {
        $before = $this->thisMonth(WorkFileModel::monthlyMoney(2));

        $this->file(['charged' => 9000, 'cost' => null, 'vendor' => true]);

        $after = $this->thisMonth(WorkFileModel::monthlyMoney(2));

        $this->assertEqualsWithDelta(9000, $after['billed'] - $before['billed'], 0.01);
        $this->assertEqualsWithDelta(0, $after['margin'] - $before['margin'], 0.01);
        $this->assertEqualsWithDelta(0, $after['cost'] - $before['cost'], 0.01);
    }

    /**
     * And what it has cost so far is a cost already.
     *
     * Found in a health check on 2026-10-07: a file with one rate still to
     * agree showed no cost at all — the rate agreed on its other work, already
     * on the vendor's statement, and the challan already paid — while the
     * Profit report the chart opens counted both. Only its margin waits.
     */
    public function test_the_chart_counts_the_cost_the_profit_report_counts(): void
    {
        $thisMonth = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];

        $report = fn () => WorkFileModel::profitBy('month', ...$thisMonth)->firstWhere('group_key', now()->format('Y-m'));

        $chartBefore = $this->thisMonth(WorkFileModel::monthlyMoney(2));
        $reportBefore = $report();

        // HPT at 3,000 with 1,200 agreed, TR at 2,000 with nothing agreed yet,
        // and a 250 challan paid on the folder.
        $file = $this->file(['charged' => 3000, 'cost' => 1200, 'vendor' => true, 'status' => WorkFileModel::DISPATCHED]);

        $tr = new WorkFileItemModel;
        $tr->work_file_id = $file->id;
        $tr->work_type_id = $this->type->id;
        $tr->customer_amount = 2000;
        $tr->vendor_id = $this->vendor->id;
        $tr->vendor_date = now()->toDateString();
        $tr->status = WorkFileModel::DISPATCHED;
        $tr->save();

        $file->load('items');
        $file->rollUp();
        $file->save();

        $challan = new ExpenseTypeModel;
        $challan->name = 'Challan '.uniqid();
        $challan->is_active = 1;
        $challan->save();

        $expense = new WorkFileExpenseModel;
        $expense->work_file_id = $file->id;
        $expense->expense_type_id = $challan->id;
        $expense->amount = 250;
        $expense->spent_on = now()->toDateString();
        $expense->save();

        $chartAfter = $this->thisMonth(WorkFileModel::monthlyMoney(2));
        $reportAfter = $report();

        $this->assertEqualsWithDelta(5000, $chartAfter['billed'] - $chartBefore['billed'], 0.01);
        $this->assertEqualsWithDelta(1450, $chartAfter['cost'] - $chartBefore['cost'], 0.01, 'the agreed rate and the challan are a cost');
        $this->assertEqualsWithDelta(0, $chartAfter['margin'] - $chartBefore['margin'], 0.01, 'and the margin still waits on the price');

        // And the Profit report the chart opens moved by the same three figures.
        foreach (['billed', 'cost', 'margin'] as $figure) {
            $this->assertEqualsWithDelta(
                (float) $reportAfter->$figure - (float) ($reportBefore->$figure ?? 0),
                $chartAfter[$figure] - $chartBefore[$figure],
                0.01,
                "the chart and the Profit report it opens disagree about the $figure"
            );
        }
    }

    // --------------------------------------------------------- files in and out

    public function test_a_file_taken_in_counts_against_the_month_it_arrived(): void
    {
        $before = $this->thisMonth(WorkFileModel::monthlyFlow(2));

        $this->file();

        $after = $this->thisMonth(WorkFileModel::monthlyFlow(2));

        $this->assertSame(1, $after['received'] - $before['received']);
        $this->assertSame(0, $after['finished'] - $before['finished'], 'a file that arrived was counted as finished');
    }

    public function test_an_approved_file_counts_as_finished_on_the_day_it_was_approved(): void
    {
        $before = $this->thisMonth(WorkFileModel::monthlyFlow(2));

        $this->file([
            'status' => WorkFileModel::APPROVED,
            'approved_on' => now()->toDateString(),
        ]);

        $after = $this->thisMonth(WorkFileModel::monthlyFlow(2));

        $this->assertSame(1, $after['finished'] - $before['finished']);
    }

    // ------------------------------------------------------ who is holding what

    public function test_it_counts_the_files_a_vendor_still_has(): void
    {
        $before = WorkFileModel::vendorsHolding();

        $this->file([
            'vendor' => true,
            'cost' => 3000,
            'status' => WorkFileModel::DISPATCHED,
            'vendor_date' => now()->subDays(9)->toDateString(),
        ]);

        $after = WorkFileModel::vendorsHolding();

        $this->assertSame(1, $after['files'] - $before['files']);
        $this->assertGreaterThanOrEqual(9, $after['oldest_days']);
    }

    /** Work that has come back is not being held. */
    public function test_work_the_vendor_returned_is_not_counted(): void
    {
        $file = $this->file([
            'vendor' => true,
            'cost' => 3000,
            'status' => WorkFileModel::DISPATCHED,
        ]);

        $before = WorkFileModel::vendorsHolding();

        WorkFileItemModel::where('work_file_id', $file->id)
            ->update(['vendor_returned_on' => now()->toDateString()]);

        $after = WorkFileModel::vendorsHolding();

        $this->assertSame(1, $before['files'] - $after['files']);
    }

    // ------------------------------------------------------------ owing longest

    public function test_the_oldest_debt_is_the_one_reported(): void
    {
        $ancient = $this->party('customer');

        $this->entry($ancient, '1999-01-04', 'debit', 4321);

        $oldest = PartyModel::oldestUnpaid();

        $this->assertNotNull($oldest);
        $this->assertSame($ancient->name, $oldest['name'], 'somebody newer was reported as the oldest');
        $this->assertEqualsWithDelta(4321, $oldest['amount'], 0.01);
        $this->assertSame('04-01-1999', $oldest['since']);
        $this->assertGreaterThan(9000, $oldest['days']);
    }

    /** Somebody who has paid is not owing anything, however long ago it was. */
    public function test_a_settled_customer_is_not_the_oldest_debt(): void
    {
        $settled = $this->party('customer');

        $this->entry($settled, '1998-01-04', 'debit', 5000);
        $this->entry($settled, '1998-01-04', 'credit', 5000);

        $oldest = PartyModel::oldestUnpaid();

        $this->assertNotSame($settled->name, $oldest['name'] ?? null);
    }

    // ---------------------------------------------------------- the screen itself

    public function test_the_dashboard_draws_its_charts(): void
    {
        $this->file(['charged' => 9000, 'cost' => 4000, 'vendor' => true]);

        $charts = collect($this->actingAs($this->admin)
            ->getJson(url('admin/dashboard'))->assertOk()->json('props.charts'));

        $this->assertNotEmpty($charts, 'the dashboard drew no charts at all');

        $money = $charts->firstWhere('title', 'Money by month');

        $this->assertNotNull($money);
        $this->assertSame('columns', $money['kind']);
        $this->assertSame('money', $money['format']);
        $this->assertCount(12, $money['rows']);
        $this->assertSame(['billed', 'cost', 'margin'], array_column($money['series'], 'key'));

        // Every chart lands on the screen holding the same rows, the same rule
        // the tiles follow.
        foreach ($charts as $chart) {
            $this->assertNotEmpty($chart['href'] ?? null, $chart['title'].' goes nowhere');
        }
    }

    public function test_a_vendor_holding_work_puts_a_tile_on_the_dashboard(): void
    {
        $this->file([
            'vendor' => true,
            'cost' => 3000,
            'status' => WorkFileModel::DISPATCHED,
        ]);

        $tiles = collect($this->actingAs($this->admin)
            ->getJson(url('admin/dashboard'))->assertOk()->json('props.tiles'));

        $tile = $tiles->firstWhere('label', 'With Vendors');

        $this->assertNotNull($tile, 'nothing on the dashboard says a vendor is holding work');
        $this->assertGreaterThan(0, $tile['value']);
        $this->assertSame(route('workfile.vendorreturn'), $tile['href']);
    }
}

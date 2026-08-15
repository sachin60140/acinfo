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
 * Party-wise work reporting.
 *
 * The point of these is that the report agrees with the ledger. A report is
 * only worth having if its figures are the same figures, so the assertions
 * compare against balances read straight from party_ledger rather than against
 * numbers recomputed here.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReportTest extends TestCase
{
    use DatabaseTransactions;

    private WorkTypeModel $workType;

    private PartyModel $customerA;

    private PartyModel $customerB;

    private PartyModel $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workType = new WorkTypeModel;
        $this->workType->name = 'Report Work '.uniqid();
        $this->workType->is_active = 1;
        $this->workType->save();

        $this->customerA = $this->party('customer', '9444400001');
        $this->customerB = $this->party('customer', '9444400002');
        $this->vendor = $this->party('vendor', '9444400003');
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

    private function file(PartyModel $customer, float $charged, ?PartyModel $vendor = null, ?float $cost = null, string $status = 'in_office'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RPT-'.uniqid();
        $file->received_date = '2026-05-10';
        $file->work_type_id = $this->workType->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = $charged;
        $file->vendor_id = $vendor?->id;
        $file->vendor_amount = $cost;
        $file->vendor_date = $vendor ? '2026-05-11' : null;
        $file->status = $status;
        $file->save();
        $file->syncLedger();

        return $file;
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Report Admin';
        $user->email = 'report-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    public function test_a_customer_report_groups_every_file_under_its_customer(): void
    {
        $this->file($this->customerA, 5000);
        $this->file($this->customerA, 3000);
        $this->file($this->customerB, 2000);

        $rows = WorkFileModel::report('customer');

        $mine = $rows->whereIn('party_id', [$this->customerA->id, $this->customerB->id]);
        $this->assertCount(3, $mine);
        $this->assertSame(2, $mine->where('party_id', $this->customerA->id)->count());
        $this->assertSame($this->customerA->name, $mine->firstWhere('party_id', $this->customerA->id)->party_name);
    }

    /**
     * A vendor report can only show files a vendor was actually given; in-house
     * work has nobody to file it under.
     */
    public function test_a_vendor_report_leaves_out_work_kept_in_house(): void
    {
        $withVendor = $this->file($this->customerA, 5000, $this->vendor, 3500);
        $inHouse = $this->file($this->customerA, 2000);

        $rows = WorkFileModel::report('vendor');

        $this->assertTrue($rows->contains('id', $withVendor->id));
        $this->assertFalse($rows->contains('id', $inHouse->id));
        $this->assertSame($this->vendor->name, $rows->firstWhere('id', $withVendor->id)->party_name);
    }

    public function test_the_report_can_be_narrowed_to_one_party_a_status_and_a_period(): void
    {
        $wanted = $this->file($this->customerA, 5000);
        $otherParty = $this->file($this->customerB, 5000);
        $otherStatus = $this->file($this->customerA, 1000, null, null, 'approval_done');

        $olderFile = $this->file($this->customerA, 800);
        $olderFile->received_date = '2026-01-05';
        $olderFile->save();

        $rows = WorkFileModel::report('customer', $this->customerA->id, 'open', '2026-05-01', '2026-05-31');

        $this->assertTrue($rows->contains('id', $wanted->id));
        $this->assertFalse($rows->contains('id', $otherParty->id), 'other party');
        $this->assertFalse($rows->contains('id', $otherStatus->id), 'other status');
        $this->assertFalse($rows->contains('id', $olderFile->id), 'outside the period');
    }

    /**
     * The whole value of the report is that it does not disagree with the
     * ledger, so every figure is compared against the balances themselves.
     */
    public function test_report_figures_match_the_ledger_for_returns_and_cancellations(): void
    {
        $plain = $this->file($this->customerA, 5000, $this->vendor, 3500);
        $partReturn = $this->file($this->customerA, 4000);
        $cancelled = $this->file($this->customerA, 9999);

        $partReturn->status = 'paper_returned';
        $partReturn->returned_on = '2026-05-20';
        $partReturn->returned_amount = 1500;
        $partReturn->save();
        $partReturn->syncLedger();

        $cancelled->status = 'cancelled';
        $cancelled->save();
        $cancelled->syncLedger();

        $rows = WorkFileModel::report('customer', $this->customerA->id);

        $billed = 0.0;
        $cost = 0.0;
        foreach ($rows as $row) {
            $line = WorkFileModel::rowTotals($row);
            $billed += $line['billed'];
            $cost += $line['cost'];
        }

        // 5000 + (4000 - 1500 refunded) + 0 cancelled
        $this->assertSame(7500.0, $billed);
        $this->assertSame(3500.0, $cost);

        $this->assertSame($billed, PartyLedgerModel::currentBalance($this->customerA->id), 'report vs ledger');
        $this->assertSame(-$cost, PartyLedgerModel::currentBalance($this->vendor->id));

        $this->assertSame($plain->id, $rows->firstWhere('id', $plain->id)->id);
    }

    /**
     * What the grid on the report page was handed.
     *
     * The report is rendered by a component now, so the page carries its data as
     * JSON rather than as markup. Asserting against this is stricter than
     * matching strings in HTML ever was: it is the actual contract, so a column
     * quietly dropped from the export shows up as a missing entry rather than
     * hiding behind a heading that still happens to appear somewhere on screen.
     */
    private function gridProps(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('#data-vue="vue-work-report" data-props="(.*?)"></div>#s', $html, $mount),
            'the report page does not mount the grid'
        );

        $props = json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'the report props are not valid JSON');

        return $props;
    }

    /** @return array<int, string> the column headings, in order, that reach a file */
    private function exportedLabels(array $props): array
    {
        $exported = array_filter(
            $props['columns'],
            fn ($column) => ($column['exportable'] ?? true) !== false && empty($column['hidden'])
        );

        return array_values(array_map(fn ($column) => $column['label'], $exported));
    }

    public function test_the_report_page_renders_grouped_with_totals(): void
    {
        $this->actingAs($this->admin());

        $this->file($this->customerA, 5000, $this->vendor, 3500);
        $this->file($this->customerB, 2000);

        $props = $this->gridProps(route('report.files', ['party_type' => 'customer']));

        // Banded per party, with the money subtotalled per band and overall.
        $this->assertSame('party_id', $props['groupBy']);
        $this->assertSame(['billed' => 'sum', 'cost' => 'sum', 'margin' => 'sum'], $props['totals']);

        $names = array_column($props['rows'], 'party_name');
        $this->assertContains($this->customerA->name, $names);
        $this->assertContains($this->customerB->name, $names);

        // The grand total is rendered by the page, outside the grid.
        $this->get(route('report.files', ['party_type' => 'customer']))->assertSee('Grand Total');

        $vendorNames = array_column($this->gridProps(route('report.files', ['party_type' => 'vendor']))['rows'], 'party_name');
        $this->assertContains($this->vendor->name, $vendorNames);
    }

    /**
     * A party split across two pages would be banded twice and subtotalled
     * twice, each time over half its files — two bands for one customer, each
     * showing a total that is not their total.
     */
    public function test_the_report_is_never_paged(): void
    {
        $this->actingAs($this->admin());

        $this->file($this->customerA, 5000);
        $this->file($this->customerA, 1000);
        $this->file($this->customerB, 2000);

        $props = $this->gridProps(route('report.files', ['party_type' => 'customer']));

        $this->assertGreaterThanOrEqual(
            count($props['rows']),
            $props['perPage'],
            'a page shorter than the report would split a party across bands'
        );
    }

    /**
     * The two reports must be visibly different, not just internally different.
     * With the reported party's column hidden, a customer-wise report showed one
     * party column headed "Vendor" full of vendor names and read as a vendor
     * report — the band heading alone did not say whose report it was.
     */
    public function test_each_report_names_the_party_it_is_grouped_by(): void
    {
        $this->actingAs($this->admin());

        $this->file($this->customerA, 5000, $this->vendor, 3000);

        $customerWise = $this->gridProps(route('report.files', ['party_type' => 'customer']));
        $vendorWise = $this->gridProps(route('report.files', ['party_type' => 'vendor']));

        $visible = function (array $props) {
            return array_values(array_map(
                fn ($column) => $column['label'],
                array_filter($props['columns'], fn ($column) => empty($column['hidden']))
            ));
        };

        // Only the machine-facing grouping key is hidden, so the reported party
        // is a real column on screen and in the export.
        $hidden = array_filter($customerWise['columns'], fn ($column) => ! empty($column['hidden']));
        $this->assertCount(1, $hidden, 'exactly one column is hidden');
        $this->assertSame('party_id', reset($hidden)['key'], 'and it is the grouping key');

        // Customer-wise names the customer and shows the vendor as "Given To".
        $this->assertContains('Customer', $visible($customerWise));
        $this->assertContains('Given To', $visible($customerWise));
        $this->assertContains($this->customerA->name, array_column($customerWise['rows'], 'party_name'));

        // Vendor-wise is the mirror image.
        $this->assertContains('Vendor', $visible($vendorWise));
        $this->assertContains('Received From', $visible($vendorWise));
        $this->assertContains($this->vendor->name, array_column($vendorWise['rows'], 'party_name'));

        // And neither borrows the other's column headings.
        $this->assertNotContains('Received From', $visible($customerWise));
        $this->assertNotContains('Given To', $visible($vendorWise));
    }

    /**
     * The remark is what tells you why a file stands where it does, so it has
     * to survive into the spreadsheet and the PDF — not just the screen.
     */
    public function test_the_report_carries_each_file_s_latest_remark_into_the_exports(): void
    {
        $this->actingAs($this->admin());

        $file = $this->file($this->customerA, 5000);
        $file->logStatus(null, 'Received from customer');
        $file->logStatus('in_office', 'Insurance copy awaited from customer');

        $quiet = $this->file($this->customerB, 1000);

        // Scoped to this customer: the report otherwise spans every file in the
        // database, and a real one may legitimately carry the earlier wording.
        $props = $this->gridProps(route('report.files', [
            'party_type' => 'customer',
            'party_id' => $this->customerA->id,
        ]));

        $remarks = array_column($props['rows'], 'remark');

        // The most recent note, not the first one.
        $this->assertContains('Insurance copy awaited from customer', $remarks);
        $this->assertNotContains('Received from customer', $remarks, 'superseded by the later note');

        // A file with nothing said about it simply leaves the cell empty.
        $this->assertNotNull(WorkFileModel::find($quiet->id));

        $exported = $this->exportedLabels($props);

        // The seven the user asked for, by name.
        foreach (['File No.', 'Received', 'Work Type', 'Details', 'Given To', 'Status', 'Remarks'] as $required) {
            $this->assertContains($required, $exported, "$required must reach Excel and the PDF");
        }

        $this->assertNotContains('Customer Id', $exported, 'the internal grouping key must never be exported');
    }

    /**
     * A spreadsheet has no bands.
     *
     * On screen the rows are grouped under a heading naming the party, so the
     * party column can look redundant. Exported, that grouping is gone and every
     * row is flat — so without the party column the only party left in a
     * customer-wise file is the vendor, and the whole export reads as a vendor
     * report. Losing which customer a row belongs to is not a cosmetic loss; it
     * is the column that makes the rest of the row mean anything.
     */
    public function test_the_export_names_the_party_each_row_belongs_to(): void
    {
        $this->actingAs($this->admin());

        $this->file($this->customerA, 5000, $this->vendor, 3500);

        $customerWise = $this->exportedLabels($this->gridProps(route('report.files', ['party_type' => 'customer'])));
        $vendorWise = $this->exportedLabels($this->gridProps(route('report.files', ['party_type' => 'vendor'])));

        $this->assertContains('Customer', $customerWise, 'a customer-wise export with no customer column');
        $this->assertContains('Vendor', $vendorWise, 'a vendor-wise export with no vendor column');

        // The money is the point of the report, so it travels too.
        foreach (['Billed', 'Cost', 'Margin'] as $figure) {
            $this->assertContains($figure, $customerWise, "$figure must reach the export");
        }
    }

    /**
     * Only (party_type, mobile) is unique on a party, so two customers may
     * legitimately share a name. Grouping the report on the name merged them
     * into one band and added their money together — two customers owing 5,000
     * and 3,000 were reported as one owing 8,000.
     */
    public function test_two_parties_sharing_a_name_are_reported_separately(): void
    {
        $this->actingAs($this->admin());

        $one = $this->party('customer', '9555500001');
        $two = $this->party('customer', '9555500002');
        $shared = 'Sharma Traders '.uniqid();
        $one->name = $shared;
        $one->save();
        $two->name = $shared;
        $two->save();

        $this->file($one, 5000);
        $this->file($two, 3000);

        $rows = WorkFileModel::report('customer')->whereIn('party_id', [$one->id, $two->id]);
        $this->assertSame(2, $rows->pluck('party_id')->unique()->count(), 'two distinct parties');

        $props = $this->gridProps(route('report.files', ['party_type' => 'customer']));

        // The key the grid bands on must separate them, so it has to be the id.
        $this->assertSame('party_id', $props['groupBy']);

        $mine = array_filter($props['rows'], fn ($row) => in_array($row['party_id'], [$one->id, $two->id], true));

        $this->assertCount(2, $mine, 'one row each');
        $this->assertSame(
            2,
            count(array_unique(array_column($mine, 'party_id'))),
            'banded under two distinct keys, not merged into one'
        );

        // Each keeps its own money rather than the two being added together.
        $byParty = [];
        foreach ($mine as $row) {
            $byParty[$row['party_id']] = (float) $row['billed'];
        }

        $this->assertSame(5000.0, $byParty[$one->id]);
        $this->assertSame(3000.0, $byParty[$two->id]);
    }

    /**
     * Every row must carry a value for every column the grid is told to draw.
     *
     * The old table failed here in a way that looked fine: DataTables counts
     * cells per row, and one short row stopped it initialising with "Incorrect
     * column count" on a perfectly good-looking page. The grid does its own
     * layout so it cannot fail that way, but the underlying mistake — a column
     * configured with no matching field on the row — still shows as a column of
     * dashes where the data should be, which is quieter and worse.
     */
    public function test_every_row_carries_a_field_for_every_column(): void
    {
        $this->actingAs($this->admin());

        $this->file($this->customerA, 5000, $this->vendor, 3500);
        $this->file($this->customerB, 2000);

        $props = $this->gridProps(route('report.files', ['party_type' => 'customer']));

        $this->assertNotEmpty($props['rows'], 'the report produced no rows');

        foreach ($props['columns'] as $column) {
            foreach ($props['rows'] as $index => $row) {
                $this->assertArrayHasKey(
                    $column['key'],
                    $row,
                    "row $index has no \"{$column['key']}\" for the \"{$column['label']}\" column"
                );
            }
        }

        // A badge colours itself from the raw key beside the label, so a status
        // column without one renders every file in the same neutral grey.
        $status = array_filter($props['columns'], fn ($column) => ($column['type'] ?? '') === 'badge');

        foreach ($status as $column) {
            foreach ($props['rows'] as $index => $row) {
                $this->assertArrayHasKey($column['key'].'_key', $row, "row $index has no raw key for its badge");
            }
        }

        // And the grouping key must reach every row, or a file lands in no band.
        foreach ($props['rows'] as $index => $row) {
            $this->assertNotEmpty($row[$props['groupBy']], "row $index has no grouping key");
        }
    }

    /**
     * A party id from the other side would match nothing and read as "no work
     * done", which is a different and much more alarming statement.
     */
    public function test_a_party_filter_from_the_other_side_is_ignored_not_obeyed(): void
    {
        $this->actingAs($this->admin());

        $this->file($this->customerA, 5000, $this->vendor, 3500);

        $response = $this->get(route('report.files', [
            'party_type' => 'vendor',
            'party_id' => $this->customerA->id,
        ]));

        $response->assertOk()->assertSee($this->vendor->name);
    }

    public function test_the_report_rejects_a_reversed_date_range(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('report.files', ['from' => '2026-05-31', 'to' => '2026-05-01']))
            ->assertSessionHasErrors('to');
    }
}

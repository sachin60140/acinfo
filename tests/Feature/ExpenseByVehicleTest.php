<?php

namespace Tests\Feature;

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
 * Everything one vehicle's work cost.
 *
 * Asked by registration number — a number plate is what anybody asking has in
 * front of them. The Expense Report then answers file by file with the vendor's
 * charge beside every expense paid out on it, and the total. Costs only: what
 * the customer was charged, and the margin, are the Profit report's question.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ExpenseByVehicleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    private int $challan;

    private int $affidavit;

    /** A vehicle made up for this test, so no real file answers for it. */
    private string $plate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Costs Admin';
        $this->admin->email = 'costs-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Rakesh Ji Madhubani');
        $this->vendor = $this->party('vendor', 'Parwez Ji Muzaffarpur');

        $this->tr = $this->type('TR');
        $this->hpa = $this->type('HPA');

        $this->challan = $this->kind('Challan');
        $this->affidavit = $this->kind('Affidavit');

        $this->plate = 'BR05ZQ'.random_int(1000, 9999);
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function type(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function kind(string $name): int
    {
        return (int) DB::table('expense_type')->insertGetId([
            'name' => $name.' '.uniqid(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A folder of two works — TR charged 5,000, HPA 2,500 — each with a vendor's rate. */
    private function file(string $vehicle, float $trRate = 3000, float $hpaRate = 1200): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-EV-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = $vehicle;
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 7500;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ([[$this->tr, 5000, $trRate], [$this->hpa, 2500, $hpaRate]] as [$type, $charge, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charge;
            $item->vendor_id = $this->vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = '2026-09-18';
            $item->status = WorkFileModel::DISPATCHED;
            $item->save();
        }

        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function spend(WorkFileModel $file, int $kind, float $amount, string $what = ''): void
    {
        DB::table('work_file_expense')->insert([
            'work_file_id' => $file->id,
            'expense_type_id' => $kind,
            'amount' => $amount,
            'spent_on' => '2026-09-19',
            'remark' => $what ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function report(array $query)
    {
        return $this->actingAs($this->admin)
            ->getJson(route('report.expenses').'?'.http_build_query($query))
            ->assertOk();
    }

    // ------------------------------------------------------------- the point

    /** The vendor's charge and every expense, on that vehicle, and what they came to. */
    public function test_a_vehicle_shows_everything_its_work_cost(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450, 'RTO challan');
        $this->spend($file, $this->affidavit, 150);

        $props = $this->report(['vehicle' => $this->plate])->json('props');
        $rows = collect($props['rows']);

        // Two works' rates and two expenses: 3,000 + 1,200 + 450 + 150.
        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(4800, $rows->sum('amount'), 0.005);

        $charges = $rows->where('type_name', 'Vendor charge');
        $this->assertCount(2, $charges);
        $this->assertEqualsWithDelta(4200, $charges->sum('amount'), 0.005);
        $this->assertTrue($charges->contains(fn ($row) => str_contains($row['remark'], $this->tr->name) && str_contains($row['remark'], $this->vendor->name)));

        // Banded by file, so each band subtotals what that file cost.
        $this->assertSame('file_id', $props['groupBy']);
        $this->assertSame([$file->id], $rows->pluck('file_id')->unique()->values()->all());
    }

    public function test_it_is_that_vehicle_and_no_other(): void
    {
        $mine = $this->file($this->plate);
        $theirs = $this->file('BR01ZZ0001', 9999, 8888);
        $this->spend($theirs, $this->challan, 777);

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertSame([$mine->id], $rows->pluck('file_id')->unique()->values()->all());
        $this->assertFalse($rows->contains('amount', 9999.0));
        $this->assertFalse($rows->contains('amount', 777.0));
    }

    /** Typed however it is typed: stored without spaces or dashes, matched the same way. */
    public function test_the_number_is_found_however_it_is_typed(): void
    {
        $file = $this->file($this->plate);

        // Lower case with spaces and a dash, whole, and the end of it.
        $loose = strtolower(substr($this->plate, 0, 4)).' '.strtolower(substr($this->plate, 4, 2)).'-'.substr($this->plate, 6);

        foreach ([$loose, $this->plate, substr($this->plate, 4)] as $typed) {
            $rows = collect($this->report(['vehicle' => $typed])->json('props.rows'));

            $this->assertSame([$file->id], $rows->pluck('file_id')->unique()->values()->all(), $typed);
        }
    }

    /** Costs only: what the customer was charged is not a cost. */
    public function test_it_says_nothing_of_what_the_customer_was_charged(): void
    {
        $this->file($this->plate);

        $said = $this->report(['vehicle' => $this->plate])->getContent();

        $this->assertStringNotContainsString('7500', $said);
        $this->assertStringNotContainsString('7,500', $said);
        $this->assertStringNotContainsString('"5000', $said);
    }

    /** Work struck off charges nobody, and cost nobody either. */
    public function test_a_work_struck_off_is_not_a_cost(): void
    {
        $file = $this->file($this->plate);
        $file->items()->where('work_type_id', $this->hpa->id)->update(['status' => WorkFileModel::CANCELLED]);

        $rows = collect($this->report(['vehicle' => $this->plate])->json('props.rows'));

        $this->assertEqualsWithDelta(3000, $rows->sum('amount'), 0.005);
        $this->assertFalse($rows->contains(fn ($row) => str_contains((string) $row['remark'], $this->hpa->name)));
    }

    /** A kind of expense asked for is a kind of expense: the vendor's charge is not one. */
    public function test_a_kind_asked_for_leaves_the_vendors_charge_out(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);
        $this->spend($file, $this->affidavit, 150);

        $rows = collect($this->report(['vehicle' => $this->plate, 'expense_type_id' => $this->challan])->json('props.rows'));

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(450, $rows->sum('amount'), 0.005);
    }

    /** Without a vehicle, the report is the one it always was. */
    public function test_without_a_vehicle_nothing_changes(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);

        $props = $this->report([])->json('props');

        $this->assertSame('type_id', $props['groupBy']);
        $this->assertFalse(collect($props['rows'])->contains('type_name', 'Vendor charge'));
    }

    /** The page says what it is showing. */
    public function test_the_page_says_it_is_one_vehicles_costs(): void
    {
        $file = $this->file($this->plate);
        $this->spend($file, $this->challan, 450);

        $page = $this->actingAs($this->admin)
            ->get(route('report.expenses', ['vehicle' => $this->plate]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Costs on '.$this->plate, $page);
        $this->assertStringContainsString('Total Cost', $page);
        $this->assertStringContainsString('value="'.$this->plate.'"', $page);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ExpenseTypeModel;
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
 * What a file costs beyond the vendor.
 *
 * A transfer challan, an affidavit, a notary's fee: money the office pays out on
 * a particular file and recorded nowhere until now, which means every margin
 * this application has ever shown was too high by exactly the amount nobody was
 * tracking.
 *
 * Office cash, and cost only. No party ledger entry — the money went out of the
 * till rather than to a vendor or on to a customer, so there is nobody for it to
 * be owed by or to. That is the rule most worth a test here, because writing a
 * ledger entry by accident is silent and moves somebody's balance.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class FileExpenseTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Expense Admin';
        $user->email = 'expense-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        /*
         * Named apart, not just numbered apart. Two parties sharing a name made
         * the customer filter untestable: a report of both of them uniqued down
         * to one name and read exactly like a report of one.
         */
        $party->name = ucfirst($type).' '.uniqid().' for expenses';
        $party->mobile = '94000'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function file(PartyModel $customer, array $overrides = []): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-'.substr((string) microtime(true), -6).random_int(10, 99);
        $file->received_date = '2026-09-01';
        $file->work_type_id = WorkTypeModel::query()->value('id');
        $file->customer_id = $customer->id;
        $file->customer_amount = $overrides['customer_amount'] ?? 9000;
        $file->vendor_amount = $overrides['vendor_amount'] ?? null;
        $file->vendor_id = $overrides['vendor_id'] ?? null;
        $file->status = $overrides['status'] ?? 'in_office';
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $file->work_type_id;
        $item->customer_amount = $file->customer_amount;
        $item->vendor_amount = $file->vendor_amount;
        $item->status = $file->status;
        $item->save();

        return $file;
    }

    private function spend(WorkFileModel $file, float $amount, ?string $on = null): WorkFileExpenseModel
    {
        $expense = new WorkFileExpenseModel;
        $expense->work_file_id = $file->id;
        $expense->expense_type_id = ExpenseTypeModel::where('name', 'Transfer Challan')->value('id');
        $expense->amount = $amount;
        $expense->spent_on = $on ?? '2026-09-02';
        $expense->save();

        return $expense;
    }

    private function rowFor(WorkFileModel $file): object
    {
        return WorkFileModel::listing()->firstWhere('id', $file->id);
    }

    // ------------------------------------------------------------ the types

    public function test_the_kinds_the_office_named_are_there_to_choose(): void
    {
        foreach (['Transfer Challan', 'Affidavit', 'Other'] as $name) {
            $this->assertTrue(
                ExpenseTypeModel::where('name', $name)->exists(),
                "$name is not an expense type"
            );
        }
    }

    public function test_a_retired_kind_is_not_offered_unless_a_file_already_uses_it(): void
    {
        $type = new ExpenseTypeModel;
        $type->name = 'Retired Fee '.uniqid();
        $type->is_active = 0;
        $type->save();

        $this->assertFalse(
            ExpenseTypeModel::selectList()->contains('id', $type->id),
            'a retired kind was offered'
        );

        // But an expense already recorded under it must still be correctable
        // without its kind being changed as well.
        $this->assertTrue(ExpenseTypeModel::selectList($type->id)->contains('id', $type->id));
    }

    // -------------------------------------------------------------- the cost

    public function test_an_expense_raises_what_the_file_cost(): void
    {
        $file = $this->file($this->party('customer'), [
            'vendor_id' => $this->party('vendor')->id,
            'vendor_amount' => 5000,
        ]);

        $before = WorkFileModel::rowTotals($this->rowFor($file));

        $this->assertSame(5000.0, $before['cost']);
        $this->assertSame(4000.0, $before['margin']);

        $this->spend($file, 450);

        $after = WorkFileModel::rowTotals($this->rowFor($file));

        $this->assertSame(5450.0, $after['cost'], 'the challan is part of what this file cost');
        $this->assertSame(450.0, $after['expenses']);
        $this->assertSame(3550.0, $after['margin'], 'and the margin is lower by exactly that');
    }

    public function test_several_expenses_add_up(): void
    {
        $file = $this->file($this->party('customer'));

        $this->spend($file, 300);
        $this->spend($file, 150);

        $this->assertSame(450.0, $file->paidOut());
        $this->assertSame(450.0, WorkFileModel::rowTotals($this->rowFor($file))['expenses']);
    }

    /**
     * margin() reads the database rather than a loaded relation, so a margin is
     * never quietly too high because nobody eager-loaded the expenses.
     */
    public function test_the_margin_on_the_model_counts_them_too(): void
    {
        $file = $this->file($this->party('customer'), [
            'vendor_id' => $this->party('vendor')->id,
            'vendor_amount' => 5000,
        ]);

        $this->assertSame(4000.0, WorkFileModel::find($file->id)->margin());

        $this->spend($file, 450);

        $this->assertSame(3550.0, WorkFileModel::find($file->id)->margin());
    }

    /**
     * A row that was never asked for its expenses reports none rather than
     * guessing. Inventing a zero would understate a cost instead of leaving it
     * visibly absent.
     */
    public function test_a_row_with_no_expenses_column_reports_none(): void
    {
        $file = $this->file($this->party('customer'));
        $this->spend($file, 450);

        $bare = (object) [
            'status' => 'in_office',
            'customer_amount' => 9000,
            'returned_amount' => null,
            'vendor_id' => null,
            'vendor_amount' => null,
            'vendor_returned_on' => null,
            'vendor_returned_amount' => null,
        ];

        $this->assertSame(0.0, WorkFileModel::rowTotals($bare)['expenses']);
    }

    /**
     * The one place this parts company with the vendor figure.
     *
     * A cancelled file charged nobody and owes no vendor, so both of those are
     * nothing — but a challan paid before it was cancelled is money that really
     * left the till, and a report that quietly forgets it is the report this
     * whole feature exists to stop.
     */
    public function test_money_spent_before_a_cancellation_is_still_money_spent(): void
    {
        $file = $this->file($this->party('customer'), ['status' => 'cancelled']);

        $this->spend($file, 450);

        $totals = WorkFileModel::rowTotals($this->rowFor($file));

        $this->assertSame(0.0, $totals['billed'], 'a cancelled file charged nobody');
        $this->assertSame(450.0, $totals['cost'], 'but the challan was still paid');
        $this->assertSame(-450.0, $totals['margin'], 'which is a loss, and says so');
    }

    // ------------------------------------------------------- the reports

    /**
     * The dashboard figure. It reports the month's margin rather than its cost,
     * so the expense shows up as the margin falling by what was paid.
     */
    public function test_the_dashboard_margin_drops_by_what_was_paid_out(): void
    {
        $customer = $this->party('customer');
        $file = $this->file($customer, [
            'vendor_id' => $this->party('vendor')->id,
            'vendor_amount' => 5000,
        ]);

        // The dashboard counts the current month, so the file has to be in it.
        $file->received_date = now()->toDateString();
        $file->save();

        $before = WorkFileModel::summary();

        $this->spend($file, 450, now()->toDateString());

        $after = WorkFileModel::summary();

        $this->assertEqualsWithDelta(
            -450.0,
            (float) $after['month_margin'] - (float) $before['month_margin'],
            0.01,
            'the month\x27s margin did not fall by the expense'
        );
    }

    public function test_the_profit_report_counts_them_too(): void
    {
        $customer = $this->party('customer');
        $file = $this->file($customer, [
            'vendor_id' => $this->party('vendor')->id,
            'vendor_amount' => 5000,
        ]);

        $mine = fn () => collect(WorkFileModel::profitBy('customer', null, null))
            ->firstWhere('group_key', (string) $customer->id);

        $before = $mine();
        $this->spend($file, 450);
        $after = $mine();

        $this->assertNotNull($after, 'the customer is on the profit report');
        $this->assertEqualsWithDelta(450.0, (float) $after->cost - (float) $before->cost, 0.01);
        $this->assertEqualsWithDelta(-450.0, (float) $after->margin - (float) $before->margin, 0.01);
    }

    // ---------------------------------------------------------- the ledgers

    /**
     * The rule most worth testing, because breaking it is silent.
     *
     * This money went out of the till. It is owed by nobody and to nobody, so
     * no party ledger may move — and a stray entry here would quietly change a
     * balance somebody is going to be asked to settle.
     */
    public function test_an_expense_moves_nobody_s_ledger(): void
    {
        $customer = $this->party('customer');
        $vendor = $this->party('vendor');

        $file = $this->file($customer, ['vendor_id' => $vendor->id, 'vendor_amount' => 5000]);
        $file->syncLedger();

        $customerBefore = \App\Models\PartyLedgerModel::currentBalance($customer->id);
        $vendorBefore = \App\Models\PartyLedgerModel::currentBalance($vendor->id);
        $entriesBefore = \App\Models\PartyLedgerModel::where('work_file_id', $file->id)->count();

        $this->spend($file, 450);
        // Even when the file is re-synced afterwards, which is what every edit
        // to it does.
        WorkFileModel::find($file->id)->syncLedger();

        $this->assertSame($customerBefore, \App\Models\PartyLedgerModel::currentBalance($customer->id));
        $this->assertSame($vendorBefore, \App\Models\PartyLedgerModel::currentBalance($vendor->id));
        $this->assertSame(
            $entriesBefore,
            \App\Models\PartyLedgerModel::where('work_file_id', $file->id)->count(),
            'an expense wrote a ledger entry'
        );
    }

    // ------------------------------------------------------------ recording

    private function edit(WorkFileModel $file, array $payload)
    {
        return $this->actingAs($this->admin())->post('/admin/file/edit/'.$file->id, array_merge([
            'file_no' => $file->file_no,
            'received_date' => '2026-09-01',
            'status' => $file->status,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
        ], $payload));
    }

    public function test_an_expense_can_be_recorded_on_a_file(): void
    {
        $file = $this->file($this->party('customer'));

        $this->edit($file, [
            'new_expenses' => [[
                'expense_type_id' => ExpenseTypeModel::where('name', 'Affidavit')->value('id'),
                'amount' => '150.50',
                'spent_on' => '2026-09-03',
                'remark' => 'Notary at Muzaffarpur',
            ]],
        ]);

        $paid = WorkFileExpenseModel::where('work_file_id', $file->id)->get();

        $this->assertCount(1, $paid);
        $this->assertSame('150.50', (string) $paid[0]->amount);
        $this->assertSame('Notary at Muzaffarpur', $paid[0]->remark);
        $this->assertSame('2026-09-03', $paid[0]->spent_on->format('Y-m-d'));
    }

    public function test_an_expense_can_be_corrected_and_taken_off(): void
    {
        $file = $this->file($this->party('customer'));
        $expense = $this->spend($file, 300);

        $this->edit($file, [
            'expenses' => [$expense->id => [
                'expense_type_id' => $expense->expense_type_id,
                'amount' => '325',
                'spent_on' => '2026-09-02',
                'remark' => 'Corrected',
            ]],
        ]);

        $this->assertSame('325.00', (string) $expense->fresh()->amount);

        $this->edit($file, ['remove_expenses' => [$expense->id]]);

        $this->assertNull($expense->fresh(), 'it was not taken off');
        $this->assertSame(0.0, WorkFileModel::find($file->id)->paidOut());
    }

    /**
     * The ids arrive in the form body. An expense id belonging to another file
     * must not be editable or deletable from a page that has no business with
     * it.
     */
    public function test_an_expense_on_another_file_is_untouchable(): void
    {
        $mine = $this->file($this->party('customer'));
        $theirs = $this->file($this->party('customer'));

        $expense = $this->spend($theirs, 300);

        $this->edit($mine, [
            'expenses' => [$expense->id => [
                'expense_type_id' => $expense->expense_type_id,
                'amount' => '9999',
                'spent_on' => '2026-09-02',
                'remark' => 'Reached across',
            ]],
        ]);

        $this->assertSame('300.00', (string) $expense->fresh()->amount, 'another file edited it');

        $this->edit($mine, ['remove_expenses' => [$expense->id]]);

        $this->assertNotNull($expense->fresh(), 'another file deleted it');
    }

    public static function badExpenses(): array
    {
        return [
            'nothing' => ['0'],
            'a negative' => ['-50'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badExpenses')]
    public function test_an_amount_that_is_not_money_is_refused(string $amount): void
    {
        $file = $this->file($this->party('customer'));

        $this->edit($file, [
            'new_expenses' => [[
                'expense_type_id' => ExpenseTypeModel::where('name', 'Other')->value('id'),
                'amount' => $amount,
                'spent_on' => '2026-09-03',
            ]],
        ])->assertSessionHasErrors('new_expenses.0.amount');

        $this->assertSame(0, WorkFileExpenseModel::where('work_file_id', $file->id)->count());
    }

    public function test_money_cannot_be_spent_in_the_future(): void
    {
        $file = $this->file($this->party('customer'));

        $this->edit($file, [
            'new_expenses' => [[
                'expense_type_id' => ExpenseTypeModel::where('name', 'Other')->value('id'),
                'amount' => '100',
                'spent_on' => now()->addDay()->toDateString(),
            ]],
        ])->assertSessionHasErrors('new_expenses.0.spent_on');

        $this->assertSame(0, WorkFileExpenseModel::where('work_file_id', $file->id)->count());
    }

    // -------------------------------------------------------- the reporting

    private function report(array $query = [])
    {
        $url = route('report.expenses').($query ? '?'.http_build_query($query) : '');

        return $this->actingAs($this->admin())->getJson($url)->assertOk();
    }

    public function test_the_report_lists_every_expense_with_the_file_it_was_on(): void
    {
        $customer = $this->party('customer');
        $file = $this->file($customer);

        $this->spend($file, 300, '2026-09-02');

        $row = collect($this->report()->json('props.rows'))
            ->firstWhere('file_no', $file->file_no);

        $this->assertNotNull($row, 'the expense is on the report');
        $this->assertSame('Transfer Challan', $row['type_name']);
        $this->assertSame('02-09-2026', $row['spent_on']);
        $this->assertEquals(300, $row['amount']);
        $this->assertSame($customer->name, $row['customer_name']);
    }

    public function test_the_report_totals_what_was_paid_out(): void
    {
        $file = $this->file($this->party('customer'));

        $before = (float) $this->report()->json('page.total');

        $this->spend($file, 300);
        $this->spend($file, 150);

        $after = $this->report();

        $this->assertEqualsWithDelta($before + 450, (float) $after->json('page.total'), 0.01);
    }

    public function test_the_report_narrows_to_a_kind(): void
    {
        $file = $this->file($this->party('customer'));

        $challan = ExpenseTypeModel::where('name', 'Transfer Challan')->value('id');

        $this->spend($file, 300);

        $other = new WorkFileExpenseModel;
        $other->work_file_id = $file->id;
        $other->expense_type_id = ExpenseTypeModel::where('name', 'Affidavit')->value('id');
        $other->amount = 150;
        $other->spent_on = '2026-09-02';
        $other->save();

        $kinds = collect($this->report(['expense_type_id' => $challan])->json('props.rows'))
            ->pluck('type_name')->unique()->values();

        $this->assertSame(['Transfer Challan'], $kinds->all());
    }

    public function test_the_report_narrows_to_a_period(): void
    {
        $file = $this->file($this->party('customer'));

        $this->spend($file, 300, '2026-01-10');
        $this->spend($file, 150, '2026-06-10');

        $rows = collect($this->report(['from' => '2026-06-01', 'to' => '2026-06-30'])->json('props.rows'))
            ->where('file_no', $file->file_no);

        $this->assertCount(1, $rows, 'only what was paid in June');
        $this->assertEquals(150, $rows->first()['amount']);
    }

    public function test_the_report_narrows_to_one_customer(): void
    {
        $mine = $this->party('customer');
        $theirs = $this->party('customer');

        $this->spend($this->file($mine), 300);
        $this->spend($this->file($theirs), 150);

        $names = collect($this->report(['party_id' => $mine->id])->json('props.rows'))
            ->pluck('customer_name')->unique()->values();

        $this->assertSame([$mine->name], $names->all());
    }

    public function test_the_report_is_banded_by_kind(): void
    {
        $this->spend($this->file($this->party('customer')), 300);

        $props = $this->report()->json('props');

        $this->assertSame('type_id', $props['groupBy'], 'banded so each kind subtotals');
        $this->assertArrayHasKey('amount', $props['totals']);
    }

    // ------------------------------------------------------ the kinds screen

    public function test_the_types_screen_says_what_each_kind_has_cost(): void
    {
        $file = $this->file($this->party('customer'));
        $this->spend($file, 300);
        $this->spend($file, 150);

        $row = collect($this->actingAs($this->admin())
            ->getJson(route('expensetype.index'))->assertOk()->json('props.rows'))
            ->firstWhere('name', 'Transfer Challan');

        $this->assertNotNull($row);
        $this->assertSame(2, $row['used']);
        $this->assertEquals(450, $row['spent']);
    }

    public function test_a_kind_can_be_added_and_retired(): void
    {
        $name = 'Notary Fee '.uniqid();

        $this->actingAs($this->admin())
            ->post(route('expensetype.index'), ['name' => $name, 'default_amount' => '120'])
            ->assertRedirect(route('expensetype.index'));

        $type = ExpenseTypeModel::where('name', $name)->firstOrFail();

        $this->assertSame('120.00', (string) $type->default_amount);
        $this->assertTrue((bool) $type->is_active);

        // Saving the edit form without the switch retires it.
        $this->actingAs($this->admin())
            ->post(route('expensetype.edit', $type->id), ['name' => $name])
            ->assertRedirect(route('expensetype.index'));

        $this->assertFalse((bool) $type->fresh()->is_active);
    }

    public function test_two_kinds_cannot_share_a_name(): void
    {
        $this->actingAs($this->admin())
            ->post(route('expensetype.index'), ['name' => 'Affidavit'])
            ->assertSessionHasErrors('name');
    }

    /**
     * Deleting a kind money has been spent under would take the name of the
     * thing that was paid off the file, the report and the margin.
     */
    public function test_a_kind_with_money_behind_it_cannot_be_deleted(): void
    {
        $file = $this->file($this->party('customer'));
        $this->spend($file, 300);

        $type = ExpenseTypeModel::where('name', 'Transfer Challan')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('expensetype.delete', $type->id))
            ->assertSessionHas('error');

        $this->assertNotNull($type->fresh(), 'the kind was deleted out from under its expenses');
    }

    public function test_a_kind_nothing_was_spent_under_can_be_deleted(): void
    {
        $type = new ExpenseTypeModel;
        $type->name = 'Unused '.uniqid();
        $type->is_active = 1;
        $type->save();

        $this->actingAs($this->admin())
            ->post(route('expensetype.delete', $type->id))
            ->assertRedirect(route('expensetype.index'));

        $this->assertNull($type->fresh());
    }

    public function test_the_edit_screen_offers_what_is_already_recorded(): void
    {
        $file = $this->file($this->party('customer'));
        $this->spend($file, 300);

        $props = $this->actingAs($this->admin())
            ->getJson('/admin/file/edit/'.$file->id)
            ->assertOk()
            ->json('props');

        $this->assertCount(1, $props['expenses']);
        // JSON decodes 300.00 as the integer 300, so the figure is compared
        // as a number rather than as a float that happens to be whole.
        $this->assertEquals(300.0, $props['expenses'][0]['amount']);
        $this->assertNotEmpty($props['expenseTypes'], 'and the kinds to add another under');
    }
}

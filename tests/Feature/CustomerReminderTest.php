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
 * What the server hands the two customer reminders.
 *
 * The messages themselves are written in the browser and tested there
 * (customer-share.test.js). This is the other half: that each customer's rows
 * carry the number the chat opens on and the works by name, that the statement
 * offers a reminder only to a customer who owes, and that it reminds them of
 * what they owe today rather than of whatever period is on screen.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class CustomerReminderTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Reminder Admin';
        $this->admin->email = 'reminder-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function party(string $type = 'customer', ?string $whatsapp = null): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' reminded';
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->whatsapp = $whatsapp;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * An approved folder, charged in full to the customer.
     *
     * @param  array<int, array{0: WorkTypeModel, 1: string}>  $works  [type, status]
     */
    private function file(PartyModel $customer, array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RM-'.uniqid();
        $file->received_date = now()->subDays(30)->toDateString();
        $file->registration_no = 'BR01RM'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = 3000 * count($works);
        $file->status = WorkFileModel::APPROVED;
        $file->save();

        foreach ($works as [$type, $status]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $status === WorkFileModel::CANCELLED ? 0 : 3000;
            $item->status = $status;
            $item->approved_on = $status === WorkFileModel::APPROVED ? now()->subDays(10)->toDateString() : null;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function uncollected(): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('report.uncollected'))->assertOk()->json('props');
    }

    private function statement(PartyModel $party, array $query = []): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('party.statement', ['id' => $party->id] + $query))->assertOk()->json('page');
    }

    // ------------------------------------------------------ Not Yet Collected

    public function test_the_report_is_banded_by_customer_for_the_message_on_each_band(): void
    {
        $props = $this->uncollected();

        $this->assertSame('customer_id', $props['groupBy']);
        $this->assertSame('customer', $props['groupLabel']);
        $this->assertSame(now()->format('d-m-Y'), $props['todayLabel']);
    }

    public function test_each_row_carries_the_number_the_chat_opens_on(): void
    {
        $customer = $this->party();
        $file = $this->file($customer, [[$this->tr, WorkFileModel::APPROVED]]);

        $row = collect($this->uncollected()['rows'])->firstWhere('id', $file->id);

        $this->assertSame($customer->mobile, $row['customer_mobile']);
    }

    /** A separate WhatsApp number wins, as it does on the statement. */
    public function test_a_whatsapp_number_is_preferred_to_the_mobile(): void
    {
        $customer = $this->party('customer', '9431012345');
        $file = $this->file($customer, [[$this->tr, WorkFileModel::APPROVED]]);

        $row = collect($this->uncollected()['rows'])->firstWhere('id', $file->id);

        $this->assertSame('9431012345', $row['customer_mobile']);
    }

    /** "BR01AB1234 — TR, HPA": the works by name, but never one that was cancelled. */
    public function test_each_row_names_its_works_leaving_out_the_cancelled(): void
    {
        $file = $this->file($this->party(), [
            [$this->tr, WorkFileModel::APPROVED],
            [$this->hpa, WorkFileModel::CANCELLED],
        ]);

        $row = collect($this->uncollected()['rows'])->firstWhere('id', $file->id);

        $this->assertSame($this->tr->name, $row['works']);
    }

    // ---------------------------------------------------------- the statement

    public function test_a_customer_who_owes_is_offered_a_reminder_of_it(): void
    {
        $customer = $this->party();
        $this->file($customer, [[$this->tr, WorkFileModel::APPROVED], [$this->hpa, WorkFileModel::APPROVED]]);

        $reminder = $this->statement($customer)['reminder'];

        $this->assertSame($customer->name, $reminder['name']);
        $this->assertSame($customer->mobile, $reminder['mobile']);
        $this->assertEquals(6000, $reminder['balance']);
    }

    public function test_the_page_mounts_it(): void
    {
        $customer = $this->party();
        $this->file($customer, [[$this->tr, WorkFileModel::APPROVED]]);

        $this->actingAs($this->admin)
            ->get(route('party.statement', $customer->id))
            ->assertOk()
            ->assertSee('data-vue="vue-balance-reminder"', false);
    }

    public function test_a_customer_who_owes_nothing_is_offered_nothing(): void
    {
        $customer = $this->party();
        $this->file($customer, [[$this->tr, WorkFileModel::APPROVED]]);

        DB::table('party_ledger')->insert([
            'party_id' => $customer->id,
            'txn_date' => now()->toDateString(),
            'entry_type' => 'credit',
            'amount' => 3000,
            'payment_mode' => 'cash',
            'particular' => 'Paid in full',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->statement($customer)['reminder']);

        $this->actingAs($this->admin)
            ->get(route('party.statement', $customer->id))
            ->assertDontSee('data-vue="vue-balance-reminder"', false);
    }

    /** What the office owes a vendor is not something to remind them of. */
    public function test_a_vendor_is_never_offered_one(): void
    {
        $vendor = $this->party('vendor');

        DB::table('party_ledger')->insert([
            'party_id' => $vendor->id,
            'txn_date' => now()->toDateString(),
            'entry_type' => 'debit',
            'amount' => 5000,
            'particular' => 'Advance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->statement($vendor)['reminder']);
    }

    /**
     * The statement narrowed to a month before the work was charged shows a
     * closing balance of nothing. The reminder is still for what is owed today.
     */
    public function test_it_reminds_of_todays_balance_whatever_period_is_shown(): void
    {
        $customer = $this->party();
        $this->file($customer, [[$this->tr, WorkFileModel::APPROVED]]);

        $page = $this->statement($customer, ['from' => '2020-01-01', 'to' => '2020-01-31']);

        $this->assertEquals(0, $page['closing']);
        $this->assertEquals(3000, $page['reminder']['balance']);
    }

    // ---------------------------------------------------------- the dashboard

    /** One click from the name on the tile to the customer's statement, where the reminder is. */
    public function test_owing_longest_opens_that_customers_statement(): void
    {
        $oldest = PartyModel::oldestUnpaid();

        if (! $oldest) {
            $customer = $this->party();
            $this->file($customer, [[$this->tr, WorkFileModel::APPROVED]]);
            $oldest = PartyModel::oldestUnpaid();
        }

        $tile = collect($this->actingAs($this->admin)
            ->getJson(url('admin/dashboard'))->assertOk()->json('props.tiles'))
            ->firstWhere('label', 'Owing Longest');

        $this->assertSame(route('party.statement', $oldest['id']), $tile['href']);
    }
}

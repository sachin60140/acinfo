<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Papers cannot go back before they came in, nor come back from a vendor
 * before they went out.
 *
 * Found in the health check. A refund is dated the day the papers went back
 * and the charge the day they came in, and the ledger reads a file's refund
 * against that file's charges before it. Return to Customer took any date: a
 * file received on the 20th and returned "on the 2nd" had a refund that found
 * nothing on its own file, went on account and paid off the customer's oldest
 * other bill. The balance was right, but Not Yet Collected, the Collection
 * List and the dashboard chased the file the customer had taken back and
 * called the unpaid one settled. The edit screen could do the same by moving
 * the received date past the return, and a vendor's take-back dated before
 * the work went out — or a Given On date moved past the take-back — did it
 * to the vendor's statement.
 *
 * The ledger's rule is the owner's and stays; the dates that break it are
 * refused where they are typed.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReturnDatesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Return Dates Admin';
        $this->admin->email = 'return-dates-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer');
        $this->sharma = $this->party('vendor', 'Sharma');

        $this->tr = $this->workType('TR');
        $this->hpt = $this->workType('HPT');
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '9'.random_int(600000000, 999999999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /**
     * A folder received on $received, its works each [type, charged, rate,
     * day given]: given to Sharma where a rate is set, in the office if not.
     *
     * @param  array<int, array>  $works
     */
    private function file(string $received, array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RD-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01RD'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $charged, $rate, $given] = $work + [2 => null, 3 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $rate !== null ? $this->sharma->id : null;
            $item->vendor_amount = $rate;
            $item->vendor_date = $given;
            $item->status = $rate !== null ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** A folder from before works named their vendor: the vendor is on the folder alone. */
    private function olderFolder(string $received, string $given): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RDO-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01RO'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000;
        $file->vendor_id = $this->sharma->id;
        $file->vendor_amount = 1000;
        $file->vendor_date = $given;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = 3000;
        $item->status = WorkFileModel::DISPATCHED;
        $item->save();

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function returnToCustomer(WorkFileModel $file, string $on)
    {
        return $this->actingAs($this->admin)
            ->from(route('workfile.customerreturn'))
            ->post(route('workfile.customerreturn'), [
                'returned_on' => $on,
                'files' => [$file->id],
                'remark' => 'Customer took the papers back',
            ]);
    }

    private function takeBack(WorkFileModel $file, string $on)
    {
        return $this->actingAs($this->admin)
            ->from(route('workfile.vendorreturn'))
            ->post(route('workfile.vendorreturn'), [
                'returned_on' => $on,
                'files' => [$file->id.':'.$this->sharma->id],
                'remark' => 'Could not do it',
            ]);
    }

    /** The edit page's save, as drawn, with anything typed over it. */
    private function edit(WorkFileModel $file, array $typed)
    {
        $page = $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props');

        $values = $page['values'];

        return $this->actingAs($this->admin)
            ->from(route('workfile.edit', $file->id))
            ->post(route('workfile.edit', $file->id), array_merge([
                'file_no' => $values['file_no'],
                'received_date' => date('Y-m-d', strtotime($file->received_date)),
                'work_type_id' => $values['work_type_id'],
                'registration_no' => $values['registration_no'],
                'customer_id' => $values['customer_id'],
                'customer_amount' => $values['customer_amount'],
                'vendor_id' => $values['vendor_id'],
                'vendor_amount' => $values['vendor_amount'],
                'vendor_date' => $file->vendor_date,
                'status' => $values['status'],
                'drawn' => $page['drawn'],
                'was_status' => $page['wasStatus'],
            ], $typed));
    }

    /** @return array<int, float> file id => still owed, by the ledger's own rule */
    private function owed(PartyModel $party, string $chargeSide = 'debit'): array
    {
        $owed = PartyLedgerModel::outstandingByFile([$party->id], $chargeSide)[$party->id] ?? [];
        ksort($owed);

        return array_map(fn ($due) => round((float) $due, 2), $owed);
    }

    private function entries(WorkFileModel $file, string $role): int
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', $role)->count();
    }

    // ------------------------------------------------- Return to Customer

    /** The reported case: received on the 20th, returned "on the 2nd". */
    public function test_a_return_dated_before_the_papers_came_in_is_refused(): void
    {
        $older = $this->file('2026-07-01', [[$this->tr, 1000]]);
        $late = $this->file('2026-09-20', [[$this->tr, 3000]]);

        $this->returnToCustomer($late, '2026-09-02')
            ->assertRedirect(route('workfile.customerreturn'))
            ->assertSessionHas('error', fn ($error) => str_contains($error, $late->file_no)
                && str_contains($error, '20-09-2026'));

        $this->assertSame(WorkFileModel::IN_OFFICE, $late->fresh()->status, 'the papers went back anyway');
        $this->assertSame(0, $this->entries($late, 'customer_return'), 'a refund was written');
        $this->assertSame([$older->id => 1000.0, $late->id => 3000.0], $this->owed($this->customer));
    }

    /** The day they came in is a day they can go back, and the refund settles its own file. */
    public function test_a_return_on_the_day_they_came_in_settles_its_own_file(): void
    {
        $older = $this->file('2026-07-01', [[$this->tr, 1000]]);
        $late = $this->file('2026-09-20', [[$this->tr, 3000]]);

        $this->returnToCustomer($late, '2026-09-20')->assertSessionHas('success');

        $this->assertSame(WorkFileModel::RETURNED, $late->fresh()->status);
        $this->assertSame([$older->id => 1000.0], $this->owed($this->customer), 'the refund paid off another bill');
    }

    // -------------------------------------------------------- the edit screen

    public function test_the_received_date_cannot_be_moved_past_the_return(): void
    {
        $older = $this->file('2026-07-01', [[$this->tr, 1000]]);
        $file = $this->file('2026-09-01', [[$this->tr, 3000]]);
        $this->returnToCustomer($file, '2026-09-10')->assertSessionHas('success');

        $this->edit($file->fresh(), ['received_date' => '2026-09-15'])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHasErrors('received_date')
            ->assertSessionHas('error', fn ($error) => str_contains($error, '10-09-2026'));

        $this->assertSame('2026-09-01', $file->fresh()->received_date, 'the received date moved');
        $this->assertSame([$older->id => 1000.0], $this->owed($this->customer), 'the refund paid off another bill');

        // Up to the day they went back is still a correction it can make.
        $this->edit($file->fresh(), ['received_date' => '2026-09-10'])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-10', $file->fresh()->received_date);
        $this->assertSame([$older->id => 1000.0], $this->owed($this->customer));
    }

    /** Returned on this screen, the return is dated today: papers not in yet cannot go. */
    public function test_returning_on_the_edit_screen_is_refused_for_papers_not_yet_in(): void
    {
        $file = $this->file(now()->addDays(5)->toDateString(), [[$this->tr, 3000]]);

        $this->edit($file, ['status' => WorkFileModel::RETURNED])
            ->assertSessionHasErrors('received_date');

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->fresh()->status);
        $this->assertSame(0, $this->entries($file, 'customer_return'));
    }

    /**
     * A file already dated that way, from before this, still saves when the
     * save leaves both dates alone: it is the return date that is usually
     * wrong, and no screen can put that right. The audit names it instead.
     */
    public function test_a_file_already_dated_so_can_still_be_saved(): void
    {
        $file = $this->file('2026-09-01', [[$this->tr, 3000]]);
        $this->returnToCustomer($file, '2026-09-10')->assertSessionHas('success');
        DB::table('work_file')->where('id', $file->id)->update(['received_date' => '2026-09-20']);

        $this->edit($file->fresh(), ['registration_no' => 'BR01RD0001'])->assertSessionHasNoErrors();

        $this->assertSame('BR01RD0001', $file->fresh()->registration_no);
    }

    /**
     * The vendor's side of the same: the Given On box re-dates their credit,
     * and moved past the take-back it lands after the reversal, which then
     * paid off another of their bills.
     */
    public function test_the_given_on_date_cannot_be_moved_past_the_take_back(): void
    {
        $older = $this->file('2026-07-01', [[$this->tr, 1000, 500, '2026-07-01']]);
        $file = $this->file('2026-09-01', [[$this->tr, 3000, 800, '2026-09-02']]);
        $this->takeBack($file, '2026-09-10')->assertSessionHas('success');

        $this->edit($file->fresh(), ['vendor_date' => '2026-09-15'])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHasErrors('vendor_date')
            ->assertSessionHas('error', fn ($error) => str_contains($error, '10-09-2026'));

        $this->assertSame('2026-09-02', $file->items()->first()->vendor_date, 'the day it went out moved');
        $this->assertSame([$older->id => 500.0], $this->owed($this->sharma, 'credit'), 'the reversal paid off another bill');

        // Up to the day it came back is still a correction it can make.
        $this->edit($file->fresh(), ['vendor_date' => '2026-09-10'])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-10', $file->items()->first()->vendor_date);
        $this->assertSame([$older->id => 500.0], $this->owed($this->sharma, 'credit'));
    }

    /** An older folder's day is its own, and so is the day it came back. */
    public function test_an_older_folders_given_on_date_cannot_be_moved_past_the_take_back(): void
    {
        $file = $this->olderFolder('2026-09-01', '2026-09-05');
        $this->takeBack($file, '2026-09-08')->assertSessionHas('success');

        $this->edit($file->fresh(), ['vendor_date' => '2026-09-12'])
            ->assertSessionHasErrors('vendor_date');

        $this->assertSame('2026-09-05', $file->fresh()->vendor_date);
    }

    // --------------------------------------------------------- the status board

    /** The board's return is dated today too. */
    public function test_the_board_will_not_return_papers_not_yet_in(): void
    {
        $file = $this->file(now()->addDays(5)->toDateString(), [[$this->tr, 3000]]);
        $work = $file->items()->first();

        $this->actingAs($this->admin)->from(route('workfile.status'))->post(route('workfile.status'), [
            'statuses' => [$work->id => WorkFileModel::RETURNED],
            'was' => [$work->id => WorkFileModel::IN_OFFICE],
            'remarks' => [$work->id => 'Customer took the papers back'],
        ])->assertSessionHas('error', fn ($error) => str_contains($error, $file->file_no));

        $this->assertSame(WorkFileModel::IN_OFFICE, $work->fresh()->status);
        $this->assertSame(0, $this->entries($file, 'customer_return'));
    }

    // ------------------------------------------------------ taken back from vendor

    /** The vendor's side of the reported case: their reversal paid off another of their bills. */
    public function test_a_take_back_dated_before_the_work_went_out_is_refused(): void
    {
        $older = $this->file('2026-07-01', [[$this->tr, 1000, 500, '2026-07-01']]);
        $late = $this->file('2026-09-01', [[$this->tr, 3000, 800, '2026-09-15']]);

        $this->takeBack($late, '2026-09-10')
            ->assertRedirect(route('workfile.vendorreturn'))
            ->assertSessionHas('error', fn ($error) => str_contains($error, $late->file_no)
                && str_contains($error, '15-09-2026'));

        $this->assertSame(WorkFileModel::DISPATCHED, $late->items()->first()->status, 'the work came back anyway');
        $this->assertNull($late->items()->first()->vendor_returned_on);
        $this->assertSame(0, $this->entries($late, 'vendor_return'), 'a reversal was written');
        $this->assertSame([$older->id => 500.0, $late->id => 800.0], $this->owed($this->sharma, 'credit'));

        // The day it went out is a day it can come back, against its own file.
        $this->takeBack($late, '2026-09-15')->assertSessionHas('success');

        $this->assertSame([$older->id => 500.0], $this->owed($this->sharma, 'credit'));
    }

    /** Two works of theirs given on two days: neither comes back before it went. */
    public function test_each_work_is_checked_against_its_own_day(): void
    {
        $file = $this->file('2026-09-01', [
            [$this->tr, 3000, 800, '2026-09-02'],
            [$this->hpt, 2000, 500, '2026-09-15'],
        ]);

        $this->takeBack($file, '2026-09-10')
            ->assertSessionHas('error', fn ($error) => str_contains($error, '15-09-2026'));

        $this->assertSame(0, $file->items()->whereNotNull('vendor_returned_on')->count());
    }

    /** No day written for the hand-over: the credit is dated the day the papers came in, and so is the floor. */
    public function test_work_given_with_no_day_is_checked_against_the_day_it_came_in(): void
    {
        $file = $this->file('2026-09-20', [[$this->tr, 3000, 800, null]]);

        $this->takeBack($file, '2026-09-10')
            ->assertSessionHas('error', fn ($error) => str_contains($error, '20-09-2026'));

        $this->assertSame(0, $this->entries($file, 'vendor_return'));
    }

    /** An older folder carries its day on itself. */
    public function test_an_older_folder_is_checked_against_its_own_day(): void
    {
        $file = $this->olderFolder('2026-09-01', '2026-09-05');

        $this->takeBack($file, '2026-09-03')
            ->assertSessionHas('error', fn ($error) => str_contains($error, '05-09-2026'));

        $this->assertNull($file->fresh()->vendor_returned_on);

        $this->takeBack($file, '2026-09-05')->assertSessionHas('success');

        $this->assertSame('2026-09-05', $file->fresh()->vendor_returned_on);
    }

    // --------------------------------------------------------------- the audit

    public function test_the_audit_names_files_already_dated_so(): void
    {
        $returned = $this->file('2026-09-01', [[$this->tr, 3000]]);
        $this->returnToCustomer($returned, '2026-09-10')->assertSessionHas('success');
        DB::table('work_file')->where('id', $returned->id)->update(['received_date' => '2026-09-20']);

        $back = $this->file('2026-09-01', [[$this->tr, 3000, 800, '2026-09-02']]);
        $this->takeBack($back, '2026-09-10')->assertSessionHas('success');
        DB::table('work_file_item')->where('work_file_id', $back->id)->update(['vendor_date' => '2026-09-15']);

        $fine = $this->file('2026-09-01', [[$this->tr, 3000]]);
        $this->returnToCustomer($fine, '2026-09-01')->assertSessionHas('success');

        /*
         * One of two works back before it went out, but the vendor's credit
         * is dated from the earlier one, so their reversal still settles this
         * file. The screens refuse it now; the audit names only harm.
         */
        $harmless = $this->file('2026-09-01', [
            [$this->tr, 3000, 800, '2026-09-02'],
            [$this->hpt, 2000, 500, '2026-09-02'],
        ]);
        $this->takeBack($harmless, '2026-09-10')->assertSessionHas('success');
        DB::table('work_file_item')->where('work_file_id', $harmless->id)->where('work_type_id', $this->hpt->id)
            ->update(['vendor_date' => '2026-09-15']);

        Artisan::call('files:audit');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/'.preg_quote($returned->file_no, '/').'.*went back to the customer on 10-09-2026, before it came in on 20-09-2026/', $output);
        $this->assertMatchesRegularExpression('/'.preg_quote($back->file_no, '/').'.*came back from vendor '.$this->sharma->id.' on 10-09-2026, before it went to them on 15-09-2026/', $output);
        $this->assertDoesNotMatchRegularExpression('/'.preg_quote($fine->file_no, '/').'.*before it/', $output);
        $this->assertDoesNotMatchRegularExpression('/'.preg_quote($harmless->file_no, '/').'.*before it/', $output);
    }
}

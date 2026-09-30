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
 * Papers Returned by Vendor, one vendor's part of a folder at a time.
 *
 * Asked for by the owner on 2026-09-28, with the rule that a vendor's is only
 * the work they were given. Taking a folder back:
 *
 *  - moved every open work on it to In Office — the office's own, and another
 *    vendor's;
 *  - listed the whole folder's works, and capped a part reversal at the whole
 *    folder's rate, the office's included;
 *  - could not take back a folder split between two vendors at all;
 *  - and a part reversal on a folder a vendor held part of was ignored by
 *    their statement, which reversed all of theirs.
 *
 * A part reversal lives on the works it was for now, and the folder's figure
 * is theirs added up.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class TakeBackPerVendorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Take Back Admin';
        $this->admin->email = 'take-back-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Customer');
        $this->sharma = $this->party('vendor', 'Sharma');
        $this->shailendra = $this->party('vendor', 'Shailendra');

        $this->hpt = $this->workType('HPT');
        $this->tr = $this->workType('TR');
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
     * A folder and its works, each [type, charged, vendor or null, rate, status, approved on].
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-TBV-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR06TB'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $charged, $vendor, $rate, $status, $approved] = $work + [3 => null, 4 => null, 5 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $vendor?->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = $vendor ? '2026-09-02' : null;
            $item->status = $status ?? ($vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE);
            $item->approved_on = $approved;
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** HPT with Sharma at 1250; TR kept in the office, at the RTO, a rate of 800 typed on it. */
    private function partlyGiven(): WorkFileModel
    {
        return $this->folder([
            [$this->hpt, 2000, $this->sharma, 1250],
            [$this->tr, 3000, null, 800, 'under_verification'],
        ]);
    }

    /** HPT with Sharma at 1000, TR with Shailendra at 1500. */
    private function split(): WorkFileModel
    {
        return $this->folder([
            [$this->hpt, 2000, $this->sharma, 1000],
            [$this->tr, 3000, $this->shailendra, 1500],
        ]);
    }

    private function takeBack(array $files, array $amounts = [], string $on = '2026-09-10')
    {
        return $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => $on,
            'files' => $files,
            'amounts' => $amounts,
            'remark' => 'Could not do it',
        ]);
    }

    private function key(WorkFileModel $file, PartyModel $vendor): string
    {
        return $file->id.':'.$vendor->id;
    }

    /** @return array<int, float> vendor id => amount, of one role */
    private function lines(WorkFileModel $file, string $role): array
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', $role)
            ->pluck('amount', 'party_id')->map(fn ($amount) => (float) $amount)->all();
    }

    private function workStatus(WorkFileModel $file, WorkTypeModel $type): string
    {
        return $file->items()->where('work_type_id', $type->id)->value('status');
    }

    private function spent(WorkFileModel $file): float
    {
        return (float) WorkFileModel::whereKey($file->id)->selectRaw(WorkFileModel::SPENT.' as spent')->value('spent');
    }

    /** @return \Illuminate\Support\Collection<int, array> the take-back list's rows for one file */
    private function listed(WorkFileModel $file)
    {
        return collect($this->actingAs($this->admin)->getJson(route('workfile.vendorreturn'))->assertOk()->json('props.files'))
            ->where('id', $file->id)->values();
    }

    // ----------------------------------------------------------- the list

    public function test_the_list_shows_their_works_and_what_was_booked_to_them(): void
    {
        $file = $this->partlyGiven();

        $rows = $this->listed($file);

        $this->assertCount(1, $rows);
        $this->assertSame($this->key($file, $this->sharma), $rows[0]['key']);
        $this->assertSame($this->hpt->name, $rows[0]['work_type'], 'the office\'s transfer listed as theirs');
        $this->assertEquals(1250, $rows[0]['vendor_amount'], 'the office\'s rate counted as booked to them');
    }

    public function test_a_split_folder_is_listed_once_for_each_vendor(): void
    {
        $rows = $this->listed($this->split())->keyBy('vendor_id');

        $this->assertCount(2, $rows);
        $this->assertSame($this->hpt->name, $rows[$this->sharma->id]['work_type']);
        $this->assertEquals(1000, $rows[$this->sharma->id]['vendor_amount']);
        $this->assertSame($this->tr->name, $rows[$this->shailendra->id]['work_type']);
        $this->assertEquals(1500, $rows[$this->shailendra->id]['vendor_amount']);
    }

    // ------------------------------------------------------------- taking back

    public function test_only_their_work_comes_back(): void
    {
        $file = $this->partlyGiven();

        $this->takeBack([$this->key($file, $this->sharma)])->assertRedirect(route('workfile.index'));

        $this->assertSame(WorkFileModel::IN_OFFICE, $this->workStatus($file, $this->hpt));
        $this->assertSame('under_verification', $this->workStatus($file, $this->tr), 'the office\'s work at the RTO was knocked back');

        $this->assertSame([$this->sharma->id => 1250.0], $this->lines($file, 'vendor_return'));
        $this->assertCount(0, $this->listed($file));

        // The office's typed rate stays the file's cost.
        $this->assertEqualsWithDelta(800, $this->spent($file), 0.005);
    }

    public function test_a_split_folder_comes_back_one_vendor_at_a_time(): void
    {
        $file = $this->split();

        $this->takeBack([$this->key($file, $this->sharma)])->assertRedirect(route('workfile.index'));

        $this->assertSame(WorkFileModel::IN_OFFICE, $this->workStatus($file, $this->hpt));
        $this->assertSame(WorkFileModel::DISPATCHED, $this->workStatus($file, $this->tr), 'Shailendra\'s work came back with Sharma\'s');

        $this->assertSame([$this->sharma->id => 1000.0], $this->lines($file, 'vendor_return'));
        $this->assertSame([$this->shailendra->id], $this->listed($file)->pluck('vendor_id')->all());

        // Sharma's rate is off the cost; Shailendra's, still out, is on it.
        $this->assertEqualsWithDelta(1500, $this->spent($file), 0.005);
    }

    /** An older page posts the file's number: every vendor still holding work on it. */
    public function test_a_bare_file_number_takes_back_every_vendor(): void
    {
        $file = $this->split();

        $this->takeBack([$file->id])->assertRedirect(route('workfile.index'));

        $this->assertSame([$this->sharma->id => 1000.0, $this->shailendra->id => 1500.0], $this->lines($file, 'vendor_return'));
    }

    public function test_one_figure_for_a_folder_two_vendors_hold_is_refused(): void
    {
        $file = $this->split();

        $this->takeBack([$file->id], [$file->id => 400])
            ->assertSessionHas('error', fn ($said) => str_contains($said, "tick each vendor's row"));

        $this->assertSame([], $this->lines($file, 'vendor_return'), 'moved anyway');
        $this->assertSame(WorkFileModel::DISPATCHED, $this->workStatus($file, $this->hpt));
    }

    // ------------------------------------------------------------- the part

    public function test_a_part_is_capped_at_what_was_booked_to_them(): void
    {
        $file = $this->partlyGiven();

        // 1500 is under the folder's 2050, and over Sharma's 1250.
        $this->takeBack([$this->key($file, $this->sharma)], [$this->key($file, $this->sharma) => 1500])
            ->assertSessionHas('error');

        $this->assertSame([], $this->lines($file, 'vendor_return'));
    }

    /**
     * A part typed on a folder they held only part of: their statement
     * reversed all of theirs, and the reports the part. One figure now.
     */
    public function test_their_statement_reverses_the_part_typed(): void
    {
        $file = $this->partlyGiven();

        $this->takeBack([$this->key($file, $this->sharma)], [$this->key($file, $this->sharma) => 500])
            ->assertRedirect(route('workfile.index'));

        $this->assertSame([$this->sharma->id => 500.0], $this->lines($file, 'vendor_return'));

        // 1250 − 500 still Sharma's, and the office's 800.
        $this->assertEqualsWithDelta(1550, $this->spent($file), 0.005);
        $this->assertEqualsWithDelta(3450, $file->fresh()->margin(), 0.005);
    }

    public function test_a_part_on_a_split_folder_is_theirs_alone(): void
    {
        $file = $this->split();

        $this->takeBack([$this->key($file, $this->sharma)], [$this->key($file, $this->sharma) => 400])
            ->assertRedirect(route('workfile.index'));

        $this->assertSame([$this->sharma->id => 400.0], $this->lines($file, 'vendor_return'));
        $this->assertEqualsWithDelta(2100, $this->spent($file), 0.005);
        $this->assertEqualsWithDelta(2900, $file->fresh()->margin(), 0.005);

        // On the vendor-wise report: Sharma 600 still, Shailendra untouched.
        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'vendor']))->assertOk()->json('props.rows'))
            ->where('id', $file->id)->keyBy('party_id');

        $this->assertEquals(600, $rows[$this->sharma->id]['cost']);
        $this->assertEquals(1500, $rows[$this->shailendra->id]['cost']);
    }

    /** Several works back with a part: the work not yet approved takes it first. */
    public function test_the_part_lands_on_the_work_not_yet_approved(): void
    {
        $file = $this->folder([
            [$this->tr, 3000, $this->sharma, 600, WorkFileModel::APPROVED, '2026-09-05'],
            [$this->hpt, 2000, $this->sharma, 400],
        ]);

        $this->takeBack([$this->key($file, $this->sharma)], [$this->key($file, $this->sharma) => 400])
            ->assertRedirect(route('workfile.index'));

        $parts = $file->items()->pluck('vendor_returned_amount', 'work_type_id');

        $this->assertNull($parts[$this->hpt->id], 'the HPT that came back undone is not reversed in full');
        $this->assertEquals(0, $parts[$this->tr->id]);
        $this->assertSame([$this->sharma->id => 400.0], $this->lines($file, 'vendor_return'));
    }

    // -------------------------------------------------------- the edit screen

    /** One vendor's part back and another's out: the box would have moved both. */
    public function test_the_edit_screen_will_not_move_work_once_any_came_back(): void
    {
        $file = $this->split();
        $this->takeBack([$this->key($file, $this->sharma)]);
        $file = $file->fresh();

        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'registration_no' => $file->registration_no,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'vendor_id' => $this->shailendra->id,
            'vendor_amount' => $file->vendor_amount,
            'vendor_date' => '2026-09-02',
            'status' => $file->status,
            'remarks' => $file->remarks,
            'drawn' => $file->editFingerprint(),
        ])->assertSessionHas('error');

        $this->assertSame([$this->sharma->id => 1000.0], $this->lines($file, 'vendor_return'), 'Sharma\'s reversal went with the move');
    }

    // ------------------------------------------------------ what else reads it

    /** On Expenses, their own hand-back — not the office's rate with it. */
    public function test_the_expenses_report_reverses_only_their_rate(): void
    {
        $file = $this->partlyGiven();
        $this->takeBack([$this->key($file, $this->sharma)]);

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('report.expenses', ['vendor_id' => $this->sharma->id]))->assertOk()->json('props.rows'))
            ->where('file_id', $file->id);

        $this->assertEqualsWithDelta(0, $rows->sum('amount'), 0.005, 'the office\'s rate reversed under their name');

        // And a vehicle still costs what SPENT says.
        $plate = collect($this->actingAs($this->admin)
            ->getJson(route('report.expenses', ['vehicle' => $file->registration_no]))->assertOk()->json('props.rows'))
            ->where('file_id', $file->id);

        $this->assertEqualsWithDelta($this->spent($file), $plate->sum('amount'), 0.005);
    }

    public function test_work_handed_back_is_not_chased_for_a_rate(): void
    {
        $file = $this->folder([[$this->hpt, 2000, $this->sharma, null]]);
        $this->takeBack([$this->key($file, $this->sharma)]);

        $chased = WorkFileModel::query()->whereKey($file->id)
            ->whereRaw('EXISTS (SELECT 1 FROM work_file_item AS vrs WHERE '.WorkFileModel::VENDOR_WORK_UNPRICED.')')
            ->exists();

        $this->assertFalse($chased);
    }

    /** The history names their works — and a customer's copy of it drops the clause. */
    public function test_the_history_says_whose_works_came_back(): void
    {
        $file = $this->partlyGiven();
        $this->takeBack([$this->key($file, $this->sharma)]);

        $remark = DB::table('work_file_status_log')->where('work_file_id', $file->id)->orderByDesc('id')->value('remark');

        $this->assertStringContainsString($this->hpt->name.' papers returned by '.$this->sharma->name, $remark);
        $this->assertSame('Could not do it', WorkFileModel::customerRemark($remark));
    }

    // --------------------------------------------------- data from before

    /** A part typed on the folder before the works carried one comes down onto them. */
    public function test_the_migration_carries_an_old_part_down_onto_the_works(): void
    {
        $file = $this->folder([
            [$this->tr, 3000, $this->sharma, 600, WorkFileModel::APPROVED, '2026-09-05'],
            [$this->hpt, 2000, $this->sharma, 400],
        ]);
        $file->items()->update(['vendor_returned_on' => '2026-09-10']);
        DB::table('work_file')->where('id', $file->id)
            ->update(['vendor_returned_on' => '2026-09-10', 'vendor_returned_amount' => 700]);

        (require database_path('migrations/2026_09_29_000100_let_a_vendor_hand_back_their_part.php'))->up();

        $parts = $file->items()->pluck('vendor_returned_amount', 'work_type_id');

        // The HPT first, all 400 of it; the TR the 300 left.
        $this->assertNull($parts[$this->hpt->id]);
        $this->assertEquals(300, $parts[$this->tr->id]);
        $this->assertEqualsWithDelta(700, $file->fresh()->reversalFromWorks(), 0.005);
    }

    public function test_the_repair_puts_an_old_folder_figure_right(): void
    {
        $file = $this->split();
        $this->takeBack([$this->key($file, $this->sharma)], [$this->key($file, $this->sharma) => 400]);
        DB::table('work_file')->where('id', $file->id)->update(['vendor_returned_amount' => 999]);

        Artisan::call('files:resync-vendors');
        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'.*rates back 999\.00 → 400\.00/', Artisan::output());

        // The audit names it too.
        Artisan::call('files:audit');
        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'.*says 999\.00 of its vendors\' rates came back but its works say 400\.00/', Artisan::output());

        Artisan::call('files:resync-vendors', ['--write' => true]);
        $this->assertEquals(400, $file->fresh()->vendor_returned_amount);
    }

    protected function tearDown(): void
    {
        WorkFileItemModel::assumePartReversals(null);

        parent::tearDown();
    }

    // ------------------------------------------------------ found in review

    /**
     * Their work handed back before anyone priced it: nothing of theirs is
     * reversed, and the rate typed on the office's own work stays the file's
     * cost. It read "all of it" reversed, the office's 800 with it.
     */
    public function test_an_unpriced_hand_back_leaves_the_offices_rate_a_cost(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, null],
            [$this->tr, 3000, null, 800],
        ]);

        $this->takeBack([$this->key($file, $this->sharma)])->assertRedirect(route('workfile.index'));

        $this->assertEqualsWithDelta(800, $this->spent($file), 0.005);
        $this->assertEqualsWithDelta(4200, $file->fresh()->margin(), 0.005);

        $plate = collect($this->actingAs($this->admin)
            ->getJson(route('report.expenses', ['vehicle' => $file->registration_no]))->assertOk()->json('props.rows'))
            ->where('file_id', $file->id);

        $this->assertEqualsWithDelta($this->spent($file), $plate->sum('amount'), 0.005);
    }

    /** Handed back unpriced, it waits on no rate: not on its own, nor beside another vendor's. */
    public function test_work_handed_back_unpriced_is_not_awaiting_a_price(): void
    {
        $whole = $this->folder([[$this->hpt, 2000, $this->sharma, null]]);
        $split = $this->folder([
            [$this->hpt, 2000, $this->sharma, null],
            [$this->tr, 3000, $this->shailendra, 1500],
        ]);

        $before = WorkFileModel::pendingCounts()['vendor'];

        $this->takeBack([$this->key($whole, $this->sharma), $this->key($split, $this->sharma)]);

        $this->assertSame($before - 2, WorkFileModel::pendingCounts()['vendor']);

        foreach ([$whole, $split] as $file) {
            $this->assertFalse(WorkFileModel::query()->whereKey($file->id)->whereRaw(WorkFileModel::OUTSTANDING)->exists());
        }

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('report.files', ['party_type' => 'vendor']))->assertOk()->json('props.rows'));

        $this->assertNotNull($rows->where('id', $whole->id)->firstWhere('party_id', $this->sharma->id)['margin']);
        $this->assertNotNull($rows->where('id', $split->id)->firstWhere('party_id', $this->sharma->id)['margin']);
    }

    /**
     * Before the migration has run, a part is kept on the folder, and a
     * folder not back keeps none: typed for one vendor while another still has
     * work, it was dropped and all of theirs reversed. Refused, rather — and a
     * folder that comes back whole takes a part as it always did.
     */
    public function test_before_the_migration_a_part_is_refused_where_it_would_be_lost(): void
    {
        WorkFileItemModel::assumePartReversals(false);

        $split = $this->split();

        $this->takeBack([$this->key($split, $this->sharma)], [$this->key($split, $this->sharma) => 400])
            ->assertSessionHas('error', fn ($said) => str_contains($said, 'once the update is finished'));
        $this->assertSame([], $this->lines($split, 'vendor_return'));

        $whole = $this->folder([[$this->hpt, 2000, $this->sharma, 1000]]);

        $this->takeBack([$this->key($whole, $this->sharma)], [$this->key($whole, $this->sharma) => 400])
            ->assertRedirect(route('workfile.index'));

        $this->assertEquals(400, $whole->fresh()->vendor_returned_amount);
        $this->assertSame([$this->sharma->id => 400.0], $this->lines($whole, 'vendor_return'));
    }

    /** A stale page posting a vendor already back moves nothing — not the other vendor's work. */
    public function test_a_stale_page_moves_nothing_the_second_time(): void
    {
        $file = $this->split();
        $this->takeBack([$this->key($file, $this->sharma)]);

        $this->takeBack([$this->key($file, $this->sharma)], [], '2026-09-20')->assertSessionHas('error');

        $this->assertSame([$this->sharma->id => 1000.0], $this->lines($file, 'vendor_return'));
        $this->assertSame('2026-09-10', (string) $file->items()->where('work_type_id', $this->hpt->id)->value('vendor_returned_on'));
        $this->assertSame(WorkFileModel::DISPATCHED, $this->workStatus($file, $this->tr));
    }

    /**
     * One work of theirs back and another given to them since: the list offers
     * the one they have, their statement reverses the one that came back —
     * and says which.
     */
    public function test_one_work_back_and_another_still_out_with_them(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 1000],
            [$this->tr, 3000, null, null],
        ]);
        $this->takeBack([$this->key($file, $this->sharma)]);

        $file->items()->where('work_type_id', $this->tr->id)->update([
            'vendor_id' => $this->sharma->id, 'vendor_amount' => 500, 'vendor_date' => '2026-09-12',
            'status' => WorkFileModel::DISPATCHED,
        ]);
        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $row = $this->listed($file)->sole();
        $this->assertSame($this->key($file, $this->sharma), $row['key']);
        $this->assertSame($this->tr->name, $row['work_type']);
        $this->assertEquals(500, $row['vendor_amount']);

        $this->assertSame([$this->sharma->id => 1500.0], $this->lines($file, 'vendor'));
        $this->assertSame([$this->sharma->id => 1000.0], $this->lines($file, 'vendor_return'));

        $says = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor_return')->value('particular');
        $this->assertStringContainsString($this->hpt->name, $says);
        $this->assertStringNotContainsString($this->tr->name, $says, 'the work they still have read as handed back');

        $this->assertEqualsWithDelta(500, $this->spent($file), 0.005);
    }

    /** On a split folder, each vendor's Expenses page carries their own hand-back only. */
    public function test_the_expenses_report_splits_a_hand_back_by_vendor(): void
    {
        $file = $this->split();
        $this->takeBack([$this->key($file, $this->sharma)], [$this->key($file, $this->sharma) => 400]);

        $for = fn (PartyModel $vendor) => collect($this->actingAs($this->admin)
            ->getJson(route('report.expenses', ['vendor_id' => $vendor->id]))->assertOk()->json('props.rows'))
            ->where('file_id', $file->id);

        $this->assertEqualsWithDelta(600, $for($this->sharma)->sum('amount'), 0.005);
        $this->assertCount(0, $for($this->shailendra)->where('amount', '<', 0));

        $plate = collect($this->actingAs($this->admin)
            ->getJson(route('report.expenses', ['vehicle' => $file->registration_no]))->assertOk()->json('props.rows'))
            ->where('file_id', $file->id);
        $this->assertEqualsWithDelta($this->spent($file), $plate->sum('amount'), 0.005);
    }

    /** A folder cancelled at deploy keeps its part, carried down with its works. */
    public function test_the_migration_carries_a_cancelled_folders_part_and_runs_twice_safely(): void
    {
        $file = $this->folder([[$this->hpt, 2000, $this->sharma, 1000], [$this->tr, 3000, $this->sharma, 500]]);
        $file->items()->update(['vendor_returned_on' => '2026-09-10', 'status' => WorkFileModel::CANCELLED]);
        DB::table('work_file')->where('id', $file->id)->update([
            'status' => WorkFileModel::CANCELLED, 'vendor_returned_on' => '2026-09-10', 'vendor_returned_amount' => 600,
        ]);

        // As a run cut off part-way through it would have left it: 50 on one.
        $file->items()->where('work_type_id', $this->hpt->id)->update(['vendor_returned_amount' => 50]);

        $migration = require database_path('migrations/2026_09_29_000100_let_a_vendor_hand_back_their_part.php');
        $migration->up();
        $migration->up();

        $parts = $file->items()->pluck('vendor_returned_amount', 'work_type_id');

        // The HPT's 1000 takes all 600, the TR none: shared once, whole.
        $this->assertEquals(600, $parts[$this->hpt->id]);
        $this->assertEquals(0, $parts[$this->tr->id]);
    }

    /** A hand-made post: an empty amounts, or a nested tick, is refused or read — never a crash. */
    public function test_odd_posts_do_not_crash_the_screen(): void
    {
        $file = $this->partlyGiven();

        $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => '2026-09-10', 'files' => [$this->key($file, $this->sharma)], 'amounts' => '', 'remark' => 'Odd',
        ])->assertRedirect(route('workfile.index'));

        $other = $this->partlyGiven();

        $this->actingAs($this->admin)->from(route('workfile.vendorreturn'))->post(route('workfile.vendorreturn'), [
            'returned_on' => '2026-09-10', 'files' => [['1']], 'remark' => 'Odd',
        ])->assertRedirect(route('workfile.vendorreturn'));

        $this->actingAs($this->admin)->get(route('workfile.vendorreturn'))->assertOk();
        $this->assertCount(1, $this->listed($other));
    }

    /** A batch refused comes back with its rows ticked and its figures typed, by row. */
    public function test_a_refused_batch_comes_back_ticked_by_row(): void
    {
        $file = $this->partlyGiven();
        $key = $this->key($file, $this->sharma);

        $this->actingAs($this->admin)->from(route('workfile.vendorreturn'))
            ->post(route('workfile.vendorreturn'), [
                'returned_on' => '2026-09-10', 'files' => [$key], 'amounts' => [$key => 5000], 'remark' => 'Too much',
            ])->assertRedirect(route('workfile.vendorreturn'));

        $props = $this->actingAs($this->admin)->getJson(route('workfile.vendorreturn'))->assertOk()->json('props');

        $this->assertContains($key, $props['pickedIds']);
        $this->assertEquals(5000, $props['oldAmounts'][$key]);
    }
}

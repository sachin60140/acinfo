<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkFilePaperModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A vendor is owed for their own works, and a folder is "older" only when no
 * work on it ever named a vendor.
 *
 * Asked for by the owner on 2026-09-28, with the rule that anything vendor-wise
 * counts only the works given to that vendor. Two ways the ledger broke it:
 *
 *  - A vendor whose one given work was cancelled, the rest kept in the office,
 *    read as the vendor of an "older" folder — asked of the live works, none
 *    named one — and was credited the rate typed on the office's work, named
 *    on their statement, and chased for its price.
 *  - A vendor holding part of a folder, their work not yet priced, was credited
 *    the rate typed on the work the office kept, under their own work's name.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VendorOwnWorksLedgerTest extends TestCase
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
        $this->admin->name = 'Own Works Admin';
        $this->admin->email = 'own-works-'.uniqid().'@example.com';
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
     * A folder and its works, each [type, charged, vendor or null, rate, status].
     *
     * @param  array<int, array>  $works
     */
    private function folder(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-OWN-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR06OW'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as $work) {
            [$type, $charged, $vendor, $rate, $status] = $work + [3 => null, 4 => null];

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_id = $vendor?->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = $vendor ? '2026-09-02' : null;
            $item->status = $status ?? ($vendor ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE);
            $item->save();
        }

        return $this->rolledUp($file);
    }

    private function rolledUp(WorkFileModel $file): WorkFileModel
    {
        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** HPT given to Sharma at 500, TR kept in the office with a rate of 800 typed on it. */
    private function partlyGiven(?float $hptRate = 500): WorkFileModel
    {
        return $this->folder([
            [$this->hpt, 2000, $this->sharma, $hptRate],
            [$this->tr, 3000, null, 800],
        ]);
    }

    /** And Sharma's HPT struck off: the cancelled trap. */
    private function trap(): WorkFileModel
    {
        $file = $this->partlyGiven();
        $file->items()->where('vendor_id', $this->sharma->id)->update(['status' => WorkFileModel::CANCELLED]);

        return $this->rolledUp($file);
    }

    /** As a trap folder written before roll-up learned to clear its vendor, with the line the old rule wrote. */
    private function staleTrap(): WorkFileModel
    {
        $file = $this->trap();

        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-02']);

        $line = new PartyLedgerModel;
        $line->party_id = $this->sharma->id;
        $line->work_file_id = $file->id;
        $line->file_role = 'vendor';
        $line->entry_type = 'credit';
        $line->txn_date = '2026-09-02';
        $line->amount = 800;
        $line->payment_mode = 'Credit / Invoice';
        $line->ref_no = $file->file_no;
        $line->particular = 'old rule';
        $line->save();

        return $file->fresh();
    }

    /** @return array<int, float> vendor id => amount, of one role */
    private function lines(WorkFileModel $file, string $role = 'vendor'): array
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', $role)
            ->pluck('amount', 'party_id')->map(fn ($amount) => (float) $amount)->all();
    }

    // ------------------------------------------------------ the cancelled trap

    public function test_a_vendor_whose_only_given_work_was_cancelled_is_owed_nothing(): void
    {
        $this->assertSame([], $this->lines($this->trap()), 'credited the rate of the work the office kept');
    }

    public function test_the_folder_names_nobody_once_its_only_given_work_is_cancelled(): void
    {
        $file = $this->trap();

        $this->assertNull($file->vendor_id);
        $this->assertNull($file->vendor_date);
    }

    /** Struck off whole, it still says who had it. */
    public function test_a_folder_struck_off_whole_still_names_its_vendor(): void
    {
        $file = $this->folder([[$this->hpt, 2000, $this->sharma, 500]]);
        $file->items()->update(['status' => WorkFileModel::CANCELLED]);
        $file = $this->rolledUp($file);

        $this->assertSame(WorkFileModel::CANCELLED, $file->status);
        $this->assertSame($this->sharma->id, (int) $file->vendor_id);
        $this->assertSame([], $this->lines($file));
    }

    /** Their statement's Against, the bill list, Vendor Payments: nothing of theirs. */
    public function test_the_work_the_office_kept_is_not_named_as_theirs(): void
    {
        $file = $this->staleTrap()->load('items.workType');

        $this->assertSame('', $file->worksFor($this->sharma->id));
    }

    public function test_they_are_not_chased_for_a_rate_on_the_work_the_office_kept(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, null, null],
        ]);
        $file->items()->where('vendor_id', $this->sharma->id)->update(['status' => WorkFileModel::CANCELLED]);
        $file = $this->rolledUp($file);
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->sharma->id]);

        $chased = WorkFileModel::query()->whereKey($file->id)
            ->whereRaw('EXISTS (SELECT 1 FROM work_file_item AS vrs WHERE '.WorkFileModel::VENDOR_WORK_UNPRICED.')')
            ->exists();

        $this->assertFalse($chased);
    }

    public function test_nothing_is_offered_back_from_them(): void
    {
        $file = $this->staleTrap();

        $this->assertFalse(WorkFileModel::withVendor()->contains('id', $file->id), 'the office\'s work offered back from the vendor');
    }

    // --------------------------------------------------------- the fallback

    /**
     * Their work unpriced, the office's work with a rate typed on it: they
     * were credited that rate, under their own work's name.
     */
    public function test_a_vendor_with_part_of_a_folder_is_not_credited_the_offices_rate(): void
    {
        $file = $this->partlyGiven(null);

        $this->assertSame([], $this->lines($file));

        // Priced, they are owed their own.
        $file->items()->where('vendor_id', $this->sharma->id)->update(['vendor_amount' => 1250]);
        $this->assertSame([$this->sharma->id => 1250.0], $this->lines($this->rolledUp($file)));
    }

    public function test_a_vendor_with_part_of_a_folder_is_owed_their_own(): void
    {
        $this->assertSame([$this->sharma->id => 500.0], $this->lines($this->partlyGiven()));
    }

    // ------------------------------------------------------------ unchanged

    /** No work ever named a vendor: the folder's own is owed the folder's rate. */
    public function test_an_older_folder_is_still_its_vendors(): void
    {
        $file = $this->folder([[$this->hpt, 2000, null, null], [$this->tr, 3000, null, null]]);
        $file->vendor_id = $this->sharma->id;
        $file->vendor_amount = 2500;
        $file->vendor_date = '2026-09-02';
        $file->save();
        $file->syncLedger();

        $this->assertSame([$this->sharma->id => 2500.0], $this->lines($file));
        $this->assertSame($this->sharma->id, (int) $this->rolledUp($file)->vendor_id, 'roll-up took the older folder\'s vendor away');
        $this->assertSame($file->fresh()->load('items.workType')->worksFor(), $file->fresh()->load('items.workType')->worksFor($this->sharma->id));
    }

    public function test_a_split_folder_owes_each_their_own(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, $this->shailendra, 700],
        ]);

        $this->assertSame([$this->sharma->id => 500.0, $this->shailendra->id => 700.0], $this->lines($file));
    }

    // ----------------------------------------------------------- paper pendency

    /**
     * The office's own work, its papers in, goes back to the office — not to
     * File Dispatch because the folder's other work is with a vendor.
     */
    public function test_the_offices_work_leaves_paper_pendency_for_the_office(): void
    {
        $paper = new PaperTypeModel;
        $paper->name = 'Form '.uniqid();
        $paper->is_active = 1;
        $paper->save();
        DB::table('work_type_paper')->insert(['work_type_id' => $this->tr->id, 'paper_type_id' => $paper->id]);

        $file = $this->partlyGiven();

        $file->savePaperChecklist([$paper->id => ['state' => WorkFilePaperModel::PENDING, 'note' => null]]);
        $this->assertSame(WorkFileModel::PAPER_PENDENCY, $file->items()->where('work_type_id', $this->tr->id)->value('status'));

        $file->fresh()->savePaperChecklist([$paper->id => ['state' => WorkFilePaperModel::RECEIVED, 'note' => null]]);
        $this->assertSame(WorkFileModel::IN_OFFICE, $file->items()->where('work_type_id', $this->tr->id)->value('status'),
            'sent to File Dispatch, which nobody had it for');
    }

    // ------------------------------------------------------------- the audit

    public function test_the_audit_is_quiet_about_shared_folders(): void
    {
        $partly = $this->partlyGiven();
        $split = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, $this->shailendra, 700],
        ]);

        Artisan::call('files:audit');
        $said = Artisan::output();

        $this->assertStringNotContainsString($partly->file_no, $said);
        $this->assertStringNotContainsString($split->file_no, $said);
    }

    public function test_the_audit_names_a_line_the_file_does_not_call_for(): void
    {
        $file = $this->staleTrap();

        Artisan::call('files:audit');
        $said = Artisan::output();

        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'.*"vendor" entry of 800\.00 for vendor '.$this->sharma->id.'/', $said);
        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'.*names vendor '.$this->sharma->id.' but its works say none/', $said);
    }

    // ------------------------------------------------------- the repair

    public function test_the_repair_lists_first_and_writes_nothing(): void
    {
        $file = $this->staleTrap();

        Artisan::call('files:resync-vendors');
        $said = Artisan::output();

        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'.*vendor #'.$this->sharma->id.' owed 800\.00 → none/', $said);
        $this->assertSame([$this->sharma->id => 800.0], $this->lines($file), 'written without --write');
        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
    }

    public function test_the_repair_puts_it_right_with_write(): void
    {
        $file = $this->staleTrap();

        Artisan::call('files:resync-vendors', ['--write' => true]);

        $this->assertSame([], $this->lines($file));
        $this->assertNull($file->fresh()->vendor_id);

        // The customer's line is left exactly as it was.
        $this->assertSame([$this->customer->id => 3000.0], $this->lines($file, 'customer'));
    }

    public function test_the_repair_skips_a_file_with_a_payment_adjusted_against_it(): void
    {
        $file = $this->staleTrap();

        $payment = new PartyLedgerModel;
        $payment->party_id = $this->sharma->id;
        $payment->entry_type = 'debit';
        $payment->txn_date = '2026-09-10';
        $payment->amount = 300;
        $payment->payment_mode = 'Cash';
        $payment->particular = 'Paid';
        $payment->save();

        DB::table('party_ledger_allocation')->insert([
            'entry_id' => $payment->id, 'party_id' => $this->sharma->id, 'work_file_id' => $file->id,
            'amount' => 300, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('files:resync-vendors', ['--write' => true]);
        $said = Artisan::output();

        $this->assertStringContainsString('payment #'.$payment->id.' adjusted 300.00 to vendor #'.$this->sharma->id, $said);
        $this->assertSame([$this->sharma->id => 800.0], $this->lines($file), 'moved under a payment adjusted against it');
        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id, 'the folder moved under it too');

        // And Vendor Payments says the line is not theirs, rather than Finished over nothing.
        $row = collect($this->actingAs($this->admin)->getJson(route('report.payable'))->assertOk()->json('props.rows'))
            ->firstWhere('bill', $file->file_no);

        $this->assertSame('Not their work — see files:resync-vendors', $row['state']);
    }

    /** A payment adjusted and since released holds nothing. */
    public function test_a_released_adjustment_does_not_hold_the_repair(): void
    {
        $file = $this->staleTrap();
        $this->adjust($file, $this->sharma, 800, released: true);

        Artisan::call('files:resync-vendors', ['--write' => true]);

        $this->assertSame([], $this->lines($file));
    }

    /** Nor does one to another vendor on the file, or one to a vendor owed more. */
    public function test_only_a_vendor_owed_less_is_held_by_their_own_payment(): void
    {
        // Split: Sharma's line is stale at 900, Shailendra's right at 700 and paid.
        $split = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, $this->shailendra, 700],
        ]);
        PartyLedgerModel::where('work_file_id', $split->id)->where('party_id', $this->sharma->id)->update(['amount' => 900]);
        $this->adjust($split, $this->shailendra, 700);

        // Owed more: Sharma's line stale at 300, and paid against.
        $more = $this->partlyGiven();
        PartyLedgerModel::where('work_file_id', $more->id)->where('party_id', $this->sharma->id)->update(['amount' => 300]);
        $this->adjust($more, $this->sharma, 300);

        Artisan::call('files:resync-vendors', ['--write' => true]);

        $this->assertSame([$this->sharma->id => 500.0, $this->shailendra->id => 700.0], $this->lines($split));
        $this->assertSame([$this->sharma->id => 500.0], $this->lines($more));
    }

    private function adjust(WorkFileModel $file, PartyModel $vendor, float $amount, bool $released = false): void
    {
        $payment = new PartyLedgerModel;
        $payment->party_id = $vendor->id;
        $payment->entry_type = 'debit';
        $payment->txn_date = '2026-09-10';
        $payment->amount = $amount;
        $payment->payment_mode = 'Cash';
        $payment->particular = 'Paid';
        $payment->save();

        DB::table('party_ledger_allocation')->insert([
            'entry_id' => $payment->id, 'party_id' => $vendor->id, 'work_file_id' => $file->id, 'amount' => $amount,
            'released_at' => $released ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The folder's vendor alone, with no line to change: named, and put right with its dates. */
    public function test_the_repair_puts_right_a_folder_vendor_with_no_line(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, null, null],
        ]);
        $file->items()->where('vendor_id', $this->sharma->id)->update(['status' => WorkFileModel::CANCELLED, 'vendor_returned_on' => '2026-09-05']);
        $file = $this->rolledUp($file);
        DB::table('work_file')->where('id', $file->id)
            ->update(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-02', 'vendor_returned_on' => '2026-09-05']);

        Artisan::call('files:resync-vendors');
        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'.*names vendor #'.$this->sharma->id.' → #none/', Artisan::output());

        Artisan::call('files:resync-vendors', ['--write' => true]);
        $file = $file->fresh();

        $this->assertNull($file->vendor_id);
        $this->assertNull($file->vendor_date);
        $this->assertNull($file->vendor_returned_on);
    }

    /** Vendor side only: the customer's line is not touched, however it reads. */
    public function test_the_repair_leaves_the_customers_line_exactly_as_it_is(): void
    {
        $file = $this->staleTrap();
        $customer = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer')->first();
        $customer->amount = 2999;
        $customer->particular = 'stale words';
        $customer->save();
        $before = $customer->fresh()->getAttributes();

        Artisan::call('files:resync-vendors', ['--write' => true]);

        $this->assertSame($before, PartyLedgerModel::find($customer->id)->getAttributes());
    }

    public function test_the_repair_lists_work_out_with_nobody_at_file_dispatch(): void
    {
        $file = $this->partlyGiven();
        $file->items()->whereNull('vendor_id')->update(['status' => WorkFileModel::DISPATCHED]);

        // And work its vendor handed back, left there too.
        $back = $this->folder([[$this->hpt, 2000, $this->sharma, 500], [$this->tr, 3000, $this->sharma, 700]]);
        $back->items()->where('work_type_id', $this->hpt->id)->update(['vendor_returned_on' => '2026-09-05']);

        Artisan::call('files:resync-vendors');
        $said = Artisan::output();

        $this->assertMatchesRegularExpression('/'.preg_quote($file->file_no, '/').'\s+1 work/', $said);
        $this->assertMatchesRegularExpression('/'.preg_quote($back->file_no, '/').'\s+1 work/', $said);
    }

    // --------------------------------------------- an older folder, handed back

    /**
     * No work ever named a vendor: its own is owed the folder's rate, and a
     * part handed back is reversed from the folder's figure, on the day.
     */
    public function test_an_older_folder_handed_back_in_part_reverses_the_part(): void
    {
        foreach ([true, false] as $withWorks) {
            $file = $withWorks
                ? $this->folder([[$this->hpt, 2000, null, null], [$this->tr, 3000, null, null]])
                : $this->bareFolder();

            $file->vendor_id = $this->sharma->id;
            $file->vendor_amount = 2500;
            $file->vendor_date = '2026-09-02';
            $file->vendor_returned_on = '2026-09-10';
            $file->vendor_returned_amount = 1000;
            $file->save();
            $file->syncLedger();

            $return = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor_return')->sole();
            $this->assertSame([$this->sharma->id => 2500.0], $this->lines($file));
            $this->assertEquals(1000, $return->amount);
            $this->assertSame('2026-09-10', date('Y-m-d', strtotime($return->txn_date)));

            // All of it.
            $file->vendor_returned_amount = null;
            $file->save();
            $file->syncLedger();
            $this->assertSame([$this->sharma->id => 2500.0], $this->lines($file, 'vendor_return'));

            // Not back after all.
            $file->vendor_returned_on = null;
            $file->save();
            $file->syncLedger();
            $this->assertSame([], $this->lines($file, 'vendor_return'));

            // To another vendor: only theirs.
            $file->vendor_id = $this->shailendra->id;
            $file->save();
            $file->syncLedger();
            $this->assertSame([$this->shailendra->id => 2500.0], $this->lines($file));
        }
    }

    /** A folder with no works at all, handed over whole on the folder. */
    private function bareFolder(): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-OWN-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR06OW'.random_int(1000, 9999);
        $file->work_type_id = $this->hpt->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        return $file;
    }

    // ------------------------------------------------------ the audit, closely

    public function test_the_audit_names_a_wrong_amount_and_a_missing_line_for_each_vendor(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, $this->shailendra, 700],
        ]);
        $file->items()->where('vendor_id', $this->sharma->id)->update(['vendor_returned_on' => '2026-09-05', 'status' => WorkFileModel::IN_OFFICE]);
        $file = $this->rolledUp($file);

        PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor')->where('party_id', $this->sharma->id)->update(['amount' => 450]);
        PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor')->where('party_id', $this->shailendra->id)->delete();
        PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'vendor_return')->update(['amount' => 100]);

        Artisan::call('files:audit');
        $said = Artisan::output();
        $at = preg_quote($file->file_no, '/');

        $this->assertMatchesRegularExpression("/$at.*owes vendor {$this->sharma->id} 500\.00 but the entry says 450/", $said);
        $this->assertMatchesRegularExpression("/$at.*owes vendor {$this->shailendra->id} 700\.00 but has no entry for it/", $said);
        $this->assertMatchesRegularExpression("/$at.*takes back from vendor {$this->sharma->id} 500\.00 but the entry says 100/", $said);
    }

    // ------------------------------------------------- the rate chase, whole

    /** Fresh from roll-up, nothing about the trap is awaiting a vendor's rate. */
    public function test_a_trap_folder_is_not_awaiting_a_vendors_rate(): void
    {
        $file = $this->folder([
            [$this->hpt, 2000, $this->sharma, 500],
            [$this->tr, 3000, null, null],
        ]);
        $file->items()->where('vendor_id', $this->sharma->id)->update(['status' => WorkFileModel::CANCELLED]);
        $file = $this->rolledUp($file);

        $this->assertFalse(WorkFileModel::query()->whereKey($file->id)->whereRaw(WorkFileModel::VENDOR_UNPRICED)->exists());
    }

    // --------------------------------------------- handed back, papers come in

    /** Work its vendor handed back, its papers in, is the office's — not File Dispatch. */
    public function test_handed_back_work_leaves_paper_pendency_for_the_office(): void
    {
        $paper = new PaperTypeModel;
        $paper->name = 'Form '.uniqid();
        $paper->is_active = 1;
        $paper->save();
        DB::table('work_type_paper')->insert(['work_type_id' => $this->hpt->id, 'paper_type_id' => $paper->id]);

        $file = $this->folder([[$this->hpt, 2000, $this->sharma, 500]]);
        $file->items()->update(['vendor_returned_on' => '2026-09-05', 'status' => WorkFileModel::IN_OFFICE]);
        $file = $this->rolledUp($file);

        $file->savePaperChecklist([$paper->id => ['state' => WorkFilePaperModel::PENDING, 'note' => null]]);
        $file->fresh()->savePaperChecklist([$paper->id => ['state' => WorkFilePaperModel::RECEIVED, 'note' => null]]);

        $this->assertSame(WorkFileModel::IN_OFFICE, $file->items()->value('status'), 'sent to File Dispatch, which nobody had it for');
    }
}

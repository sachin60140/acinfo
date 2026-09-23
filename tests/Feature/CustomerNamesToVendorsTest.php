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
 * A vendor is never told a customer's name or money.
 *
 * The owner's rule, the mirror of "no customer sees a vendor's name". A
 * vendor's statement is printed, exported and sent to the vendor, and the
 * vendor-side sweep (2026-09-23) found three ways a customer reached it:
 *
 *  - the file's typed details were built into the vendor's line;
 *  - the file's remarks — the counter's note on the customer's file — were
 *    its Remarks column;
 *  - what the office typed on a vendor's own entries went out as typed.
 *
 * Read for: the vendor's line names the works and the vehicle and nothing
 * typed; the Remarks column is not on a vendor's statement; a customer's
 * name, first name or number typed on a vendor's entry is cut to "…"; and
 * the customer's own statement is exactly as it was.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class CustomerNamesToVendorsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    /** Made up, so no real party answers to it. */
    private string $name;

    private string $mobile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Vendor Side Admin';
        $this->admin->email = 'vendor-side-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->name = 'Zorawarq Vendside '.random_int(1000, 9999);
        $this->mobile = '98765'.random_int(10000, 99999);

        $this->customer = $this->party('customer', $this->name, $this->mobile);
        $this->vendor = $this->party('vendor', 'Parwez Works '.uniqid(), '93800'.random_int(10000, 99999));

        $this->tr = $this->type('TR');
        $this->hpa = $this->type('HPA');
    }

    private function party(string $type, string $name, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name;
        $party->mobile = $mobile;
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

    /** A folder of works for our customer, each given to a vendor, with what the counter typed on it. */
    private function file(array $works, string $details, string $remarks = ''): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-CV-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = 'BR01CV'.random_int(1000, 9999);
        $file->description = $details;
        $file->remarks = $remarks ?: null;
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($works as [$type, $vendor, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 5000;
            $item->vendor_id = $vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = '2026-09-16';
            $item->status = WorkFileModel::DISPATCHED;
            $item->save();
        }

        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function statement(PartyModel $party)
    {
        return $this->actingAs($this->admin)->getJson(route('party.statement', $party->id))->assertOk();
    }

    private function vendorLine(WorkFileModel $file, PartyModel $vendor, string $role = 'vendor'): ?PartyLedgerModel
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('party_id', $vendor->id)->where('file_role', $role)->first();
    }

    // ---------------------------------------------------- the file's details

    /** What the counter typed on the file stays on the customer's line and never reaches the vendor's. */
    public function test_the_files_details_never_reach_the_vendors_line(): void
    {
        $details = $this->name.' '.$this->mobile.' bal 5000';
        $file = $this->file([[$this->tr, $this->vendor, 1500]], $details);

        $line = $this->vendorLine($file, $this->vendor);
        $this->assertSame($this->tr->name.' - '.$file->registration_no, $line->particular);

        // The customer's own line keeps them, as it always has.
        $customerLine = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer')->first();
        $this->assertStringContainsString($details, $customerLine->particular);

        $said = $this->statement($this->vendor)->getContent();
        $this->assertStringNotContainsString($this->name, $said);
        $this->assertStringNotContainsString($this->mobile, $said);
        $this->assertStringNotContainsString('bal 5000', $said);
    }

    /** A vendor with part of a split folder: their own works, still nothing typed. */
    public function test_a_split_folders_vendor_line_names_their_works_and_nothing_typed(): void
    {
        $other = $this->party('vendor', 'Second Works '.uniqid(), '93900'.random_int(10000, 99999));
        $file = $this->file([[$this->tr, $this->vendor, 1500], [$this->hpa, $other, 900]], 'For '.$this->name);

        $this->assertSame($this->tr->name.' - '.$file->registration_no, $this->vendorLine($file, $this->vendor)->particular);
        $this->assertSame($this->hpa->name.' - '.$file->registration_no, $this->vendorLine($file, $other)->particular);
    }

    /** Handed back by the vendor, the line that says so carries nothing typed either. */
    public function test_the_returned_by_vendor_line_carries_nothing_typed(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 1500]], 'For '.$this->name);

        $file->items()->update(['vendor_returned_on' => '2026-09-20', 'status' => WorkFileModel::IN_OFFICE]);
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->assertSame($this->tr->name.' - '.$file->registration_no.' - returned by vendor', $this->vendorLine($file, $this->vendor, 'vendor_return')->particular);
    }

    /**
     * Lines written before the fix are put right by files:relabel-ledger —
     * each vendor's own works on a split folder, not the whole of it. Found
     * in the sweep: the command gave every vendor line the customer's
     * wording, and a vendor with part of a folder all of it.
     */
    public function test_relabelling_takes_the_details_off_old_vendor_lines(): void
    {
        $other = $this->party('vendor', 'Second Works '.uniqid(), '93900'.random_int(10000, 99999));
        $file = $this->file([[$this->tr, $this->vendor, 1500], [$this->hpa, $other, 900]], 'For '.$this->name);

        // As the old code wrote them.
        DB::table('party_ledger')->where('work_file_id', $file->id)->where('file_role', 'vendor')
            ->update(['particular' => $file->ledgerParticular()]);

        Artisan::call('files:relabel-ledger', ['--write' => true]);

        $this->assertSame($this->tr->name.' - '.$file->registration_no, $this->vendorLine($file, $this->vendor)->particular);
        $this->assertSame($this->hpa->name.' - '.$file->registration_no, $this->vendorLine($file, $other)->particular);

        $customerLine = PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', 'customer')->first();
        $this->assertStringContainsString('For '.$this->name, $customerLine->particular);
    }

    // -------------------------------------------------------- the remarks

    /** The counter's note on the customer's file is not a column on the vendor's statement. */
    public function test_the_files_remarks_are_not_on_the_vendors_statement(): void
    {
        $file = $this->file([[$this->tr, $this->vendor, 1500]], '', 'Advance 2000 paid, balance 3000 by Friday');

        $vendor = $this->statement($this->vendor);
        $this->assertStringNotContainsString('Advance 2000', $vendor->getContent());
        $this->assertFalse(collect($vendor->json('props.columns'))->contains('key', 'remarks'));

        // The customer's own statement still has it.
        $customer = $this->statement($this->customer);
        $this->assertTrue(collect($customer->json('props.columns'))->contains('key', 'remarks'));
        $this->assertStringContainsString('Advance 2000', $customer->getContent());
    }

    // ----------------------------------------------------- typed by hand

    /** Typed on a vendor's payment, a customer's name, first name and number are cut out; the rest reads as typed. */
    public function test_a_customer_typed_on_a_vendors_entry_is_cut_out(): void
    {
        $first = explode(' ', $this->name)[0];

        $entry = new PartyLedgerModel;
        $entry->party_id = $this->vendor->id;
        $entry->txn_date = '2026-09-18';
        $entry->entry_type = 'debit';
        $entry->amount = 1500;
        $entry->payment_mode = 'Cash';
        $entry->ref_no = '+91 '.substr($this->mobile, 0, 5).' '.substr($this->mobile, 5);
        $entry->particular = 'Paid for '.strtoupper($this->name).' TR, and '.$first.' ji will pay the rest';
        $entry->save();

        $row = collect($this->statement($this->vendor)->json('props.rows'))->keyBy('id')[$entry->id];

        $this->assertSame('Paid for … TR, and … ji will pay the rest', $row['particular']);
        $this->assertSame('…', $row['ref_no']);

        // A reversal copies the reference, and is read the same way.
        $this->actingAs($this->admin)->post(route('party.reverse', $entry->id), ['reason' => 'Wrong vendor']);
        $reversal = PartyLedgerModel::where('reverses_id', $entry->id)->first();
        $this->assertSame('…', collect($this->statement($this->vendor)->json('props.rows'))->keyBy('id')[$reversal->id]['ref_no']);
    }

    /** A vendor's own name on their own statement is theirs, and stays. */
    public function test_a_vendors_own_name_stays_on_their_statement(): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $this->vendor->id;
        $entry->txn_date = '2026-09-18';
        $entry->entry_type = 'debit';
        $entry->amount = 500;
        $entry->particular = 'Advance to '.$this->vendor->name;
        $entry->save();

        $row = collect($this->statement($this->vendor)->json('props.rows'))->keyBy('id')[$entry->id];

        $this->assertSame('Advance to '.$this->vendor->name, $row['particular']);
    }

    /** The customer's own statement is not read for customers: it is theirs. */
    public function test_the_customers_own_statement_names_them(): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $this->customer->id;
        $entry->txn_date = '2026-09-18';
        $entry->entry_type = 'credit';
        $entry->amount = 500;
        $entry->payment_mode = 'Cash';
        $entry->particular = 'Received from '.$this->name;
        $entry->save();

        $row = collect($this->statement($this->customer)->json('props.rows'))->keyBy('id')[$entry->id];

        $this->assertSame('Received from '.$this->name, $row['particular']);
    }

    /**
     * Every customer is looked for, and there are far more of them than
     * vendors: the names go in batches, and one past the first batch is
     * still found.
     */
    public function test_a_customer_far_down_a_long_list_is_still_cut_out(): void
    {
        $now = now();
        $rows = [];

        for ($i = 0; $i < 250; $i++) {
            $rows[] = ['party_type' => 'customer', 'name' => 'Qwxcust Filler '.$i.' '.uniqid(), 'mobile' => '97'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('party')->insert($rows);

        // The shortest name sorts last, into the final batch.
        $short = $this->party('customer', 'Qaz'.random_int(10, 99), '96'.random_int(10000000, 99999999));

        $said = \App\Models\WorkFileModel::redactCustomers('Paid for '.$short->name.' and '.$rows[0]['name']);

        $this->assertSame('Paid for … and …', $said);
    }
}

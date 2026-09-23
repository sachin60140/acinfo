<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Vendor Payments: what the office owes each vendor, bill by bill, the
 * longest-waiting first — to decide whom to pay this week.
 *
 * A vendor is owed for a file from the day the work went to them. Money paid
 * and adjusted against files settles those; the rest settles the oldest bill;
 * work they give back comes off its own file.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class VendorPaymentsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private WorkTypeModel $tr;

    private WorkTypeModel $hp;

    private PartyModel $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Vendor Payments Admin';
        $this->admin->email = 'vendor-payments-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->tr = $this->type('TR');
        $this->hp = $this->type('HP');

        $this->customer = $this->party('customer');
        $this->customer->name = 'Zeenat Distinct Customer';
        $this->customer->save();
    }

    private function type(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' to pay';
        $party->mobile = '93700'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * A file with one work per [vendor, rate, status] given out $daysAgo.
     *
     * @param  array<int, array{0: PartyModel, 1: float, 2?: string, 3?: WorkTypeModel}>  $works
     */
    private function file(array $works, int $daysAgo): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-VP-'.uniqid();
        $file->received_date = now()->subDays($daysAgo)->toDateString();
        $file->registration_no = 'BR01VP'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 1000;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        foreach ($works as $work) {
            [$vendor, $rate] = $work;

            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = ($work[3] ?? $this->tr)->id;
            $item->customer_amount = 1000 / count($works);
            $item->status = $work[2] ?? WorkFileModel::DISPATCHED;
            $item->approved_on = ($work[2] ?? null) === WorkFileModel::APPROVED ? now()->toDateString() : null;
            $item->vendor_id = $vendor->id;
            $item->vendor_amount = $rate;
            $item->vendor_date = now()->subDays($daysAgo)->toDateString();
            $item->save();
        }

        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    /** A line typed on a ledger. */
    private function entry(PartyModel $party, string $side, float $amount, int $daysAgo, string $particular = 'Typed by hand', ?int $fileId = null): void
    {
        DB::table('party_ledger')->insert([
            'party_id' => $party->id,
            'work_file_id' => $fileId,
            'txn_date' => now()->subDays($daysAgo)->toDateString(),
            'entry_type' => $side,
            'amount' => $amount,
            'payment_mode' => $side === 'debit' && $party->party_type === 'vendor' ? 'cash' : null,
            'particular' => $particular,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function page()
    {
        return $this->actingAs($this->admin)->getJson(route('report.payable'))->assertOk();
    }

    private function rows(?PartyModel $vendor = null)
    {
        $rows = collect($this->page()->json('props.rows'));

        return $vendor ? $rows->where('vendor_id', $vendor->id)->values() : $rows;
    }

    public function test_the_oldest_bill_comes_first_and_the_longest_waiting_vendor_on_top(): void
    {
        // Owed more, for less long — and made first, so neither the order they
        // were made in nor their names put the other on top.
        $recent = $this->party('vendor');
        $this->file([[$recent, 5000]], 5);

        $patient = $this->party('vendor');
        $new = $this->file([[$patient, 300]], 10);
        $old = $this->file([[$patient, 200]], 60);

        $rows = $this->rows();
        $bands = $rows->pluck('vendor_id')->unique()->values();

        $this->assertLessThan($bands->search($recent->id), $bands->search($patient->id));

        $mine = $rows->where('vendor_id', $patient->id)->values();
        $this->assertSame([$old->file_no, $new->file_no], $mine->pluck('bill')->all());
        $this->assertSame(now()->subDays(60)->format('d-m-Y'), $mine[0]['given']);
        $this->assertSame('60 days', $mine[0]['days_text']);
        $this->assertSame(500.0, (float) $mine[0]['vendor_owed']);
    }

    public function test_a_part_payment_leaves_the_rest_on_the_oldest_bill(): void
    {
        $vendor = $this->party('vendor');
        $old = $this->file([[$vendor, 800]], 30);
        $this->file([[$vendor, 400]], 10);

        $this->entry($vendor, 'debit', 500, 1);

        $rows = $this->rows($vendor);
        $this->assertSame([$old->file_no, 300.0], [$rows[0]['bill'], (float) $rows[0]['due']]);
        $this->assertSame(700.0, (float) $rows->sum('due'));
    }

    public function test_a_payment_adjusted_against_the_newer_file_leaves_the_older_one_owed(): void
    {
        $vendor = $this->party('vendor');
        $old = $this->file([[$vendor, 400]], 30);
        $new = $this->file([[$vendor, 900]], 10);

        // Exactly the older file's amount: not adjusted, it would clear that.
        $this->actingAs($this->admin)
            ->from(route('party.entry', 'vendor'))
            ->post(route('party.entry', 'vendor'), [
                'party_id' => $vendor->id,
                'entry_type' => 'debit',
                'txn_date' => now()->toDateString(),
                'amount' => 400,
                'payment_mode' => 'UPI',
                'particular' => 'Payment',
                'alloc' => [$new->id => ['work_file_id' => $new->id, 'amount' => 400]],
            ])->assertSessionHasNoErrors();

        $rows = $this->rows($vendor);
        $this->assertSame([$old->file_no => 400.0, $new->file_no => 500.0], $rows->mapWithKeys(fn ($r) => [$r['bill'] => (float) $r['due']])->all());
    }

    public function test_work_given_back_comes_off_its_own_file(): void
    {
        $vendor = $this->party('vendor');
        $old = $this->file([[$vendor, 600]], 30);
        $new = $this->file([[$vendor, 700]], 10);

        // The newer one given back in part: a debit on that file.
        $this->entry($vendor, 'debit', 700, 2, 'Returned', $new->id);

        $rows = $this->rows($vendor);
        $this->assertSame([$old->file_no], $rows->pluck('bill')->all());
        $this->assertSame(600.0, (float) $rows[0]['due']);
    }

    public function test_a_bill_typed_on_the_ledger_counts_and_says_what_it_is(): void
    {
        $vendor = $this->party('vendor');
        $this->entry($vendor, 'credit', 1500, 90, 'Opening Balance');

        $row = $this->rows($vendor)->first();

        $this->assertSame('Opening Balance', $row['bill']);
        $this->assertNull($row['bill_url']);
        $this->assertSame(now()->subDays(90)->format('d-m-Y'), $row['given']);
        $this->assertSame(1500.0, (float) $row['due']);
    }

    public function test_a_vendor_paid_ahead_is_not_listed_but_is_counted(): void
    {
        $before = $this->page()->json('page.inAdvance');

        $ahead = $this->party('vendor');
        $this->entry($ahead, 'debit', 900, 3);

        // Settled: neither owed nor ahead.
        $settled = $this->party('vendor');
        $this->file([[$settled, 400]], 6);
        $this->entry($settled, 'debit', 400, 1);

        $page = $this->page();

        $this->assertNull(collect($page->json('props.rows'))->firstWhere('vendor_id', $ahead->id));
        $this->assertNull(collect($page->json('props.rows'))->firstWhere('vendor_id', $settled->id));
        $this->assertSame($before + 1, $page->json('page.inAdvance'), 'the one paid ahead, not the one settled');
    }

    public function test_of_the_same_day_the_vendor_owed_more_comes_first(): void
    {
        $less = $this->party('vendor');
        $this->file([[$less, 200]], 40);

        $more = $this->party('vendor');
        $this->file([[$more, 900]], 40);

        $bands = $this->rows()->pluck('vendor_id')->unique()->values();

        $this->assertLessThan($bands->search($less->id), $bands->search($more->id));
    }

    /**
     * Given back with part of the rate kept: what they keep is final, so it is
     * finished work — not In Office, as if they never started.
     */
    public function test_work_given_back_with_part_kept_is_finished_for_them(): void
    {
        $vendor = $this->party('vendor');
        $file = $this->file([[$vendor, 600, WorkFileModel::APPROVED], [$vendor, 400, WorkFileModel::DISPATCHED, $this->hp]], 12);

        $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'files' => [$file->id],
            'amounts' => [$file->id => 400],
            'returned_on' => now()->toDateString(),
            'remark' => 'HP could not be done',
        ])->assertSessionHasNoErrors();

        $row = $this->rows($vendor)->first();

        $this->assertSame(600.0, (float) $row['due']);
        $this->assertSame(600.0, (float) $row['finished']);
        $this->assertSame('Approval Done, Given back', $row['state']);
    }

    /**
     * On one page: a vendor split across two would be banded twice, each
     * band's total half their bills under a heading saying all they owe.
     */
    public function test_every_bill_is_on_one_page(): void
    {
        $vendor = $this->party('vendor');

        foreach ([3, 4, 5] as $daysAgo) {
            $this->file([[$vendor, 100]], $daysAgo);
        }

        $page = $this->page();

        $this->assertGreaterThanOrEqual(3, count($page->json('props.rows')));
        $this->assertGreaterThanOrEqual(count($page->json('props.rows')), $page->json('props.perPage'));
    }

    public function test_the_screen_is_the_payable_figure_and_the_tile_opens_it(): void
    {
        $vendor = $this->party('vendor');
        $this->file([[$vendor, 1200]], 8);

        // One owed nothing, so every vendor and the vendors owed differ on
        // any database.
        $this->party('vendor');

        $page = $this->page();
        $rows = collect($page->json('props.rows'));

        $tile = collect($this->actingAs($this->admin)->getJson('admin/dashboard')->json('props.tiles'))
            ->firstWhere('label', 'Payable');

        $this->assertEqualsWithDelta($rows->sum('due'), $tile['value'], 0.01, 'the bills add up to what is owed');
        $this->assertSame(route('report.payable'), $tile['href']);

        $vendors = $page->json('page.totals.vendors');
        $this->assertSame('owed to '.$vendors.' '.($vendors === 1 ? 'vendor' : 'vendors'), $tile['note']);
    }

    public function test_it_says_how_far_their_own_work_has_got(): void
    {
        $vendor = $this->party('vendor');
        $other = $this->party('vendor');

        $done = $this->file([[$vendor, 500, WorkFileModel::APPROVED]], 20);

        // A folder split between two vendors: only theirs is named and counted.
        $split = $this->file([[$vendor, 300, WorkFileModel::APPROVED, $this->hp], [$other, 250, WorkFileModel::DISPATCHED]], 15);
        $going = $this->file([[$vendor, 400]], 10);

        $rows = $this->rows($vendor)->keyBy('bill');

        $this->assertSame('Finished', $rows[$done->file_no]['state']);
        $this->assertSame(500.0, (float) $rows[$done->file_no]['finished']);

        $this->assertSame('Finished', $rows[$split->file_no]['state'], 'their part is done, whatever the other vendor\'s is');
        $this->assertSame($split->worksFor($vendor->id), $rows[$split->file_no]['works']);
        $this->assertStringNotContainsString($this->tr->name, $rows[$split->file_no]['works']);

        $this->assertSame('File Dispatch', $rows[$going->file_no]['state']);
        $this->assertSame(0.0, (float) $rows[$going->file_no]['finished']);
    }

    public function test_an_inactive_vendor_still_owed_is_listed_and_can_be_paid(): void
    {
        $vendor = $this->party('vendor');
        $this->file([[$vendor, 650]], 12);
        $vendor->is_active = 0;
        $vendor->save();

        $row = $this->rows($vendor)->first();
        $this->assertStringEndsWith('(inactive)', $row['vendor']);

        $entry = $this->actingAs($this->admin)->getJson($row['pay_url'])->assertOk();
        $this->assertContains($vendor->id, collect($entry->json('props.parties'))->pluck('id')->all());
    }

    public function test_record_payment_opens_the_entry_with_the_vendor_and_debit_picked(): void
    {
        $vendor = $this->party('vendor');
        $this->file([[$vendor, 650]], 12);

        $url = $this->rows($vendor)->first()['pay_url'];
        $this->assertSame(route('party.entry', ['type' => 'vendor', 'party_id' => $vendor->id, 'pay' => 1]), $url);

        $initial = $this->actingAs($this->admin)->getJson($url)->assertOk()->json('props.initial');
        $this->assertSame((string) $vendor->id, $initial['party_id']);
        $this->assertSame('debit', $initial['entry_type']);

        // Only a party of the screen's own type; anything else is the plain screen.
        $plain = $this->actingAs($this->admin)
            ->getJson(route('party.entry', ['type' => 'vendor', 'party_id' => $this->customer->id, 'pay' => 1]))
            ->json('props.initial');
        $this->assertSame('', $plain['party_id']);
        $this->assertSame('credit', $plain['entry_type']);

        // And what a refused save brings back wins over the link.
        $other = $this->party('vendor');
        $this->actingAs($this->admin)->from($url)->post(route('party.entry', 'vendor'), [
            'party_id' => $other->id,
            'entry_type' => 'credit',
            'txn_date' => now()->toDateString(),
        ])->assertRedirect($url)->assertSessionHasErrors('amount');

        $back = $this->actingAs($this->admin)->getJson($url)->json('props.initial');
        $this->assertSame((string) $other->id, $back['party_id']);
        $this->assertSame('credit', $back['entry_type']);
    }

    public function test_a_linked_customer_account_that_owes_is_noted(): void
    {
        if (! Schema::hasColumn('party', 'linked_vendor_id')) {
            $this->markTestSkipped('set-off not migrated');
        }

        $vendor = $this->party('vendor');
        $this->file([[$vendor, 800]], 9);

        $dealer = $this->party('customer');
        $this->entry($dealer, 'debit', 350, 4);
        $dealer->linked_vendor_id = $vendor->id;
        $dealer->save();

        $this->assertSame('Their customer account owes you 350.00 — set it off first', $this->rows($vendor)->first()['setoff_note']);
    }

    public function test_no_customer_name_and_no_whatsapp(): void
    {
        $vendor = $this->party('vendor');
        $this->file([[$vendor, 800]], 9);

        $page = $this->actingAs($this->admin)->get(route('report.payable'))->assertOk();

        $page->assertDontSee('Zeenat');
        $page->assertDontSee('wa.me');
        $page->assertSee('office only');

        // Who is owed goes into a spreadsheet too, which has no bands.
        $vendorColumn = collect($this->page()->json('props.columns'))->firstWhere('key', 'vendor');
        $this->assertTrue($vendorColumn['exportOnly']);
    }

    public function test_it_says_what_is_given_out_with_no_rate_yet(): void
    {
        $vendor = $this->party('vendor');
        $this->file([[$vendor, 0]], 4);

        $this->actingAs($this->admin)->get(route('report.payable'))
            ->assertOk()
            ->assertSee('work given out with no vendor rate agreed')
            ->assertSee(route('workfile.index', ['pending' => 'vendor']), false);
    }

    public function test_it_is_on_the_menu_and_shut_to_strangers(): void
    {
        $this->actingAs($this->admin)->get('admin/dashboard')->assertOk()->assertSee('Vendor Payments');

        $this->actingAs($this->admin)->get(route('report.payable'))
            ->assertOk()
            ->assertSee('data-vue="vue-vendor-payments"', false)
            ->assertSee('class="statement-summary owed-summary"', false);

        auth()->logout();

        $this->get(route('report.payable'))->assertRedirect(url('/admin'));
    }
}

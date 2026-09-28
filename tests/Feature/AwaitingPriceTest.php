<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Which files are still waiting on a vendor's rate.
 *
 * A work needs one when it was given to a vendor — and only then. Reported by
 * the owner on 2026-09-28: a file with one work at a vendor, priced, and one
 * done in-house sat under Awaiting Price with no margin after approval, with
 * every price on it agreed. The in-house work has no vendor and never will
 * have a rate, and was being asked for one because the folder had a vendor.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class AwaitingPriceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Awaiting Price Admin';
        $this->admin->email = 'awaiting-price-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' pricing';
        $party->mobile = '93600'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function type(): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = 'Priced Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /**
     * An approved file of works, each [customer price, vendor or null, vendor rate or null].
     * A work with no vendor is kept in-house, as the In-house Work screen keeps one.
     *
     * @param  array<int, array{0: float, 1: ?PartyModel, 2: ?float}>  $works
     */
    private function file(array $works, bool $legacy = false): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-AP-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->registration_no = 'BR06AP'.random_int(1000, 9999);
        $file->work_type_id = $this->type()->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::APPROVED;
        $file->save();

        foreach ($works as [$charge, $vendor, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $this->type()->id;
            $item->customer_amount = $charge;
            $item->status = WorkFileModel::APPROVED;
            $item->approved_on = now()->toDateString();
            $item->vendor_amount = $rate;

            if ($vendor && ! $legacy) {
                $item->vendor_id = $vendor->id;
                $item->vendor_date = now()->toDateString();
            } elseif (! $vendor) {
                $item->kept_in_house_on = now()->toDateString();
            }

            $item->save();
        }

        $file->rollUp();

        // From before works carried their own vendor: the folder names one.
        if ($legacy) {
            $file->vendor_id = $works[0][1]->id;
            $file->vendor_date = now()->toDateString();
            $file->vendor_amount = collect($works)->sum(fn ($w) => (float) $w[2]) ?: null;
        }

        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function chased(string $which): array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('workfile.index', ['pending' => $which]))->assertOk()->json('props.rows'))
            ->pluck('id')->all();
    }

    private function listed(WorkFileModel $file): array
    {
        return collect($this->actingAs($this->admin)->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);
    }

    public function test_a_work_kept_in_house_is_not_waiting_on_a_vendor_rate(): void
    {
        $before = WorkFileModel::pendingCounts();
        $month = WorkFileModel::summary()['month_unpriced'];

        // F-00052's shape: one work at a vendor, priced; the other in-house.
        $file = $this->file([[5000, $this->party('vendor'), 3160], [2500, null, null]]);

        $this->assertNotContains($file->id, $this->chased('vendor'));
        $this->assertNotContains($file->id, $this->chased('any'));
        $this->assertSame($before, WorkFileModel::pendingCounts(), 'the dashboard and the chips count it nowhere');

        // Charged 7,500, costing 3,160: a margin, not a blank.
        $this->assertEquals(4340, $this->listed($file)['margin']);
        $this->assertSame($month, WorkFileModel::summary()['month_unpriced'], 'and the month\'s margin includes it');
    }

    public function test_a_work_at_a_vendor_with_no_rate_is_still_chased(): void
    {
        $file = $this->file([[5000, $this->party('vendor'), null], [2500, null, null]]);

        $this->assertContains($file->id, $this->chased('vendor'));
        $this->assertNull($this->listed($file)['margin']);
    }

    /**
     * A folder split between two vendors names neither, so asked only of a
     * folder's vendor the missing rate on one of them was never chased.
     */
    public function test_a_folder_split_between_vendors_is_chased_for_the_rate_it_lacks(): void
    {
        $month = WorkFileModel::summary()['month_unpriced'];

        $file = $this->file([[5000, $this->party('vendor'), 3000], [2500, $this->party('vendor'), null]]);
        $this->assertNull($file->vendor_id, 'split: the folder has no one vendor');

        $this->assertContains($file->id, $this->chased('vendor'));
        $this->assertNull($this->listed($file)['margin']);
        $this->assertSame($month + 1, WorkFileModel::summary()['month_unpriced']);
    }

    public function test_a_folder_split_between_vendors_both_priced_is_not(): void
    {
        $file = $this->file([[5000, $this->party('vendor'), 3000], [2500, $this->party('vendor'), 1000]]);

        $this->assertNotContains($file->id, $this->chased('vendor'));
        $this->assertEquals(3500, $this->listed($file)['margin']);
    }

    /** From before works carried a vendor: the folder's vendor has all of it, as before. */
    public function test_an_older_file_whose_works_carry_no_vendor_is_asked_as_before(): void
    {
        $vendor = $this->party('vendor');

        $unpriced = $this->file([[5000, $vendor, 3000], [2500, $vendor, null]], legacy: true);
        $priced = $this->file([[5000, $vendor, 3000], [2500, $vendor, 1000]], legacy: true);

        $this->assertContains($unpriced->id, $this->chased('vendor'));
        $this->assertNotContains($priced->id, $this->chased('vendor'));
    }

    /**
     * The profit report by work type asks each work: the in-house one is
     * priced — it has no vendor to agree a rate with.
     */
    public function test_the_profit_report_counts_the_in_house_work_as_priced(): void
    {
        $file = $this->file([[5000, $this->party('vendor'), 3160], [2500, null, null]]);
        $inHouse = $file->items->firstWhere('vendor_id', null);

        $row = collect($this->actingAs($this->admin)
            ->getJson(route('report.profit', ['group' => 'work_type']))->assertOk()->json('props.rows'))
            ->firstWhere('id', (string) $inHouse->work_type_id);

        $this->assertNotNull($row);
        $this->assertNull($row['unpriced'], 'nothing awaiting a price on it');
        $this->assertEquals(2500, $row['margin']);

        // And by customer, which asks the file as a whole.
        $customer = collect($this->actingAs($this->admin)
            ->getJson(route('report.profit', ['group' => 'customer']))->assertOk()->json('props.rows'))
            ->firstWhere('id', (string) $this->customer->id);

        $this->assertNull($customer['unpriced']);
        $this->assertEquals(4340, $customer['margin']);
    }
}

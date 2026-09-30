<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Giving out again work a vendor handed back.
 *
 * Asked for by the owner on 2026-09-28. Work that came back kept its vendor,
 * so Give to Vendor never offered it again, and the only way to send the
 * papers to somebody else was to type a new file — charging the customer a
 * second time.
 *
 * Back whole, with all of its rate reversed, nothing of it is owed to anybody:
 * it is offered again, and giving it out (or keeping it here) takes the first
 * vendor's credit and its reversal, which net to nothing, off their statement.
 * Back with part of its rate still theirs, it stays theirs.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReGiveWorkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $dabloo;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Re-give Admin';
        $this->admin->email = 'regive-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');
        $this->dabloo = $this->party('vendor');

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    protected function tearDown(): void
    {
        WorkFileItemModel::assumePartReversals(null);

        parent::tearDown();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for re-give';
        $party->mobile = '93600'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A work type with no paper list: nothing to check, so nothing holds it up. */
    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function file(array $types): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RG-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01RG'.random_int(1000, 9999);
        $file->work_type_id = $types[0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000 * count($types);
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ($types as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 3000;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function work(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)
            ->where('work_type_id', $type->id)
            ->firstOrFail();
    }

    /** The post Give to Vendor makes; no jobs[] is the whole folder. */
    private function give(PartyModel $vendor, WorkFileModel $file, array $rates, bool $whole = false, string $on = '2026-09-12')
    {
        $amounts = [];

        foreach ($rates as [$type, $rate]) {
            $amounts[$this->work($file, $type)->id] = $rate;
        }

        return $this->actingAs($this->admin)->post(route('workfile.assign'), array_filter([
            'vendor_id' => $vendor->id,
            'vendor_date' => $on,
            'files' => [$file->id],
            'jobs' => $whole ? null : array_keys($amounts),
            'amounts' => $amounts,
        ], fn ($value) => $value !== null));
    }

    /** The post Papers Returned by Vendor makes: this folder, this vendor. */
    private function takeBack(WorkFileModel $file, PartyModel $vendor, ?float $part = null)
    {
        $key = $file->id.':'.$vendor->id;

        return $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => '2026-09-08',
            'files' => [$key],
            'amounts' => $part === null ? [] : [$key => $part],
            'remark' => 'Could not get it done',
        ]);
    }

    private function keep(WorkFileModel $file, WorkTypeModel $type)
    {
        return $this->actingAs($this->admin)->post(route('workfile.keepinhouse'), [
            'files' => [$file->id],
            'jobs' => [$this->work($file, $type)->id],
        ]);
    }

    /** What the Give to Vendor screen is offering, by file id. */
    private function onOffer(): array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('workfile.assign'))->assertOk()->json('props.files'))
            ->keyBy('id')
            ->all();
    }

    /** One work as Give to Vendor draws it. */
    private function offered(WorkFileModel $file, WorkTypeModel $type): array
    {
        $id = $this->work($file, $type)->id;

        return collect($this->onOffer()[$file->id]['items'] ?? [])->firstWhere('id', $id) ?? [];
    }

    /** @return array<int, float> vendor id => amount, of one role */
    private function lines(WorkFileModel $file, string $role): array
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', $role)
            ->pluck('amount', 'party_id')->map(fn ($amount) => (float) $amount)->all();
    }

    /** A folder given to Sharma at 1000 and handed back whole. */
    private function backWhole(array $types): WorkFileModel
    {
        $file = $this->file($types);

        $this->give($this->sharma, $file, [[$types[0], 1000]])->assertRedirect(route('workfile.index'));
        $this->takeBack($file, $this->sharma)->assertRedirect(route('workfile.index'));

        return $file->fresh();
    }

    // ---------------------------------------------------------------- offered

    public function test_work_back_whole_is_offered_again_saying_who_had_it(): void
    {
        $file = $this->backWhole([$this->tr]);

        // The folder holds nothing else: it is on the list for this work.
        $this->assertArrayHasKey($file->id, $this->onOffer());

        $work = $this->offered($file, $this->tr);
        $this->assertSame('here', $work['state']);
        $this->assertSame($this->sharma->name, $work['came_back_from']);
    }

    public function test_work_never_given_says_nothing_about_coming_back(): void
    {
        $file = $this->backWhole([$this->tr, $this->hpa]);

        $work = $this->offered($file, $this->hpa);
        $this->assertSame('here', $work['state']);
        $this->assertNull($work['came_back_from']);
    }

    public function test_approved_work_that_came_back_is_not_offered(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->give($this->sharma, $file, [[$this->tr, 1000], [$this->hpa, 800]]);

        $done = $this->work($file, $this->tr);
        $done->status = WorkFileModel::APPROVED;
        $done->save();

        $this->takeBack($file->fresh(), $this->sharma)->assertRedirect(route('workfile.index'));

        $this->assertSame('done', $this->offered($file, $this->tr)['state']);
        $this->assertNull($this->offered($file, $this->tr)['came_back_from']);
        $this->assertSame('here', $this->offered($file, $this->hpa)['state']);

        // Nor taken by a hand-made post, though its folder is on the list.
        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertSessionHas('error');
        $this->assertSame($this->sharma->id, (int) $this->work($file, $this->tr)->vendor_id);
    }

    /**
     * An in-house mark the edit screen left on a work it then gave out does
     * not hide it once it comes back: the vendor is what decides it.
     */
    public function test_a_left_over_in_house_mark_does_not_hide_it(): void
    {
        $file = $this->file([$this->tr]);
        $this->give($this->sharma, $file, [[$this->tr, 1000]]);
        $this->work($file, $this->tr)->forceFill(['kept_in_house_on' => '2026-09-02'])->save();
        $this->takeBack($file->fresh(), $this->sharma);

        $this->assertSame('here', $this->offered($file, $this->tr)['state']);
    }

    // ----------------------------------------------------------- given again

    public function test_given_again_it_leaves_the_first_vendors_statement(): void
    {
        $file = $this->backWhole([$this->tr]);

        // Credited and reversed: nothing owed, but two lines on their statement.
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor_return'));

        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertRedirect(route('workfile.index'));

        $this->assertEquals([$this->dabloo->id => 1200], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));

        $work = $this->work($file, $this->tr);
        $this->assertSame($this->dabloo->id, (int) $work->vendor_id);
        $this->assertNull($work->vendor_returned_on);
        $this->assertNull($work->vendor_returned_amount);
        $this->assertSame(WorkFileModel::DISPATCHED, $work->status);

        $folder = $file->fresh();
        $this->assertSame($this->dabloo->id, (int) $folder->vendor_id);
        $this->assertNull($folder->vendor_returned_on);
        $this->assertNull($folder->vendor_returned_amount);
    }

    public function test_given_again_to_the_same_vendor_at_a_new_rate(): void
    {
        $file = $this->backWhole([$this->tr]);

        $this->give($this->sharma, $file, [[$this->tr, 900]])->assertRedirect(route('workfile.index'));

        $this->assertEquals([$this->sharma->id => 900], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));
    }

    public function test_the_first_vendor_keeps_the_rest_of_what_they_hold(): void
    {
        $file = $this->backWhole([$this->tr, $this->hpa]);

        // The other work goes to them after the first came back.
        $this->give($this->sharma, $file, [[$this->hpa, 800]])->assertRedirect(route('workfile.index'));
        $this->assertEquals([$this->sharma->id => 1800], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor_return'));

        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertRedirect(route('workfile.index'));

        $this->assertEquals([$this->sharma->id => 800, $this->dabloo->id => 1200], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));
        $this->assertSame($this->sharma->id, (int) $this->work($file, $this->hpa)->vendor_id);
    }

    public function test_the_folder_box_takes_work_back_whole_with_the_rest(): void
    {
        $file = $this->backWhole([$this->tr, $this->hpa]);

        $this->give($this->dabloo, $file, [[$this->tr, 1200], [$this->hpa, 700]], whole: true)
            ->assertRedirect(route('workfile.index'));

        $this->assertEquals([$this->dabloo->id => 1900], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));
    }

    // ---------------------------------------------------- part still theirs

    public function test_work_with_part_of_its_rate_still_theirs_is_not_offered(): void
    {
        $file = $this->file([$this->tr]);
        $this->give($this->sharma, $file, [[$this->tr, 1000]]);
        $this->takeBack($file->fresh(), $this->sharma, 400)->assertRedirect(route('workfile.index'));

        $this->assertArrayNotHasKey($file->id, $this->onOffer());

        // Nor taken by a stale page.
        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertSessionHas('error');

        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 400], $this->lines($file, 'vendor_return'));
        $this->assertSame($this->sharma->id, (int) $this->work($file, $this->tr)->vendor_id);
    }

    public function test_work_with_part_still_theirs_is_said_to_be_back(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->give($this->sharma, $file, [[$this->tr, 1000]]);
        $this->takeBack($file->fresh(), $this->sharma, 400);

        $work = $this->offered($file, $this->tr);
        $this->assertSame('back', $work['state']);
        $this->assertNull($work['came_back_from']);
        $this->assertSame($this->sharma->name, $work['vendor']);
    }

    public function test_work_with_part_still_theirs_cannot_be_kept_in_house(): void
    {
        $file = $this->file([$this->tr]);
        $this->give($this->sharma, $file, [[$this->tr, 1000]]);
        $this->takeBack($file->fresh(), $this->sharma, 400);

        $this->keep($file, $this->tr)->assertSessionHas('error');

        $work = $this->work($file, $this->tr);
        $this->assertSame($this->sharma->id, (int) $work->vendor_id);
        $this->assertNull($work->kept_in_house_on);
    }

    // ------------------------------------------------------------ kept here

    public function test_kept_in_house_it_becomes_the_offices(): void
    {
        $file = $this->backWhole([$this->tr]);

        $this->keep($file, $this->tr)->assertRedirect(route('workfile.assign'));

        $work = $this->work($file, $this->tr);
        $this->assertNull($work->vendor_id);
        $this->assertNull($work->vendor_date);
        $this->assertNull($work->vendor_amount);
        $this->assertNull($work->vendor_returned_on);
        $this->assertNotNull($work->kept_in_house_on);

        $this->assertSame([], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));

        // Nobody's, as though never given: a folder still marked back from
        // a vendor is refused a new one on the edit screen (found in review).
        $folder = $file->fresh();
        $this->assertNull($folder->vendor_id);
        $this->assertNull($folder->vendor_date);
        $this->assertNull($folder->vendor_returned_on);
        $this->assertNull($folder->vendor_returned_amount);
        $this->assertFalse($folder->isReturnedByVendor());

        // Off Give to Vendor, and on the office's own list.
        $this->assertArrayNotHasKey($file->id, $this->onOffer());
        $this->assertTrue(WorkFileModel::inHouseWork()->contains('id', $work->id));
    }

    public function test_kept_in_house_the_vendor_keeps_the_rest_of_what_they_hold(): void
    {
        $file = $this->backWhole([$this->tr, $this->hpa]);
        $this->give($this->sharma, $file, [[$this->hpa, 800]]);

        $this->keep($file, $this->tr)->assertRedirect(route('workfile.assign'));

        $this->assertEquals([$this->sharma->id => 800], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));
        // The folder is theirs by the work they still have.
        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
    }

    /** Keeping work nobody had is as it was: nothing about money moves. */
    public function test_keeping_work_never_given_is_unchanged(): void
    {
        $file = $this->backWhole([$this->tr, $this->hpa]);

        $this->keep($file, $this->hpa)->assertRedirect(route('workfile.assign'));

        $this->assertNotNull($this->work($file, $this->hpa)->kept_in_house_on);
        // The work that came back is still theirs until it goes somewhere.
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor_return'));
        $this->assertSame('here', $this->offered($file, $this->tr)['state']);
    }

    // ------------------------------------------------- before the migration

    /**
     * Between a pull and a migrate a hand-back is reversed on the folder,
     * and whether a work came back whole cannot be told: nothing is offered.
     */
    public function test_nothing_is_offered_again_before_the_update(): void
    {
        WorkFileItemModel::assumePartReversals(false);

        $file = $this->backWhole([$this->tr]);

        $this->assertArrayNotHasKey($file->id, $this->onOffer());
        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertSessionHas('error');
        $this->keep($file, $this->tr)->assertSessionHas('error');

        $this->assertSame($this->sharma->id, (int) $this->work($file, $this->tr)->vendor_id);
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor_return'));
    }

    /**
     * And the screen says only that it is back: whether part of its rate is
     * still theirs cannot be told yet, and it often is not (found in review).
     */
    public function test_before_the_update_it_is_said_to_be_back_and_no_more(): void
    {
        WorkFileItemModel::assumePartReversals(false);

        // Listed for the work never given out.
        $file = $this->backWhole([$this->tr, $this->hpa]);

        $work = $this->offered($file, $this->tr);
        $this->assertSame('back_pending', $work['state']);
        $this->assertNull($work['came_back_from']);
        $this->assertSame('here', $this->offered($file, $this->hpa)['state']);
    }
}

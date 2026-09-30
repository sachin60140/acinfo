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
 * Give to Vendor and a folder from before works carried their own vendor.
 *
 * On such a folder only the folder names the vendor, and all of it is theirs
 * (see WorkFileModel::isOlderFolder()). Its works name nobody, so each read as
 * waiting for a vendor, and Give to Vendor offered them while the vendor still
 * had the papers — giving any of it out took their credit off their statement.
 * Found in the review of #56 on 2026-09-30.
 *
 * The rule is the one a work's own vendor has (see ReGiveWorkTest): out, or
 * back with part of its rate still theirs, it stays theirs; back whole, it can
 * go out again or be kept here, and they are let go of — their rates with
 * them, which left on works naming nobody would read as the office's cost.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class OlderFolderGiveToVendorTest extends TestCase
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
        $this->admin->name = 'Older Folder Admin';
        $this->admin->email = 'older-give-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');
        $this->dabloo = $this->party('vendor');

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for older folders';
        $party->mobile = '93700'.random_int(10000, 99999);
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

    /**
     * A folder given to Sharma the old way: the vendor on the folder, none on
     * its works, and their rate agreed on each work.
     *
     * @param  array<int, array{0: WorkTypeModel, 1: float}>  $rates  none: a folder with no works
     */
    private function olderFolder(array $rates, float $folderRate = 1000): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-OF-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01OF'.random_int(1000, 9999);
        $file->work_type_id = $rates ? $rates[0][0]->id : $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000 * max(1, count($rates));
        $file->vendor_amount = $rates ? null : $folderRate;
        $file->vendor_id = $this->sharma->id;
        $file->vendor_date = '2026-09-05';
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        foreach ($rates as [$type, $rate]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = 3000;
            $item->vendor_amount = $rate;
            $item->status = WorkFileModel::DISPATCHED;
            $item->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function work(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)
            ->where('work_type_id', $type->id)
            ->firstOrFail();
    }

    /** The post Give to Vendor makes; no works ticked is the whole folder. */
    private function give(PartyModel $vendor, WorkFileModel $file, array $rates = [])
    {
        $amounts = [];

        foreach ($rates as [$type, $rate]) {
            $amounts[$this->work($file, $type)->id] = $rate;
        }

        return $this->actingAs($this->admin)->post(route('workfile.assign'), array_filter([
            'vendor_id' => $vendor->id,
            'vendor_date' => '2026-09-12',
            'files' => [$file->id],
            'jobs' => $amounts ? array_keys($amounts) : null,
            'amounts' => $amounts,
        ], fn ($value) => $value !== null));
    }

    /** The post Papers Returned by Vendor makes: this folder, this vendor. */
    private function takeBack(WorkFileModel $file, ?float $part = null)
    {
        $key = $file->id.':'.$this->sharma->id;

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

    private function twoWorks(): WorkFileModel
    {
        $file = $this->olderFolder([[$this->tr, 1000], [$this->hpa, 800]]);

        // All of it theirs, on the folder's say-so.
        $this->assertEquals([$this->sharma->id => 1800], $this->lines($file, 'vendor'));

        return $file;
    }

    // ------------------------------------------------------ still theirs

    public function test_an_older_folder_out_with_its_vendor_is_not_offered(): void
    {
        $file = $this->twoWorks();

        $this->assertArrayNotHasKey($file->id, $this->onOffer());

        // Nor taken by a stale page.
        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertSessionHas('error');

        $this->assertNull($this->work($file, $this->tr)->vendor_id);
        $this->assertEquals([$this->sharma->id => 1800], $this->lines($file, 'vendor'));
        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
    }

    public function test_nor_is_any_of_it_kept_in_house_while_out(): void
    {
        $file = $this->twoWorks();

        $this->keep($file, $this->tr)->assertSessionHas('error');

        $this->assertNull($this->work($file, $this->tr)->kept_in_house_on);
        $this->assertEquals([$this->sharma->id => 1800], $this->lines($file, 'vendor'));
    }

    public function test_back_with_part_of_its_rate_still_theirs_it_stays_theirs(): void
    {
        $file = $this->twoWorks();
        $this->takeBack($file, 400)->assertRedirect(route('workfile.index'));

        $this->assertArrayNotHasKey($file->id, $this->onOffer());
        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertSessionHas('error');
        $this->keep($file, $this->tr)->assertSessionHas('error');

        $this->assertEquals([$this->sharma->id => 1800], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 400], $this->lines($file, 'vendor_return'));
        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
    }

    // ----------------------------------------------------------- back whole

    public function test_back_whole_it_is_offered_saying_who_it_came_back_from(): void
    {
        $file = $this->twoWorks();
        $this->takeBack($file)->assertRedirect(route('workfile.index'));

        foreach ([$this->tr, $this->hpa] as $type) {
            $work = $this->offered($file, $type);
            $this->assertSame('here', $work['state']);
            $this->assertSame($this->sharma->name, $work['came_back_from']);
        }
    }

    public function test_given_out_again_their_folder_and_rates_go_with_them(): void
    {
        $file = $this->twoWorks();
        $this->takeBack($file);

        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertRedirect(route('workfile.index'));

        $this->assertEquals([$this->dabloo->id => 1200], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));

        // Their rate on the work staying here would be the office's cost now.
        $this->assertNull($this->work($file, $this->hpa)->vendor_amount);

        $folder = $file->fresh();
        $this->assertSame($this->dabloo->id, (int) $folder->vendor_id);
        $this->assertEquals(1200, $folder->vendor_amount);
        $this->assertNull($folder->vendor_returned_on);

        // The other work waits for a vendor, with nobody's name on it.
        $work = $this->offered($file, $this->hpa);
        $this->assertSame('here', $work['state']);
        $this->assertNull($work['came_back_from']);
    }

    public function test_kept_in_house_the_folder_is_nobodys(): void
    {
        $file = $this->twoWorks();
        $this->takeBack($file);

        $this->keep($file, $this->tr)->assertRedirect(route('workfile.assign'));

        $this->assertSame([], $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));

        $folder = $file->fresh();
        $this->assertNull($folder->vendor_id);
        $this->assertNull($folder->vendor_date);
        $this->assertNull($folder->vendor_returned_on);
        $this->assertNull($folder->vendor_amount);

        $this->assertNotNull($this->work($file, $this->tr)->kept_in_house_on);
        $this->assertNull($this->work($file, $this->tr)->vendor_amount);
        $this->assertNull($this->work($file, $this->hpa)->vendor_amount);
        $this->assertTrue(WorkFileModel::inHouseWork()->contains('id', $this->work($file, $this->tr)->id));

        $work = $this->offered($file, $this->hpa);
        $this->assertSame('here', $work['state']);
        $this->assertNull($work['came_back_from']);
    }

    /**
     * A folder whose works carry their own vendor is not an older folder, even
     * when every work of theirs came back whole and the folder reads back
     * whole from them: sending one of those works out again leaves the other,
     * still theirs, and its rate, where they are.
     */
    public function test_on_a_newer_folder_their_other_work_stays_theirs(): void
    {
        $file = $this->olderFolder([[$this->tr, 1000], [$this->hpa, 800]]);

        // Made newer: each work names Sharma, the folder by its works.
        foreach ([$this->tr, $this->hpa] as $type) {
            $this->work($file, $type)->forceFill(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-05'])->save();
        }

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        $this->takeBack($file->fresh())->assertRedirect(route('workfile.index'));
        $this->assertFalse(WorkFileModel::isOlderFolder($file->fresh()->items));

        $this->give($this->dabloo, $file, [[$this->tr, 1200]])->assertRedirect(route('workfile.index'));

        $this->assertEquals(800, $this->work($file, $this->hpa)->vendor_amount);
        $this->assertEquals([$this->sharma->id => 800, $this->dabloo->id => 1200], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 800], $this->lines($file, 'vendor_return'));
    }

    // ------------------------------------------------------ the folder's say

    public function test_the_folder_says_whether_its_vendor_still_has_it(): void
    {
        $out = $this->twoWorks();
        $this->assertTrue($out->isHeldByItsVendor());
        $this->assertFalse($out->cameBackWholeFromItsVendor());

        $part = $this->twoWorks();
        $this->takeBack($part, 400);
        $part = $part->fresh();
        $this->assertTrue($part->isHeldByItsVendor());
        $this->assertFalse($part->cameBackWholeFromItsVendor());

        $whole = $this->twoWorks();
        $this->takeBack($whole);
        $whole = $whole->fresh();
        $this->assertFalse($whole->isHeldByItsVendor());
        $this->assertTrue($whole->cameBackWholeFromItsVendor());

        // A folder whose works carry their vendor: theirs by the works, not
        // by the folder, whatever the folder says.
        $newer = $this->olderFolder([[$this->tr, 1000]]);
        $this->work($newer, $this->tr)->forceFill(['vendor_id' => $this->sharma->id, 'vendor_date' => '2026-09-05'])->save();
        $newer = $newer->fresh();
        $this->assertFalse($newer->isHeldByItsVendor());
        $this->takeBack($newer);
        $this->assertFalse($newer->fresh()->cameBackWholeFromItsVendor());

        // Never given: nobody's to hold or to have come back from.
        $never = $this->olderFolder([[$this->tr, 1000]]);
        $never->forceFill(['vendor_id' => null, 'vendor_date' => null])->save();
        $never = $never->fresh();
        $this->assertFalse($never->isHeldByItsVendor());
        $this->assertFalse($never->cameBackWholeFromItsVendor());
    }

    public function test_letting_go_of_its_vendor_leaves_it_nobodys(): void
    {
        $file = $this->twoWorks();
        $this->takeBack($file);

        $file = $file->fresh();
        $file->letGoOfItsVendor();
        $file->save();

        $folder = $file->fresh();
        $this->assertNull($folder->vendor_id);
        $this->assertNull($folder->vendor_date);
        $this->assertNull($folder->vendor_returned_on);
        $this->assertNull($folder->vendor_returned_amount);
        $this->assertNull($folder->vendor_amount);
        $this->assertNull($this->work($file, $this->tr)->vendor_amount);
        $this->assertNull($this->work($file, $this->hpa)->vendor_amount);
    }

    // ---------------------------------------------------- no works at all

    public function test_a_folder_with_no_works_out_with_its_vendor_is_not_offered(): void
    {
        $file = $this->olderFolder([]);
        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor'));

        $this->assertArrayNotHasKey($file->id, $this->onOffer());
        $this->give($this->dabloo, $file)->assertSessionHas('error');

        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
    }

    public function test_a_folder_with_no_works_back_in_part_stays_theirs(): void
    {
        $file = $this->olderFolder([]);
        $this->takeBack($file, 400)->assertRedirect(route('workfile.index'));

        $this->assertArrayNotHasKey($file->id, $this->onOffer());
        $this->give($this->dabloo, $file)->assertSessionHas('error');

        $this->assertEquals([$this->sharma->id => 1000], $this->lines($file, 'vendor'));
        $this->assertEquals([$this->sharma->id => 400], $this->lines($file, 'vendor_return'));
    }

    public function test_a_folder_with_no_works_back_whole_goes_out_again_without_their_rate(): void
    {
        $file = $this->olderFolder([]);
        $this->takeBack($file)->assertRedirect(route('workfile.index'));

        $this->assertArrayHasKey($file->id, $this->onOffer());

        $this->give($this->dabloo, $file)->assertRedirect(route('workfile.index'));

        $folder = $file->fresh();
        $this->assertSame($this->dabloo->id, (int) $folder->vendor_id);
        $this->assertSame('2026-09-12', date('Y-m-d', strtotime($folder->vendor_date)));
        // Theirs to agree afresh, on the edit screen, as for any such folder.
        $this->assertNull($folder->vendor_amount);
        $this->assertNull($folder->vendor_returned_on);
        $this->assertNull($folder->vendor_returned_amount);

        $this->assertArrayNotHasKey($this->sharma->id, $this->lines($file, 'vendor'));
        $this->assertSame([], $this->lines($file, 'vendor_return'));
    }
}

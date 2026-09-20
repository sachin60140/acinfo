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
 * Work the office is doing itself.
 *
 * A folder can hold a transfer that went to one agent, a hypothecation addition
 * that went to another, and a termination being done at this counter. Give to
 * Vendor offers every work with no vendor that is not finished, which is exactly
 * what the third one looks like — so the folder sat on the list of work waiting
 * to go out for as long as the termination took, next to work that really was
 * waiting.
 *
 * Saying so takes it off that list and changes nothing else: the customer is
 * charged the same, no vendor is credited anything, and the work goes on through
 * the status board as before.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class KeptInHouseTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'In House Admin';
        $this->admin->email = 'inhouse-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');
        $this->shailendra = $this->party('vendor');

        $this->hpt = $this->workType('HPT');
        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' in house';
        $party->mobile = '93700'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** No paper list, so nothing holds the work up on its way out. */
    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** @param  array<int, WorkTypeModel>  $types */
    private function file(array $types): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-IH-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01IH'.random_int(1000, 9999);
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

    private function jobOf(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)
            ->where('work_type_id', $type->id)
            ->firstOrFail();
    }

    /** The post the Keep in-house button makes: the same ticks, elsewhere. */
    private function keep(WorkFileModel $file, array $jobs)
    {
        return $this->actingAs($this->admin)->post(route('workfile.keepinhouse'), [
            'files' => [$file->id],
            'jobs' => array_map(fn ($job) => $job->id, $jobs),
        ]);
    }

    private function give(PartyModel $vendor, WorkFileModel $file, array $jobs, float $rate = 1000)
    {
        return $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => '2026-09-05',
            'files' => [$file->id],
            'jobs' => array_map(fn ($job) => $job->id, $jobs),
            'amounts' => array_combine(
                array_map(fn ($job) => $job->id, $jobs),
                array_fill(0, count($jobs), $rate)
            ),
        ]);
    }

    /** What Give to Vendor is offering, by file id. */
    private function onOffer(): array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('workfile.assign'))->assertOk()->json('props.files'))
            ->keyBy('id')
            ->all();
    }

    // ------------------------------------------------------------ saying so

    public function test_work_kept_in_house_is_marked_with_the_day_it_was_decided(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->keep($file, [$this->jobOf($file, $this->tr)])->assertRedirect();

        $this->assertSame(now()->toDateString(), $this->jobOf($file, $this->tr)->kept_in_house_on);
        $this->assertNull($this->jobOf($file, $this->hpa)->kept_in_house_on, 'the work beside it was kept too');
    }

    public function test_it_is_no_longer_offered_to_a_vendor(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->keep($file, [$this->jobOf($file, $this->tr)]);

        $works = collect($this->onOffer()[$file->id]['items'])->keyBy('work_type_id');

        $this->assertSame('kept', $works[$this->tr->id]['state']);
        $this->assertSame('here', $works[$this->hpa->id]['state'], 'the rest of the folder stopped being offered');
    }

    /** A folder with nothing left waiting is not waiting. */
    public function test_a_folder_whose_remaining_work_is_all_ours_leaves_the_screen(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->hpa)]);
        $this->keep($file->fresh(), [$this->jobOf($file, $this->tr)]);

        $this->assertArrayNotHasKey($file->id, $this->onOffer());
    }

    public function test_the_history_says_which_work_was_kept(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->keep($file, [$this->jobOf($file, $this->tr)]);

        $said = $file->fresh()->statusLog()->latest('id')->value('remark');

        $this->assertStringContainsString($this->tr->name, $said);
        $this->assertStringContainsString('kept in-house', $said);
        $this->assertStringNotContainsString($this->hpa->name, $said);
    }

    // -------------------------------------------------------------- the money

    public function test_keeping_work_credits_nobody(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->keep($file, [$this->jobOf($file, $this->tr)]);

        $vendorLines = PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'vendor')->count();

        $this->assertSame(0, $vendorLines, 'keeping work in-house wrote somebody a credit');
    }

    public function test_the_customer_is_charged_exactly_as_before(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $before = PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'customer')->value('amount');

        $this->keep($file, [$this->jobOf($file, $this->tr)]);

        $this->assertEquals($before, PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'customer')->value('amount'));
    }

    // ----------------------------------------------- the question the office asked

    /**
     * HPT to one vendor, TR done here, HPA to another.
     *
     * Each vendor is owed for their own work, the work being done here costs
     * nobody anything, and the folder is not left sitting on a list asking to be
     * given away.
     */
    public function test_a_folder_split_between_two_vendors_and_the_office(): void
    {
        $file = $this->file([$this->hpt, $this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->hpt)], 1800);
        $this->give($this->shailendra, $file->fresh(), [$this->jobOf($file, $this->hpa)], 1200);
        $this->keep($file->fresh(), [$this->jobOf($file, $this->tr)]);

        $lines = PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'vendor')->get()->keyBy('party_id');

        $this->assertCount(2, $lines, 'the work being done here found itself a vendor');
        $this->assertEquals(1800, $lines[$this->sharma->id]->amount);
        $this->assertEquals(1200, $lines[$this->shailendra->id]->amount);

        // What the folder cost is what was paid out, and no more.
        $this->assertEquals(3000, $file->fresh()->vendor_amount);

        // Split, so no single vendor — and never "In-house" in a list.
        $this->assertNull($file->fresh()->vendor_id);
        $this->assertSame('2 vendors', $file->fresh()->vendorLabel());

        $this->assertArrayNotHasKey($file->id, $this->onOffer(), 'the folder is still asking to be given out');
    }

    // ------------------------------------------------------------ a stale page

    public function test_work_already_with_a_vendor_cannot_be_claimed_as_ours(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);

        $this->give($this->sharma, $file, [$transfer], 1800);

        $this->keep($file->fresh(), [$transfer]);

        $again = $transfer->fresh();

        $this->assertNull($again->kept_in_house_on, 'work with a vendor was marked as ours');
        $this->assertSame($this->sharma->id, (int) $again->vendor_id);
    }

    public function test_nothing_is_reported_when_there_was_nothing_to_keep(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);

        $this->give($this->sharma, $file, [$transfer], 1800);

        $this->keep($file->fresh(), [$transfer])->assertSessionHas('error');
    }

    /** Ours, and then given away anyway, is a decision that has to be undone first. */
    public function test_work_kept_in_house_is_not_sent_by_a_stale_page(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);

        $this->keep($file, [$transfer]);
        $this->give($this->sharma, $file->fresh(), [$transfer], 1800);

        $this->assertNull($transfer->fresh()->vendor_id, 'work being done here was handed to a vendor');
    }

    // ----------------------------------------------------- letting go of it again

    public function test_the_edit_screen_says_which_work_is_ours(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->keep($file, [$this->jobOf($file, $this->tr)]);

        $works = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props.items'))
            ->keyBy('work_type_id');

        $this->assertTrue($works[$this->tr->id]['in_house']);
        $this->assertFalse($works[$this->hpa->id]['in_house']);
    }

    public function test_unticking_it_on_the_edit_screen_offers_it_again(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);
        $addition = $this->jobOf($file, $this->hpa);

        $this->keep($file, [$transfer]);

        // The edit form, saved with the box unticked: an unticked box posts
        // nothing at all, which is what says it is no longer ours.
        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '6000',
            'status' => WorkFileModel::IN_OFFICE,
            'items' => [
                $transfer->id => ['work_type_id' => $this->tr->id, 'customer_amount' => '3000', 'vendor_amount' => ''],
                $addition->id => ['work_type_id' => $this->hpa->id, 'customer_amount' => '3000', 'vendor_amount' => ''],
            ],
        ])->assertRedirect();

        $this->assertNull($transfer->fresh()->kept_in_house_on);

        $works = collect($this->onOffer()[$file->id]['items'])->keyBy('work_type_id');
        $this->assertSame('here', $works[$this->tr->id]['state']);
    }

    public function test_saving_the_edit_screen_with_the_box_ticked_keeps_it(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);
        $addition = $this->jobOf($file, $this->hpa);

        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $this->customer->id,
            'customer_amount' => '6000',
            'status' => WorkFileModel::IN_OFFICE,
            'items' => [
                $transfer->id => ['work_type_id' => $this->tr->id, 'customer_amount' => '3000', 'vendor_amount' => '', 'in_house' => '1'],
                $addition->id => ['work_type_id' => $this->hpa->id, 'customer_amount' => '3000', 'vendor_amount' => ''],
            ],
        ])->assertRedirect();

        $this->assertSame(now()->toDateString(), $transfer->fresh()->kept_in_house_on);
        $this->assertNull($addition->fresh()->kept_in_house_on);
    }
}

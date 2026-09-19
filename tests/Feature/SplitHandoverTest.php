<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
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
 * Giving one work out of a folder, from the screen that gives it.
 *
 * SplitVendorLedgerTest covers the shape underneath: what two vendors on one
 * folder do to the money. This covers the handover itself — the post the Give
 * to Vendor screen makes, and what the office sees afterwards.
 *
 * The rule the whole feature turns on: jobs[] names the work leaving. Left out
 * entirely, the whole folder goes, which is what every older form posts and
 * what the ordinary handover still is.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class SplitHandoverTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private PartyModel $shailendra;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Split Handover Admin';
        $this->admin->email = 'handover-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');
        $this->shailendra = $this->party('vendor');

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for handover';
        $party->mobile = '93500'.random_int(10000, 99999);
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
        $file->file_no = 'F-SH-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01SH'.random_int(1000, 9999);
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

    /** The post the screen makes: the folder, the work in it, and a rate each. */
    private function give(PartyModel $vendor, WorkFileModel $file, array $jobs, array $rates = [], string $on = '2026-09-05')
    {
        $amounts = [];

        foreach ($jobs as $i => $job) {
            $amounts[$job->id] = $rates[$i] ?? 1000;
        }

        return $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $vendor->id,
            'vendor_date' => $on,
            'files' => [$file->id],
            'jobs' => array_map(fn ($job) => $job->id, $jobs),
            'amounts' => $amounts,
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

    private function lines(WorkFileModel $file): array
    {
        return PartyLedgerModel::where('work_file_id', $file->id)
            ->where('file_role', 'vendor')
            ->get()
            ->keyBy('party_id')
            ->all();
    }

    // ------------------------------------------------------------- one work out

    public function test_only_the_ticked_work_goes_to_the_vendor(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);
        $addition = $this->jobOf($file, $this->hpa);

        $this->give($this->sharma, $file, [$transfer])->assertRedirect();

        $this->assertSame($this->sharma->id, (int) $transfer->fresh()->vendor_id);
        $this->assertNull($addition->fresh()->vendor_id, 'the work that stayed here went out anyway');
    }

    public function test_the_work_left_behind_stays_in_the_office(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);

        $this->assertSame(WorkFileModel::DISPATCHED, $this->jobOf($file, $this->tr)->status);
        $this->assertSame(WorkFileModel::IN_OFFICE, $this->jobOf($file, $this->hpa)->status);
    }

    /**
     * A folder split between two vendors has no vendor of its own. Naming one
     * of them on the folder would put the other's work on his statement.
     */
    public function test_a_part_handover_leaves_the_folder_without_one_vendor(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);

        $fresh = $file->fresh();

        $this->assertSame($this->sharma->id, (int) $fresh->vendor_id, 'one vendor holds all that is out');

        $this->give($this->shailendra, $file->fresh(), [$this->jobOf($file, $this->hpa)]);

        $this->assertNull($file->fresh()->vendor_id, 'a folder at two vendors still claims one of them');
    }

    public function test_each_vendor_is_credited_only_for_the_work_they_were_given(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)], [1800]);
        $this->give($this->shailendra, $file->fresh(), [$this->jobOf($file, $this->hpa)], [1200]);

        $lines = $this->lines($file);

        $this->assertCount(2, $lines, 'a folder given to two vendors did not write a line each');
        $this->assertEquals(1800, $lines[$this->sharma->id]->amount);
        $this->assertEquals(1200, $lines[$this->shailendra->id]->amount);
    }

    // --------------------------------------------------- back for the other half

    public function test_the_folder_comes_back_to_the_screen_for_its_other_half(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);

        $this->assertArrayHasKey($file->id, $this->onOffer(), 'a half-given folder left the screen');
    }

    public function test_the_screen_says_which_work_has_gone_and_who_has_it(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);

        $works = collect($this->onOffer()[$file->id]['items'])->keyBy('work_type_id');

        $gone = $works[$this->tr->id];
        $this->assertSame('out', $gone['state']);
        $this->assertSame($this->sharma->name, $gone['vendor']);
        $this->assertSame('05-09-2026', $gone['vendor_date']);

        $this->assertSame('here', $works[$this->hpa->id]['state'], 'the work still here cannot be ticked');
    }

    public function test_a_folder_leaves_the_screen_once_all_of_its_work_has_gone(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);
        $this->give($this->shailendra, $file->fresh(), [$this->jobOf($file, $this->hpa)]);

        $this->assertArrayNotHasKey($file->id, $this->onOffer());
    }

    /** The half that left is not leaving twice, whatever a stale page ticks. */
    public function test_work_already_with_a_vendor_is_never_sent_again(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);

        $this->give($this->sharma, $file, [$transfer], [1800]);

        // A stale page, ticking work that has gone since it was drawn.
        $this->give($this->shailendra, $file->fresh(), [$transfer, $this->jobOf($file, $this->hpa)]);

        $again = $transfer->fresh();

        $this->assertSame($this->sharma->id, (int) $again->vendor_id, 'the work changed hands without a correction');
        $this->assertEquals(1800, $again->vendor_amount, 'the second handover overwrote the first rate');
    }

    public function test_nothing_is_reported_when_the_ticked_work_has_already_gone(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $transfer = $this->jobOf($file, $this->tr);

        $this->give($this->sharma, $file, [$transfer]);

        $this->give($this->shailendra, $file->fresh(), [$transfer])
            ->assertSessionHas('error');
    }

    // ------------------------------------------------------------ the whole folder

    /** Every older form posts no jobs[] at all, and still hands the folder over. */
    public function test_a_post_with_no_works_named_sends_the_whole_folder(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->sharma->id,
            'vendor_date' => '2026-09-05',
            'files' => [$file->id],
            'amounts' => [],
        ])->assertRedirect();

        $this->assertSame($this->sharma->id, (int) $this->jobOf($file, $this->tr)->vendor_id);
        $this->assertSame($this->sharma->id, (int) $this->jobOf($file, $this->hpa)->vendor_id);
        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
    }

    public function test_ticking_every_work_is_the_same_as_sending_the_folder(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr), $this->jobOf($file, $this->hpa)]);

        $this->assertSame($this->sharma->id, (int) $file->fresh()->vendor_id);
        $this->assertSame(WorkFileModel::DISPATCHED, $file->fresh()->status);
        $this->assertArrayNotHasKey($file->id, $this->onOffer());
    }

    // -------------------------------------------------------- who has it, in a list

    /**
     * A folder at two vendors has none of its own, and a column that reads that
     * straight would call it work nobody was given.
     */
    public function test_a_split_folder_is_never_listed_as_in_house(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);
        $this->give($this->shailendra, $file->fresh(), [$this->jobOf($file, $this->hpa)]);

        $this->assertSame('2 vendors', $this->listed($file)['vendor']);
    }

    public function test_a_folder_at_one_vendor_still_says_their_name(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr), $this->jobOf($file, $this->hpa)]);

        $this->assertSame($this->sharma->name, $this->listed($file)['vendor']);
    }

    public function test_work_nobody_was_given_still_reads_as_in_house(): void
    {
        $file = $this->file([$this->tr]);

        $this->assertSame('In-house', $this->listed($file)['vendor']);
    }

    /** The folder's row on the files list. */
    private function listed(WorkFileModel $file): array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('workfile.index'))->assertOk()->json('props.rows'))
            ->firstWhere('id', $file->id);
    }

    // ------------------------------------------------------------------ the history

    public function test_a_part_handover_names_the_work_that_went(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr)]);

        $said = $file->fresh()->statusLog()->latest('id')->value('remark');

        $this->assertStringContainsString($this->tr->name, $said);
        $this->assertStringNotContainsString($this->hpa->name, $said);
        $this->assertStringContainsString($this->sharma->name, $said);
    }

    /** A whole folder reads as it always did, on statements already filed. */
    public function test_a_whole_handover_still_reads_as_given_to_the_vendor(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->give($this->sharma, $file, [$this->jobOf($file, $this->tr), $this->jobOf($file, $this->hpa)]);

        $said = $file->fresh()->statusLog()->latest('id')->value('remark');

        $this->assertSame('Given to '.$this->sharma->name, $said);
    }
}

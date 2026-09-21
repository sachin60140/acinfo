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
 * The office's own to-do list: work it said it is doing itself, not yet done.
 *
 * Keep in-house takes a work off Give to Vendor, and until now it then showed
 * up nowhere but inside its folder. This list is read for two things: that
 * everything the office kept is on it until it is finished, and that nothing
 * else is — above all not work that simply has no vendor yet, which is waiting
 * to be given out and belongs on Give to Vendor.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class InHouseWorkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private WorkTypeModel $hpt;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'In-house List Admin';
        $this->admin->email = 'inhouse-list-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');

        $this->hpt = $this->workType('HPT');
        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' in-house list';
        $party->mobile = '93701'.random_int(10000, 99999);
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

    /** @param  array<int, WorkTypeModel>  $types */
    private function file(array $types, string $received = '2026-09-01'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-IHL-'.uniqid();
        $file->received_date = $received;
        $file->registration_no = 'BR01HL'.random_int(1000, 9999);
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

        return $file->fresh();
    }

    private function jobOf(WorkFileModel $file, WorkTypeModel $type): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)
            ->where('work_type_id', $type->id)
            ->firstOrFail();
    }

    /** Through the button on Give to Vendor, the only way work gets marked. */
    private function keep(WorkFileModel $file, WorkTypeModel ...$types): void
    {
        $this->actingAs($this->admin)->post(route('workfile.keepinhouse'), [
            'files' => [$file->id],
            'jobs' => array_map(fn ($type) => $this->jobOf($file, $type)->id, $types),
        ])->assertRedirect();
    }

    /** What the screen hands its grid, as the page draws it. */
    private function props(): array
    {
        $html = $this->actingAs($this->admin)
            ->get(route('workfile.inhouse'))
            ->assertOk()->getContent();

        preg_match('#data-vue="vue-inhouse-work" data-props="(.*?)"></div>#s', $html, $mount);

        $this->assertNotEmpty($mount, 'the page mounted no list');

        return json_decode(html_entity_decode($mount[1], ENT_QUOTES, 'UTF-8'), true);
    }

    /** This file's rows, in the order the list gives them. */
    private function rowsFor(WorkFileModel $file): array
    {
        return collect($this->props()['rows'])->where('file_id', $file->id)->values()->all();
    }

    // -------------------------------------------------------------- on the list

    /**
     * The folder the feature was asked for: HPT with a vendor, TR done here,
     * HPA not given to anybody yet. Only the TR is the office's to do.
     */
    public function test_only_the_work_kept_in_house_is_listed(): void
    {
        $file = $this->file([$this->hpt, $this->tr, $this->hpa]);

        $hpt = $this->jobOf($file, $this->hpt);
        $hpt->vendor_id = $this->sharma->id;
        $hpt->vendor_date = '2026-09-05';
        $hpt->save();

        $this->keep($file, $this->tr);

        $rows = $this->rowsFor($file);

        $this->assertCount(1, $rows);
        $this->assertSame($this->tr->name, $rows[0]['work']);
    }

    /** No vendor is not the same as in-house: that work is waiting to go out. */
    public function test_work_merely_without_a_vendor_is_not_listed(): void
    {
        $this->assertSame([], $this->rowsFor($this->file([$this->hpa])));
    }

    public function test_it_says_whose_work_it_is_and_what_it_is_charged(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);

        $row = $this->rowsFor($file)[0];

        $this->assertSame($file->file_no, $row['file_no']);
        $this->assertSame($file->registration_no, $row['registration_no']);
        $this->assertSame($this->customer->name, $row['customer']);
        $this->assertEquals(3000, $row['charged']);
        $this->assertSame('01-09-2026', $row['received']);
        $this->assertSame(now()->format('d-m-Y'), $row['kept_on']);
        $this->assertSame('In Office', $row['status']);
        $this->assertSame(route('workfile.edit', $file->id), $row['edit_url']);
    }

    /** One row per work, so two works kept on one folder are two things to do. */
    public function test_each_work_kept_is_its_own_row(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->keep($file, $this->tr, $this->hpa);

        $this->assertCount(2, $this->rowsFor($file));
    }

    public function test_the_customer_waiting_longest_is_at_the_top(): void
    {
        $newer = $this->file([$this->tr], '2026-09-10');
        $older = $this->file([$this->tr], '2026-08-20');

        $this->keep($newer, $this->tr);
        $this->keep($older, $this->tr);

        $ids = array_column($this->props()['rows'], 'file_id');

        $this->assertLessThan(array_search($newer->id, $ids), array_search($older->id, $ids));
    }

    // -------------------------------------------------------------- off the list

    public function test_work_falls_off_once_it_is_approved(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);

        $job = $this->jobOf($file, $this->tr);
        $job->status = WorkFileModel::APPROVED;
        $job->approved_on = '2026-09-15';
        $job->save();

        $this->assertSame([], $this->rowsFor($file));
    }

    public function test_a_cancelled_folder_takes_its_work_with_it(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);

        $file->status = WorkFileModel::CANCELLED;
        $file->save();

        $this->assertSame([], $this->rowsFor($file));
    }

    /**
     * A vendor typed on the edit screen reaches every work on the folder and
     * leaves the in-house mark where it was. The vendor is what decides it.
     */
    public function test_work_given_to_a_vendor_since_is_not_listed(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);

        $job = $this->jobOf($file, $this->tr);
        $job->vendor_id = $this->sharma->id;
        $job->save();

        $this->assertNotNull($job->fresh()->kept_in_house_on, 'the premise: the mark is still there');
        $this->assertSame([], $this->rowsFor($file));
    }

    // -------------------------------------------------------------- updating

    /**
     * The Update dialog is handed this row's one work — never the rest of the
     * folder, whose other works may be with a vendor.
     */
    public function test_each_row_offers_to_move_only_its_own_work(): void
    {
        $file = $this->file([$this->hpt, $this->tr]);

        $hpt = $this->jobOf($file, $this->hpt);
        $hpt->vendor_id = $this->sharma->id;
        $hpt->save();

        $this->keep($file, $this->tr);

        $row = $this->rowsFor($file)[0];
        $tr = $this->jobOf($file, $this->tr);

        $this->assertSame('Update', $row['update']);
        $this->assertSame($this->customer->name, $row['party_name']);
        $this->assertSame([$tr->id], array_column($row['items'], 'id'));
        $this->assertSame(WorkFileModel::IN_OFFICE, $row['items'][0]['status']);
    }

    public function test_the_dialog_posts_to_update_status_and_comes_back_here(): void
    {
        $props = $this->props();

        $this->assertSame(route('workfile.status'), $props['action']);
        $this->assertSame(route('workfile.inhouse'), $props['returnTo']);
        $this->assertSame(WorkFileModel::JOB_STATUSES, $props['jobStatuses']);
    }

    /** The round trip the button makes, end to end. */
    public function test_a_change_made_from_the_list_is_saved_and_returns_to_the_list(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);
        $job = $this->jobOf($file, $this->tr);

        $this->actingAs($this->admin)->post(route('workfile.status'), [
            'statuses' => [$job->id => 'under_verification'],
            'remarks' => [$job->id => 'Filed at the RTO counter'],
            'return_to' => route('workfile.inhouse'),
        ])->assertRedirect(route('workfile.inhouse'));

        $this->assertSame('under_verification', $job->fresh()->status);

        // Still ours and still unfinished, so still on the list, as it now stands.
        $this->assertSame('Under Verification', $this->rowsFor($file)[0]['status']);
    }

    /**
     * Nine columns on show turn the grid wide, and it scrolls sideways with
     * Update — the one thing to do here — past the edge of a laptop screen.
     */
    public function test_the_list_stays_narrow_enough_to_show_update(): void
    {
        $shown = collect($this->props()['columns'])
            ->reject(fn ($c) => ($c['hidden'] ?? false) || ($c['exportOnly'] ?? false));

        $this->assertLessThan(9, $shown->count(), 'the grid goes wide at nine columns on show');
        $this->assertSame('update', $shown->last()['key']);
    }

    public function test_the_day_it_was_kept_is_shown_under_the_work_and_exported(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);

        $columns = collect($this->props()['columns'])->keyBy('key');

        $this->assertSame('kept_text', $columns['work']['sub']);
        $this->assertTrue($columns['kept_on']['exportOnly']);
        $this->assertSame('kept in-house '.now()->format('d-m-Y'), $this->rowsFor($file)[0]['kept_text']);
    }

    public function test_the_update_column_is_kept_out_of_the_exports(): void
    {
        $update = collect($this->props()['columns'])->firstWhere('key', 'update');

        $this->assertSame('action', $update['type']);
        $this->assertFalse($update['exportable']);
        $this->assertFalse($update['searchable']);
    }

    // ------------------------------------------------------------ dashboard

    public function test_the_dashboard_counts_it_and_links_here(): void
    {
        $file = $this->file([$this->tr]);
        $this->keep($file, $this->tr);

        $tile = collect($this->actingAs($this->admin)
            ->getJson(url('admin/dashboard'))->assertOk()->json('props.tiles'))
            ->firstWhere('label', 'In-house Work');

        $this->assertNotNull($tile, 'no In-house Work tile');
        $this->assertSame(WorkFileModel::inHouseWork()->count(), $tile['value']);
        $this->assertSame(route('workfile.inhouse'), $tile['href']);
        $this->assertStringContainsString('oldest waiting', $tile['note']);
    }

    // ---------------------------------------------------------------- the page

    public function test_it_is_on_the_menu_beside_give_to_vendor(): void
    {
        $body = $this->actingAs($this->admin)->get(route('workfile.inhouse'))->assertOk()->getContent();

        $give = strpos($body, '<span>Give to Vendor</span>');
        $here = strpos($body, '<span>In-house Work</span>');

        $this->assertNotFalse($here, 'In-house Work is not on the menu');
        $this->assertGreaterThan($give, $here);
    }

    public function test_it_says_how_work_gets_onto_it_when_there_is_none(): void
    {
        $this->assertStringContainsString('Keep in-house', $this->props()['emptyText']);
    }

    public function test_nobody_signed_out_can_open_it(): void
    {
        $this->get(route('workfile.inhouse'))->assertRedirect();
    }
}

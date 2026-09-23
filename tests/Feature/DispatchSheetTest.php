<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkFileController;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The paper a vendor signs for what they were handed.
 *
 * One vendor, one day, because that is what a hand-over is and how the works
 * record it. Read for: it lists that vendor's works of that day and nobody
 * else's; a work struck off is not on it; it carries no money and no customer's
 * name — a sheet travels, and neither is a vendor's business; and it ends with
 * a line to sign.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class DispatchSheetTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Sheet Admin';
        $this->admin->email = 'sheet-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Rakesh Ji Madhubani');
        $this->vendor = $this->party('vendor', 'Parwez Ji Muzaffarpur');

        $this->tr = $this->type('TR');
        $this->hpa = $this->type('HPA');
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name.' '.uniqid();
        $party->mobile = '93700'.random_int(10000, 99999);
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

    /** A folder of two works for this customer, in the office. */
    private function file(float $charge = 5000, ?WorkTypeModel $second = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-DS-'.uniqid();
        $file->received_date = '2026-09-15';
        $file->registration_no = 'BR05DS'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = $charge;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        foreach ([$this->tr, $second ?? $this->hpa] as $type) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charge / 2;
            $item->status = WorkFileModel::IN_OFFICE;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    /** Give to Vendor, as the screen posts it. */
    private function give(WorkFileModel $file, string $date, ?PartyModel $vendor = null, array $jobs = []): void
    {
        Auth::loginUsingId($this->admin->id);

        app(WorkFileController::class)->assign(Request::create('/admin/file/assign', 'POST', array_filter([
            'vendor_id' => ($vendor ?? $this->vendor)->id,
            'vendor_date' => $date,
            'files' => [$file->id],
            'jobs' => $jobs,
        ])));
    }

    /** The file edit screen's save, with whatever is being corrected. */
    private function save(WorkFileModel $file, array $changes = [])
    {
        return $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), $changes + [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'registration_no' => $file->registration_no,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'vendor_id' => $file->vendor_id,
            'vendor_amount' => $file->vendor_amount,
            'vendor_date' => $file->vendor_date ? date('Y-m-d', strtotime($file->vendor_date)) : null,
            'status' => $file->status,
            'remarks' => $file->remarks,
            'drawn' => $file->editFingerprint(),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    private function sheet(?string $date = '2026-09-20', ?PartyModel $vendor = null)
    {
        return $this->actingAs($this->admin)->get(route('workfile.dispatchsheet', array_filter([
            'vendor' => ($vendor ?? $this->vendor)->id,
            'date' => $date,
        ])));
    }

    // ------------------------------------------------------------- the sheet

    public function test_it_lists_that_vendors_works_of_that_day(): void
    {
        $given = $this->file();
        $this->give($given, '2026-09-20');

        $said = $this->sheet()->assertOk()->getContent();

        $this->assertStringContainsString($given->file_no, $said);
        $this->assertStringContainsString($given->registration_no, $said);
        $this->assertStringContainsString($this->tr->name, $said);
        $this->assertStringContainsString($this->hpa->name, $said);
        $this->assertStringContainsString('Received the above papers', $said);
        $this->assertStringContainsString('Signature', $said);
    }

    public function test_it_leaves_out_another_day_another_vendor_and_a_work_struck_off(): void
    {
        $today = $this->file();
        $yesterday = $this->file();
        $theirs = $this->file();

        $this->give($today, '2026-09-20');
        $this->give($yesterday, '2026-09-19');
        $this->give($theirs, '2026-09-20', $this->party('vendor', 'Somebody Else'));

        /*
         * And a folder whose second work was struck off after it went. That
         * work has a type of its own here, so the page can be read for it: the
         * folders above carry the ordinary two.
         */
        $struck = $this->type('RC');
        $cancelled = $this->file(5000, $struck);
        $this->give($cancelled, '2026-09-20');
        $cancelled->items()->where('work_type_id', $struck->id)->update(['status' => WorkFileModel::CANCELLED]);

        $said = $this->sheet()->assertOk()->getContent();

        $this->assertStringContainsString($today->file_no, $said);
        $this->assertStringNotContainsString($yesterday->file_no, $said);
        $this->assertStringNotContainsString($theirs->file_no, $said);

        // The folder is still on it, for the work that stands — and nobody
        // signs for work that is not being done.
        $this->assertStringContainsString($cancelled->file_no, $said);
        $this->assertStringNotContainsString($struck->name, $said);
    }

    /** A sheet travels: no money on it, and no customer's name. */
    public function test_it_carries_no_money_and_no_customer(): void
    {
        $file = $this->file(7500);
        $file->vendor_amount = 4000;
        $file->save();
        $this->give($file, '2026-09-20');

        $said = $this->sheet()->assertOk()->getContent();

        $this->assertStringNotContainsString($this->customer->name, $said);
        $this->assertStringNotContainsString('7,500', $said);
        $this->assertStringNotContainsString('7500', $said);
        $this->assertStringNotContainsString('4,000', $said);
        $this->assertStringNotContainsString('4000', $said);
    }

    public function test_it_offers_the_days_that_vendor_was_given_something(): void
    {
        $file = $this->file();
        $this->give($file, '2026-09-20');

        $said = $this->actingAs($this->admin)
            ->get(route('workfile.dispatchsheet', ['vendor' => $this->vendor->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('20-09-2026', $said);
    }

    public function test_a_day_with_nothing_on_it_says_so(): void
    {
        $said = $this->sheet('2026-01-01')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing was given to', $said);
        $this->assertStringNotContainsString('Received the above papers', $said);
    }

    /** Handed over just now: the sheet is offered where the office already is. */
    public function test_giving_papers_out_offers_the_sheet(): void
    {
        $file = $this->file();

        $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->vendor->id,
            'vendor_date' => '2026-09-20',
            'files' => [$file->id],
        ])->assertSessionHas('sheet');

        $offered = session('sheet');

        $this->assertSame(
            route('workfile.dispatchsheet', ['vendor' => $this->vendor->id, 'date' => '2026-09-20']),
            $offered['url']
        );
        $this->assertStringContainsString($this->vendor->name, $offered['label']);
    }

    /** Printed, it is the sheet and nothing else: no menu, no pickers, no buttons. */
    public function test_the_printed_sheet_leaves_the_screen_behind(): void
    {
        $file = $this->file();
        $this->give($file, '2026-09-20');

        $said = $this->sheet()->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/@media print\s*\{.*body:has\(\.sheet\) \.sidebar,.*display: none !important;/s',
            $said
        );

        /*
         * And every rule says which page it is for. A screen's styles stay in
         * the document once it has been visited, so a rule written plainly
         * would hide the menu from every other screen's printout too.
         */
        $print = preg_replace('/^.*?@media print\s*\{/s', '', $said);
        $print = substr($print, 0, (int) strpos($print, '.sheet {'));

        foreach (['.sidebar', '.header', '.footer', '.pagetitle', '.back-to-top'] as $ofTheScreen) {
            $this->assertStringNotContainsString("\n            $ofTheScreen", $print, "$ofTheScreen is hidden on every screen's printout");
        }
    }

    /** A folder holding no works of its own is handed over whole — and is on the sheet. */
    public function test_a_folder_with_no_works_is_on_the_sheet(): void
    {
        $file = $this->file();
        $file->items()->delete();
        $file->refresh();

        $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->vendor->id,
            'vendor_date' => '2026-09-20',
            'files' => [$file->id],
        ])->assertSessionHas('sheet');

        $said = $this->sheet()->assertOk()->getContent();

        $this->assertStringContainsString($file->file_no, $said);
        $this->assertStringContainsString($file->registration_no, $said);
        $this->assertStringNotContainsString('Nothing was given to', $said);

        // And its day is offered for reprinting, counted once.
        $days = $this->actingAs($this->admin)
            ->get(route('workfile.dispatchsheet', ['vendor' => $this->vendor->id]))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($days, '20-09-2026'));
    }

    /** An old day is still offered, however many days came after it. */
    public function test_a_day_long_past_is_still_offered(): void
    {
        $longAgo = $this->file();
        $this->give($longAgo, now()->subYear()->toDateString());

        // And a run of days since, any window over which would hide it.
        for ($back = 0; $back < 5; $back++) {
            $this->give($this->file(), now()->subDays($back)->toDateString());
        }

        $said = $this->actingAs($this->admin)
            ->get(route('workfile.dispatchsheet', ['vendor' => $this->vendor->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString(now()->subYear()->format('d-m-Y'), $said);
        $this->assertStringContainsString(now()->format('d-m-Y'), $said);
    }

    /** A vendor the office has stopped working with still has their sheets. */
    public function test_a_vendor_switched_off_can_still_be_chosen(): void
    {
        $file = $this->file();
        $this->give($file, '2026-09-20');

        $this->vendor->is_active = 0;
        $this->vendor->save();

        $said = $this->actingAs($this->admin)->get(route('workfile.dispatchsheet'))->assertOk()->getContent();

        // The option itself: the name alone could be on the page for other reasons.
        $this->assertStringContainsString('<option value="'.$this->vendor->id.'"', $said);
        $this->assertStringContainsString($this->vendor->name, $said);
    }

    /**
     * A folder can go out over two days. Saving the file for any other reason
     * must not move what went out when, or one sheet lists papers the vendor
     * never took that day and the other says nothing went at all.
     */
    public function test_saving_the_file_does_not_move_what_went_out_when(): void
    {
        $file = $this->file();
        $works = $file->items()->orderBy('id')->get();

        $this->give($file, '2026-09-20', null, [$works[0]->id]);
        $this->give($file, '2026-09-21', null, [$works[1]->id]);

        $this->assertStringContainsString($this->tr->name, $this->sheet('2026-09-20')->getContent());
        $this->assertStringContainsString($this->hpa->name, $this->sheet('2026-09-21')->getContent());

        $this->assertSame(['2026-09-20', '2026-09-21'], $file->items()->orderBy('id')->pluck('vendor_date')->map(fn ($d) => substr((string) $d, 0, 10))->all(), 'the premise: two days');
        $this->assertSame('2026-09-20', substr((string) $file->fresh()->vendor_date, 0, 10), 'the premise: the folder carries the earlier day');

        // An ordinary save: a remark, nothing to do with the vendor.
        $file->refresh();
        $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'work_type_id' => $file->work_type_id,
            'registration_no' => $file->registration_no,
            'customer_id' => $file->customer_id,
            'customer_amount' => $file->customer_amount,
            'vendor_id' => $file->vendor_id,
            'vendor_amount' => $file->vendor_amount,
            'vendor_date' => $file->vendor_date ? date('Y-m-d', strtotime($file->vendor_date)) : null,
            'status' => $file->status,
            'remarks' => 'Nothing to do with the vendor',
            'drawn' => $file->editFingerprint(),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        // The premise of the rest: the file really was saved.
        $this->assertSame('Nothing to do with the vendor', $file->fresh()->remarks);

        // The works themselves: the sheet is read from these.
        $dates = $file->items()->orderBy('id')->pluck('vendor_date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame(['2026-09-20', '2026-09-21'], $dates, 'the day a work went out was moved by an unrelated save');

        $twentieth = $this->sheet('2026-09-20')->getContent();
        $twentyFirst = $this->sheet('2026-09-21')->getContent();

        $this->assertStringContainsString($this->tr->name, $twentieth);
        $this->assertStringNotContainsString($this->hpa->name, $twentieth, 'the sheet lists papers taken another day');
        $this->assertStringContainsString($this->hpa->name, $twentyFirst, 'the day the papers went out was moved');

        // And both days are still offered.
        $days = $this->actingAs($this->admin)
            ->get(route('workfile.dispatchsheet', ['vendor' => $this->vendor->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('20-09-2026', $days);
        $this->assertStringContainsString('21-09-2026', $days);
    }

    /** The day corrected on the file: the works that carried it move, and no others. */
    public function test_correcting_the_day_moves_only_the_works_that_carried_it(): void
    {
        $file = $this->file();
        $works = $file->items()->orderBy('id')->get();

        $this->give($file, '2026-09-20', null, [$works[0]->id]);
        $this->give($file, '2026-09-21', null, [$works[1]->id]);

        // The 20th was mistyped: it went out on the 22nd.
        $this->save($file->fresh(), ['vendor_date' => '2026-09-22']);

        $dates = $file->items()->orderBy('id')->pluck('vendor_date')->map(fn ($d) => substr((string) $d, 0, 10))->all();

        $this->assertSame(['2026-09-22', '2026-09-21'], $dates);
        $this->assertStringContainsString($this->tr->name, $this->sheet('2026-09-22')->getContent());
        $this->assertStringContainsString($this->hpa->name, $this->sheet('2026-09-21')->getContent());
    }

    /** Work still on the desk is not stamped as handed over by a save. */
    public function test_a_save_does_not_hand_over_work_still_in_the_office(): void
    {
        $file = $this->file();
        $works = $file->items()->orderBy('id')->get();

        $this->give($file, '2026-09-20', null, [$works[0]->id]);

        $this->save($file->fresh(), ['remarks' => 'Nothing to do with the vendor']);

        $this->assertNull($file->items()->whereKey($works[1]->id)->value('vendor_date'), 'work still here was dated as given out');
        $this->assertStringNotContainsString($this->hpa->name, $this->sheet('2026-09-20')->getContent());
    }

    public function test_nobody_signed_out_can_read_it(): void
    {
        $file = $this->file();
        $this->give($file, '2026-09-20');

        auth()->logout();

        $this->get(route('workfile.dispatchsheet', ['vendor' => $this->vendor->id, 'date' => '2026-09-20']))
            ->assertRedirect(url('/admin'));
    }
}

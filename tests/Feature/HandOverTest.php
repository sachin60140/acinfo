<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkFileStatusLogModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Approved papers going back to the customer.
 *
 * The one thing this must never do is move money. Return to Customer is a
 * refund; this is finished work going home, and the customer still owes for
 * it in full. So the tests that matter most here are the ones that compare the
 * ledger, the balances and the reports before and after, and find nothing
 * changed.
 *
 * The other thing is who reads what. The customer sees that their papers came
 * back, and when. They do not see who collected them, and they do not see a
 * handover the office recorded by mistake and took back.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class HandOverTest extends TestCase
{
    use DatabaseTransactions;

    private PartyModel $vendor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = $this->party('vendor');

        $this->admin = new User;
        $this->admin->name = 'Handover Admin';
        $this->admin->email = 'handover-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for handover';
        $party->mobile = '93100'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * A priced file with a vendor, its works at the given statuses, and its
     * entries on both ledgers — so there is money in place to not move.
     */
    private function file(PartyModel $customer, array $works = [WorkFileModel::APPROVED], ?string $status = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-HO-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01HO'.random_int(1000, 9999);
        $file->work_type_id = WorkTypeModel::query()->value('id');
        $file->customer_id = $customer->id;
        $file->customer_amount = 5000;
        $file->vendor_id = $this->vendor->id;
        $file->vendor_amount = 3000;
        $file->vendor_date = '2026-09-02';
        $file->status = $status ?? $works[0];
        $file->save();

        foreach ($works as $work) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $file->work_type_id;
            $item->customer_amount = 5000 / count($works);
            $item->vendor_amount = 3000 / count($works);
            $item->status = $work;
            $item->approved_on = $work === WorkFileModel::APPROVED ? '2026-09-10' : null;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    private function handOver(array $files, array $extra = [])
    {
        // array_merge and not +, which keeps the left-hand key and would
        // quietly ignore a date passed in to be refused.
        return $this->actingAs($this->admin)->post(route('workfile.handover'), array_merge([
            'handed_over_on' => now()->toDateString(),
            'files' => array_map(fn (WorkFileModel $file) => $file->id, $files),
        ], $extra));
    }

    private function waiting(): array
    {
        return collect($this->actingAs($this->admin)->getJson(route('workfile.handover'))->assertOk()->json('props.files'))
            ->pluck('id')->all();
    }

    // ------------------------------------------------------------ what is listed

    public function test_the_screen_lists_approved_files_whose_papers_are_still_here(): void
    {
        $customer = $this->party('customer');

        $approved = $this->file($customer);
        $partly = $this->file($customer, [WorkFileModel::APPROVED, WorkFileModel::DISPATCHED], WorkFileModel::PARTLY_APPROVED);
        $inHand = $this->file($customer, [WorkFileModel::IN_OFFICE]);
        $returned = $this->file($customer, [WorkFileModel::RETURNED]);
        $cancelled = $this->file($customer, [WorkFileModel::CANCELLED]);
        $gone = $this->file($customer);
        $gone->handOver('2026-09-12');

        $waiting = $this->waiting();

        $this->assertContains($approved->id, $waiting);

        // Partly approved stays: the work still pending is usually still
        // working from these papers.
        foreach ([$partly, $inHand, $returned, $cancelled, $gone] as $not) {
            $this->assertNotContains($not->id, $waiting, $not->status.' was offered');
        }
    }

    // ---------------------------------------------------------------- handing over

    public function test_handing_over_records_when_who_and_who_collected(): void
    {
        $file = $this->file($this->party('customer'));

        $this->handOver([$file], ['collected_by' => '  Rakesh, driver  ', 'remark' => 'RC and NOC'])
            ->assertRedirect(route('workfile.handover'))
            ->assertSessionHas('success');

        $file->refresh();

        $this->assertSame(now()->toDateString(), substr((string) $file->handed_over_on, 0, 10));
        $this->assertSame($this->admin->id, (int) $file->handed_over_by);
        $this->assertSame('Rakesh, driver', $file->collected_by);

        // Not a status. The work is approved and stays approved.
        $this->assertSame(WorkFileModel::APPROVED, $file->status);
    }

    public function test_who_collected_them_is_optional(): void
    {
        $file = $this->file($this->party('customer'));

        $this->handOver([$file], ['collected_by' => '   '])->assertSessionHasNoErrors();

        $this->assertNull($file->fresh()->collected_by, 'a blank box is not a name');
        $this->assertTrue($file->fresh()->isHandedOver());
    }

    /**
     * The point of the whole screen.
     *
     * Every figure a file puts anywhere — its ledger lines on both statements,
     * both parties' balances, and what the reports say it earned — is read
     * before and after, and must not have moved.
     */
    public function test_no_money_moves(): void
    {
        $customer = $this->party('customer');
        $file = $this->file($customer);

        $snapshot = function () use ($file, $customer) {
            return [
                'entries' => PartyLedgerModel::where('work_file_id', $file->id)
                    ->orderBy('id')
                    ->get(['id', 'party_id', 'entry_type', 'amount', 'file_role'])
                    ->toArray(),
                'customer' => PartyLedgerModel::currentBalance($customer->id),
                'vendor' => PartyLedgerModel::currentBalance($this->vendor->id),
                'row' => WorkFileModel::rowTotals(WorkFileModel::listing()->firstWhere('id', $file->id)),
                'by customer' => (array) WorkFileModel::profitBy('customer')->firstWhere('group_key', $customer->id),
            ];
        };

        $before = $snapshot();

        $this->assertNotEmpty($before['entries'], 'the fixture has no money in place to not move');

        $this->handOver([$file], ['remark' => 'RC handed over'])->assertSessionHasNoErrors();

        $this->assertEquals($before, $snapshot());
    }

    /**
     * And the work-type report — the one that leaves returned files out — still
     * counts it. A handover recorded as a ₹0 return would have dropped it.
     */
    public function test_the_work_type_report_still_counts_it(): void
    {
        $file = $this->file($this->party('customer'));

        $before = WorkFileModel::profitBy('work_type')->firstWhere('group_key', $file->work_type_id);

        $this->handOver([$file]);

        $after = WorkFileModel::profitBy('work_type')->firstWhere('group_key', $file->work_type_id);

        $this->assertEquals($before->billed, $after->billed);
        $this->assertEquals($before->files, $after->files);
    }

    // ------------------------------------------------------------------ refusals

    /**
     * The page may have been open since before a file was handed over by
     * someone else, or since before one of its works stopped being approved.
     * The post is what records it, so the post asks again.
     */
    public function test_a_stale_page_hands_over_only_what_is_still_waiting(): void
    {
        $customer = $this->party('customer');

        $fine = $this->file($customer);

        $alreadyGone = $this->file($customer);
        $alreadyGone->handOver('2026-09-11', 'Earlier');

        $noLonger = $this->file($customer);
        $noLonger->status = WorkFileModel::PARTLY_APPROVED;
        $noLonger->save();

        $this->handOver([$fine, $alreadyGone, $noLonger], ['collected_by' => 'Today'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($fine->fresh()->isHandedOver());

        // The earlier handover is left exactly as it was.
        $this->assertSame('2026-09-11', substr((string) $alreadyGone->fresh()->handed_over_on, 0, 10));
        $this->assertSame('Earlier', $alreadyGone->fresh()->collected_by);

        $this->assertFalse($noLonger->fresh()->isHandedOver());
    }

    public function test_a_batch_that_is_all_stale_says_so_and_records_nothing(): void
    {
        $file = $this->file($this->party('customer'));
        $file->handOver('2026-09-11');

        $logged = WorkFileStatusLogModel::where('work_file_id', $file->id)->count();

        $this->handOver([$file])->assertSessionHas('error');

        $this->assertSame($logged, WorkFileStatusLogModel::where('work_file_id', $file->id)->count());
    }

    public function test_a_date_in_the_future_is_refused(): void
    {
        $file = $this->file($this->party('customer'));

        $this->handOver([$file], ['handed_over_on' => now()->addDays(2)->toDateString()])
            ->assertSessionHasErrors('handed_over_on');

        $this->assertFalse($file->fresh()->isHandedOver());
    }

    public function test_at_least_one_file_is_needed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('workfile.handover'), ['handed_over_on' => now()->toDateString()])
            ->assertSessionHasErrors('files');
    }

    /** It stays a finished file: the refund screen still will not have it. */
    public function test_a_handed_over_file_still_cannot_be_refunded(): void
    {
        $file = $this->file($this->party('customer'));
        $this->handOver([$file]);

        $returnable = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.customerreturn'))->json('props.files'))->pluck('id');

        $this->assertNotContains($file->id, $returnable);

        $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => now()->toDateString(),
            'files' => [$file->id],
            'remark' => 'Trying anyway',
        ]);

        $this->assertSame(WorkFileModel::APPROVED, $file->fresh()->status);
    }

    public function test_nobody_signed_out_can_reach_it(): void
    {
        $file = $this->file($this->party('customer'));

        $this->get(route('workfile.handover'))->assertRedirect(url('/admin'));

        $this->post(route('workfile.handover'), [
            'handed_over_on' => now()->toDateString(),
            'files' => [$file->id],
        ])->assertRedirect(url('/admin'));

        $this->post(route('workfile.handover.undo', $file->id), ['undo_remark' => 'x'])
            ->assertRedirect(url('/admin'));

        $this->assertFalse($file->fresh()->isHandedOver());
    }

    // ------------------------------------------------------------- the history

    public function test_the_office_history_shows_the_handover_and_who_recorded_it(): void
    {
        $file = $this->file($this->party('customer'));
        $this->handOver([$file], ['collected_by' => 'Rakesh', 'remark' => 'RC and NOC']);

        $props = $this->actingAs($this->admin)->getJson(route('workfile.edit', $file->id))->json('props');

        $entry = collect($props['timeline'])->firstWhere('kind', 'handover');

        $this->assertNotNull($entry, 'the handover is not on the history');
        $this->assertSame('RC and NOC', $entry['remark']);
        $this->assertSame($this->admin->name, $entry['user']);

        $this->assertSame(date('d-m-Y'), $props['handover']['on']);
        $this->assertSame('Rakesh', $props['handover']['collectedBy']);
        $this->assertSame($this->admin->name, $props['handover']['by']);
        $this->assertSame(route('workfile.handover.undo', $file->id), $props['handover']['undoUrl']);

        // Once it has happened there is nothing further to offer.
        $this->assertNull($props['handoverUrl']);
    }

    public function test_the_edit_screen_offers_the_way_in_only_when_it_applies(): void
    {
        $customer = $this->party('customer');

        $ready = $this->file($customer);
        $notYet = $this->file($customer, [WorkFileModel::DISPATCHED]);

        $props = fn (WorkFileModel $file) => $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->json('props');

        $this->assertSame(
            route('workfile.handover', ['q' => $ready->file_no]),
            $props($ready)['handoverUrl'],
            'the screen opens narrowed to this file'
        );
        $this->assertNull($props($ready)['handover']);

        $this->assertNull($props($notYet)['handoverUrl']);
    }

    /**
     * The customer is told their papers came back, and when. Who took them is
     * the office's note — for the day someone says they never arrived — and is
     * not on the customer's page in any form.
     */
    public function test_the_customer_sees_the_handover_but_not_who_collected(): void
    {
        $customer = $this->party('customer');
        $file = $this->file($customer);
        $this->handOver([$file], ['collected_by' => 'Rakesh Kumar the driver', 'remark' => 'RC and NOC']);

        $session = ['customer_id' => $customer->id];

        $page = $this->withSession($session)->getJson(route('customer.file', $file->id))->assertOk()->json('page');

        $this->assertSame(date('d-m-Y'), $page['handedOverOn']);

        $entry = collect($page['timeline'])->firstWhere('to', 'Papers handed over to you');

        $this->assertNotNull($entry, 'the handover is not on the customer\'s history');
        $this->assertSame('RC and NOC', $entry['remark']);

        $body = $this->withSession($session)->get(route('customer.file', $file->id))->assertOk()->getContent();

        $this->assertStringContainsString('Papers Handed Over', $body);
        $this->assertStringNotContainsString('Rakesh Kumar the driver', $body);
    }

    // ------------------------------------------------------------ taking it back

    public function test_taking_a_handover_back_needs_a_reason(): void
    {
        $file = $this->file($this->party('customer'));
        $this->handOver([$file]);

        $this->actingAs($this->admin)
            ->post(route('workfile.handover.undo', $file->id), ['undo_remark' => ''])
            ->assertSessionHasErrors('undo_remark');

        $this->assertTrue($file->fresh()->isHandedOver());
    }

    public function test_taking_it_back_puts_the_file_on_the_list_again(): void
    {
        $file = $this->file($this->party('customer'));
        $this->handOver([$file], ['collected_by' => 'Rakesh']);

        $this->actingAs($this->admin)
            ->post(route('workfile.handover.undo', $file->id), ['undo_remark' => 'Wrong file ticked'])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHas('success');

        $file->refresh();

        $this->assertFalse($file->isHandedOver());
        $this->assertNull($file->collected_by);
        $this->assertNull($file->handed_over_by);

        $this->assertContains($file->id, $this->waiting());

        // Kept on the office's history, with its reason.
        $timeline = $this->actingAs($this->admin)->getJson(route('workfile.edit', $file->id))->json('props.timeline');
        $undone = collect($timeline)->firstWhere('kind', 'handover_undone');

        $this->assertNotNull($undone);
        $this->assertSame('Wrong file ticked', $undone['remark']);
        $this->assertNotNull(collect($timeline)->firstWhere('kind', 'handover'), 'and the handover it undid is still there');
    }

    /**
     * A handover recorded by mistake never happened, as far as the customer is
     * concerned: neither it nor the reason for taking it back reaches them, on
     * the file's history or as the latest news on their list.
     */
    public function test_the_customer_never_sees_a_handover_that_was_taken_back(): void
    {
        $customer = $this->party('customer');
        $file = $this->file($customer);

        $this->handOver([$file], ['remark' => 'First handover']);
        $this->actingAs($this->admin)->post(route('workfile.handover.undo', $file->id), ['undo_remark' => 'Wrong file ticked']);

        $timeline = WorkFileModel::customerTimeline($file->id);

        $this->assertNull(collect($timeline)->firstWhere('to', 'Papers handed over to you'));
        $this->assertNotContains('Wrong file ticked', array_column($timeline, 'remark'));
        $this->assertNotContains('First handover', array_column($timeline, 'remark'));

        $latest = WorkFileModel::latestCustomerUpdates([$file->id])[$file->id] ?? [];

        $this->assertNotSame('Wrong file ticked', $latest['remark'] ?? null);
        $this->assertNotSame('First handover', $latest['remark'] ?? null);

        // Handed over again for real: that one stands, once.
        $this->handOver([$file], ['remark' => 'Second handover']);

        $timeline = WorkFileModel::customerTimeline($file->id);
        $handovers = collect($timeline)->where('to', 'Papers handed over to you');

        $this->assertCount(1, $handovers);
        $this->assertSame('Second handover', $handovers->first()['remark']);
        $this->assertSame('Second handover', WorkFileModel::latestCustomerUpdates([$file->id])[$file->id]['remark']);
    }

    public function test_taking_back_a_handover_that_never_happened_changes_nothing(): void
    {
        $file = $this->file($this->party('customer'));

        $logged = WorkFileStatusLogModel::where('work_file_id', $file->id)->count();

        $this->actingAs($this->admin)
            ->post(route('workfile.handover.undo', $file->id), ['undo_remark' => 'Just in case'])
            ->assertSessionHas('error');

        $this->assertSame($logged, WorkFileStatusLogModel::where('work_file_id', $file->id)->count());
    }

    // -------------------------------------------------------------- the lists

    public function test_the_files_list_says_so_and_can_show_only_the_waiting_ones(): void
    {
        $customer = $this->party('customer');

        $waiting = $this->file($customer);
        $gone = $this->file($customer);
        $gone->handOver('2026-09-12');

        $all = collect($this->actingAs($this->admin)->getJson(route('workfile.index'))->json('props.rows'))->keyBy('id');

        $this->assertStringContainsString('Papers handed over 12-09-2026', (string) $all[$gone->id]['works_note']);
        $this->assertSame('12-09-2026', $all[$gone->id]['handed_over']);
        $this->assertNull($all[$waiting->id]['handed_over']);

        $filtered = collect($this->actingAs($this->admin)
            ->getJson(route('workfile.index', ['status' => WorkFileModel::AWAITING_HANDOVER]))
            ->assertOk()->json('props.rows'))->pluck('id');

        $this->assertContains($waiting->id, $filtered);
        $this->assertNotContains($gone->id, $filtered);

        // Nothing that is not approved, whatever else it is.
        $statuses = WorkFileModel::whereIn('id', $filtered)->pluck('status')->unique()->values()->all();
        $this->assertSame([WorkFileModel::APPROVED], $statuses);
    }

    /**
     * Drawn where every file is finished and the handover is the question left;
     * exported, but not drawn, on the full list.
     */
    public function test_the_approved_screen_draws_the_handover_and_the_full_list_exports_it(): void
    {
        $column = fn (string $url) => collect($this->actingAs($this->admin)->getJson($url)->json('props.columns'))
            ->firstWhere('key', 'handed_over');

        $this->assertFalse((bool) ($column(route('workfile.approved'))['exportOnly'] ?? false));
        $this->assertTrue((bool) $column(route('workfile.index'))['exportOnly']);
    }
}

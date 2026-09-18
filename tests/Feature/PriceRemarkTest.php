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
 * Moving a price that was already agreed.
 *
 * Changing one rewrites this file's entries on a statement somebody has already
 * seen, and "why is this file 6,000 now" gets asked weeks later, by which time
 * nobody remembers. So it says why, once, on the file's own history.
 *
 * The reason is the office's. Why a rate moved is between the office and its
 * vendor — or its own margin — so it is written under an event no customer page
 * reads, and a test opens every customer page looking for it.
 *
 * Pricing a file for the first time asks nothing: a blank or a nought is how
 * this application says "not agreed yet", and a box that fired on those would
 * be clicked past without being read.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PriceRemarkTest extends TestCase
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
        $this->admin->name = 'Price Admin';
        $this->admin->email = 'price-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->vendor = $this->party('vendor');
        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' for pricing';
        $party->mobile = '93700'.random_int(10000, 99999);
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

    /** @param  array<int, array{0: WorkTypeModel, 1: float, 2: float|null}>  $works */
    private function file(array $works): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-PR-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01PR'.random_int(1000, 9999);
        $file->work_type_id = $works[0][0]->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = array_sum(array_column($works, 1));
        $file->vendor_id = $this->vendor->id;
        $file->vendor_date = '2026-09-02';
        $file->vendor_amount = array_sum(array_map(fn ($work) => $work[2] ?? 0, $works)) ?: null;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        foreach ($works as [$type, $charged, $cost]) {
            $item = new WorkFileItemModel;
            $item->work_file_id = $file->id;
            $item->work_type_id = $type->id;
            $item->customer_amount = $charged;
            $item->vendor_amount = $cost;
            $item->status = WorkFileModel::DISPATCHED;
            $item->save();
        }

        $file->syncLedger();

        return $file->fresh();
    }

    /** Save the edit screen, with whatever is being changed. */
    private function save(WorkFileModel $file, array $changes = [])
    {
        $items = $file->items()->get();

        $base = [
            'file_no' => $file->file_no,
            'received_date' => $file->received_date,
            'status' => $file->status,
            'work_type_id' => $file->work_type_id,
            'customer_id' => $file->customer_id,
            'customer_amount' => (string) $file->customer_amount,
            'vendor_id' => $file->vendor_id,
            'vendor_amount' => $file->vendor_amount === null ? '' : (string) $file->vendor_amount,
        ];

        if ($items->count() > 1) {
            $base['items'] = $items->mapWithKeys(fn ($item) => [$item->id => [
                'work_type_id' => $item->work_type_id,
                'customer_amount' => (string) $item->customer_amount,
                'vendor_amount' => $item->vendor_amount === null ? '' : (string) $item->vendor_amount,
            ]])->all();
        }

        // From the edit screen, so a refusal goes back to it the way it does in a browser.
        return $this->from(route('workfile.edit', $file->id))
            ->actingAs($this->admin)
            ->post(route('workfile.edit', $file->id), array_replace_recursive($base, $changes));
    }

    private function itemId(WorkFileModel $file, WorkTypeModel $type): int
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->where('work_type_id', $type->id)->value('id');
    }

    private function priceEntry(WorkFileModel $file)
    {
        return $file->statusLog()->where('event', WorkFileModel::PRICE)->first();
    }

    // --------------------------------------------------------------- it is asked

    public function test_changing_a_charge_on_a_folder_needs_a_reason(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000], [$this->hpa, 2000, 1000]]);
        $id = $this->itemId($file, $this->tr);

        $this->save($file, ['items' => [$id => ['customer_amount' => '6000']], 'customer_amount' => '8000'])
            ->assertSessionHasErrors('price_remark');

        $this->assertEquals(5000, WorkFileItemModel::find($id)->customer_amount, 'it was changed anyway');
        $this->assertNull($this->priceEntry($file));
    }

    public function test_changing_a_vendor_rate_needs_one_too(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000], [$this->hpa, 2000, 1000]]);
        $id = $this->itemId($file, $this->tr);

        $this->save($file, ['items' => [$id => ['vendor_amount' => '3500']]])
            ->assertSessionHasErrors('price_remark');

        $this->assertEquals(3000, WorkFileItemModel::find($id)->vendor_amount);
    }

    /** A file of one work is priced in the boxes above the table. Same question. */
    public function test_a_single_work_file_is_asked_in_the_boxes_above(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000]]);

        $this->save($file, ['customer_amount' => '7000'])->assertSessionHasErrors('price_remark');
        $this->assertEquals(5000, $file->fresh()->customer_amount);

        $this->save($file, ['vendor_amount' => '2500'])->assertSessionHasErrors('price_remark');
        $this->assertEquals(3000, $file->fresh()->vendor_amount);
    }

    public function test_clearing_a_rate_is_changing_it(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000]]);

        $this->save($file, ['vendor_amount' => ''])->assertSessionHasErrors('price_remark');
    }

    /**
     * The refusal has to come back to somewhere you can answer it.
     *
     * The screen is filled from what was typed, so by then the price on it and
     * the price it is compared against are the same figure — the box the server
     * is asking for only appears because the refusal says so.
     */
    public function test_the_refusal_comes_back_with_somewhere_to_type(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000]]);

        $props = $this->save($file, ['customer_amount' => '7000'])
            ->assertRedirect(route('workfile.edit', $file->id))
            ->getSession();

        $page = $this->actingAs($this->admin)
            ->withSession(['errors' => $props->get('errors'), '_old_input' => $props->get('_old_input')])
            ->getJson(route('workfile.edit', $file->id));

        $this->assertStringContainsString('Say why the price is changing', $page->json('props.errors.price_remark'));
        // And the figure that was typed is still on the screen to correct or keep.
        $this->assertEquals(7000, $page->json('props.values.customer_amount'));
    }

    // ------------------------------------------------------------ it is not asked

    public function test_pricing_a_file_for_the_first_time_asks_nothing(): void
    {
        $file = $this->file([[$this->tr, 5000, null]]);

        $this->save($file, ['vendor_amount' => '3000'])->assertSessionHasNoErrors();

        $this->assertEquals(3000, $file->fresh()->vendor_amount);
        $this->assertNull($this->priceEntry($file), 'agreeing a rate is not changing one');
    }

    public function test_a_price_of_nothing_is_not_a_price(): void
    {
        $file = $this->file([[$this->tr, 0, null]]);

        $this->save($file, ['customer_amount' => '4000'])->assertSessionHasNoErrors();

        $this->assertEquals(4000, $file->fresh()->customer_amount);
    }

    public function test_changing_anything_else_asks_nothing(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000]]);

        $this->save($file, ['description' => 'Without challan'])->assertSessionHasNoErrors();

        $this->assertSame('Without challan', $file->fresh()->description);
    }

    // ------------------------------------------------------------------ it is kept

    public function test_the_reason_is_kept_with_what_moved(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000], [$this->hpa, 2000, 1000]]);
        $id = $this->itemId($file, $this->tr);

        $this->save($file, [
            'items' => [$id => ['customer_amount' => '6000']],
            'customer_amount' => '8000',
            'price_remark' => 'Customer agreed the higher rate on the phone',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(6000, WorkFileItemModel::find($id)->customer_amount);

        $entry = $this->priceEntry($file);

        $this->assertNotNull($entry);
        $this->assertStringContainsString('5,000.00 → 6,000.00', $entry->remark);
        $this->assertStringContainsString($this->tr->name, $entry->remark);
        $this->assertStringContainsString('Customer agreed the higher rate on the phone', $entry->remark);
        $this->assertSame($this->admin->id, (int) $entry->user_id);
    }

    public function test_the_office_history_marks_it_as_a_price_change(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000]]);

        $this->save($file, ['customer_amount' => '6000', 'price_remark' => 'Agreed on the phone']);

        $entry = collect($this->actingAs($this->admin)->getJson(route('workfile.edit', $file->id))->json('props.timeline'))
            ->firstWhere('kind', 'price');

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Agreed on the phone', $entry['remark']);
    }

    /** The whole point of the event: no customer page reads it. */
    public function test_the_customer_never_reads_it(): void
    {
        $file = $this->file([[$this->tr, 5000, 3000]]);

        $this->save($file, ['customer_amount' => '6000', 'price_remark' => 'Vendor put the rate up, so we did']);

        foreach (WorkFileModel::customerTimeline($file->id) as $entry) {
            $this->assertStringNotContainsString('Vendor put the rate up', (string) $entry['remark']);
        }

        $latest = WorkFileModel::latestCustomerUpdates([$file->id])[$file->id]['remark'] ?? '';
        $this->assertStringNotContainsString('Vendor put the rate up', (string) $latest);

        $session = ['customer_id' => $this->customer->id];

        foreach ([route('customer.file', $file->id), route('customer.files'), route('customer.statement')] as $url) {
            $body = $this->withSession($session)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Vendor put the rate up', $body, "$url shows the office's reason");
        }

        // And what they are charged still reads correctly.
        $this->assertEquals(6000, $file->fresh()->customer_amount);
    }
}

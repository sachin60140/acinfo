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
 * The same papers taken in twice.
 *
 * One envelope entered again is charged again and sent out again, and the
 * office finds out when a vendor asks why they have two files for one job. So
 * the counter is told, at the moment of entering it, that this vehicle already
 * has this work in hand.
 *
 * Work that finished long ago is not that. A second transfer on the same
 * vehicle years later is ordinary, and a warning that fired on it would be
 * ignored by the third time — so only unfinished work counts.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReceiveDuplicateTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    private string $plate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Receive Admin';
        $this->admin->email = 'receive-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' for receiving';
        $this->customer->mobile = '93600'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
        $this->plate = 'BR01RD'.random_int(1000, 9999);
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** A file already on the books for this vehicle. */
    private function existing(WorkTypeModel $type, string $status = WorkFileModel::DISPATCHED, ?string $plate = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RD-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = $plate ?? $this->plate;
        $file->work_type_id = $type->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;
        $file->status = $status;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $type->id;
        $item->customer_amount = 5000;
        $item->status = $status;
        $item->approved_on = $status === WorkFileModel::APPROVED ? '2026-09-10' : null;
        $item->save();

        return $file->fresh();
    }

    /** Take papers in, the way the screen posts them. */
    private function receive(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => $rows,
        ]);
    }

    private function row(WorkTypeModel $type, ?string $plate = null, bool $ok = false): array
    {
        return array_filter([
            'registration_no' => $plate ?? $this->plate,
            'works' => [['work_type_id' => $type->id, 'amount' => '5000']],
            'duplicate_ok' => $ok ? '1' : null,
        ], fn ($value) => $value !== null);
    }

    private function filesFor(string $plate): int
    {
        return WorkFileModel::where('registration_no', WorkFileModel::normaliseRegistration($plate))->count();
    }

    // -------------------------------------------------------------- what counts

    public function test_work_in_hand_for_the_same_vehicle_is_found(): void
    {
        $open = $this->existing($this->tr);

        $found = WorkFileModel::workAlreadyInHand($this->plate, [$this->tr->id]);

        $this->assertCount(1, $found);
        $this->assertSame($open->file_no, $found[0]->file_no);
        $this->assertSame($this->tr->id, (int) $found[0]->work_type_id);
    }

    public function test_however_the_number_was_typed(): void
    {
        $this->existing($this->tr);

        $typed = strtolower(substr($this->plate, 0, 4).' '.substr($this->plate, 4));

        $this->assertCount(1, WorkFileModel::workAlreadyInHand($typed, [$this->tr->id]));
    }

    public function test_other_work_and_other_vehicles_are_not_it(): void
    {
        $this->existing($this->tr);

        $this->assertSame([], WorkFileModel::workAlreadyInHand($this->plate, [$this->hpa->id]), 'another work');
        $this->assertSame([], WorkFileModel::workAlreadyInHand('BR99ZZ0000', [$this->tr->id]), 'another vehicle');
        $this->assertSame([], WorkFileModel::workAlreadyInHand(null, [$this->tr->id]), 'no number at all');
    }

    /** Finished work is not in hand: the same job again is ordinary. */
    public function test_finished_work_is_not_in_hand(): void
    {
        foreach ([WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED] as $status) {
            $file = $this->existing($this->tr, $status);

            $this->assertSame([], WorkFileModel::workAlreadyInHand($this->plate, [$this->tr->id]), "$status counted");

            $file->items()->delete();
            $file->delete();
        }
    }

    public function test_a_cancelled_work_on_a_live_file_is_not_in_hand(): void
    {
        $file = $this->existing($this->tr);
        $file->items()->update(['status' => WorkFileModel::CANCELLED]);

        $this->assertSame([], WorkFileModel::workAlreadyInHand($this->plate, [$this->tr->id]));
    }

    // ------------------------------------------------------------- at the counter

    public function test_taking_in_work_already_in_hand_is_refused(): void
    {
        $open = $this->existing($this->tr);

        $this->receive([$this->row($this->tr)])
            ->assertSessionHas('error', fn ($message) => str_contains($message, $open->file_no)
                && str_contains($message, $this->tr->name));

        $this->assertSame(1, $this->filesFor($this->plate), 'a second file was written');
    }

    public function test_it_goes_in_when_the_office_says_it_is_deliberate(): void
    {
        $this->existing($this->tr);

        $this->receive([$this->row($this->tr, null, true)])->assertSessionMissing('error');

        $this->assertSame(2, $this->filesFor($this->plate));
    }

    public function test_other_work_on_the_same_vehicle_goes_in_as_before(): void
    {
        $this->existing($this->tr);

        $this->receive([$this->row($this->hpa)])->assertSessionMissing('error');

        $this->assertSame(2, $this->filesFor($this->plate));
    }

    public function test_the_same_work_after_the_last_one_finished_goes_in_as_before(): void
    {
        $this->existing($this->tr, WorkFileModel::APPROVED);

        $this->receive([$this->row($this->tr)])->assertSessionMissing('error');

        $this->assertSame(2, $this->filesFor($this->plate));
    }

    /** Two envelopes for one job on one page: always a slip, ticked or not. */
    public function test_the_same_vehicle_and_work_twice_on_one_page_is_refused(): void
    {
        $this->receive([$this->row($this->tr), $this->row($this->tr, null, true)])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'twice'));

        $this->assertSame(0, $this->filesFor($this->plate));
    }

    public function test_two_rows_for_the_same_vehicle_and_different_work_are_fine(): void
    {
        $this->receive([$this->row($this->tr), $this->row($this->hpa)])->assertSessionMissing('error');

        $this->assertSame(2, $this->filesFor($this->plate));
    }

    /** A file with no registration number cannot be checked against anything. */
    public function test_a_file_with_no_number_is_taken_in(): void
    {
        $this->existing($this->tr);

        $this->receive([['registration_no' => '', 'works' => [['work_type_id' => $this->tr->id, 'amount' => '5000']]]])
            ->assertSessionMissing('error');
    }

    // --------------------------------------------------------------- the lookup

    public function test_the_lookup_says_what_each_earlier_file_is_for(): void
    {
        $open = $this->existing($this->tr);
        $done = $this->existing($this->hpa, WorkFileModel::APPROVED);

        $files = collect($this->actingAs($this->admin)
            ->getJson(route('api.workfile.history', ['registration_no' => $this->plate]))
            ->assertOk()->json('files'))->keyBy('file_no');

        $this->assertTrue($files[$open->file_no]['open'], 'still in hand');
        $this->assertSame([$this->tr->id], array_column($files[$open->file_no]['works'], 'work_type_id'));

        $this->assertFalse($files[$done->file_no]['open'], 'finished');
        $this->assertSame($this->hpa->name, $files[$done->file_no]['works'][0]['work_type']);
    }

    /** A folder of two works names both, not only the one on the folder. */
    public function test_the_lookup_names_every_work_on_a_folder(): void
    {
        $file = $this->existing($this->tr);

        $second = new WorkFileItemModel;
        $second->work_file_id = $file->id;
        $second->work_type_id = $this->hpa->id;
        $second->customer_amount = 2000;
        $second->status = WorkFileModel::DISPATCHED;
        $second->save();

        $works = collect($this->actingAs($this->admin)
            ->getJson(route('api.workfile.history', ['registration_no' => $this->plate]))
            ->json('files.0.works'))->pluck('work_type_id')->all();

        $this->assertEqualsCanonicalizing([$this->tr->id, $this->hpa->id], $works);

        // And the hypothecation addition on that folder is in hand too.
        $this->assertCount(1, WorkFileModel::workAlreadyInHand($this->plate, [$this->hpa->id]));
    }
}

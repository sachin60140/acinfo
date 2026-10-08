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
 * A work type switched off, on the folders that already have it.
 *
 * Switching a type off is what Work Types recommends instead of deleting it,
 * and the edit screen kept a retired type on offer for the file that uses it —
 * but only for the folder's first work. A second work of that type had a Work
 * box with nothing in it to show; a box with no matching choice posts nothing,
 * and every save of the folder was refused with "Every work on the file needs
 * a type". A document, an expense, a remark or a corrected price could not be
 * added, approved folders included, short of retyping the work as something
 * it was not — which rewrites what the customer was charged for. Found in the
 * health check.
 *
 * So the screen offers the type of every work on the folder, switched off or
 * not, as it already did for the kinds of expense.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class EditRetiredWorkTypeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    private WorkTypeModel $hpa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Retired Type Admin';
        $this->admin->email = 'retired-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' retired type';
        $this->customer->mobile = '93900'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = $this->workType('TR');
        $this->hpa = $this->workType('HPA');
    }

    private function workType(string $name): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    private function retire(WorkTypeModel $type): void
    {
        $type->is_active = 0;
        $type->save();
    }

    /** @param  array<int, WorkTypeModel>  $types  one work each, charged 3,000 */
    private function file(array $types): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-RT-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01RT'.random_int(1000, 9999);
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

    /** The edit page as a browser gets it. */
    private function draw(WorkFileModel $file): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props');
    }

    /** @return array<int, string> the work types offered, label by id */
    private function offered(array $page): array
    {
        return collect($page['workTypes'])->pluck('label', 'id')->all();
    }

    public function test_every_work_on_a_folder_keeps_its_retired_type(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);

        $this->retire($this->tr);
        $this->retire($this->hpa);

        $offered = $this->offered($this->draw($file));

        // The first work's type, as before, and the second work's too.
        $this->assertArrayHasKey($this->tr->id, $offered);
        $this->assertArrayHasKey($this->hpa->id, $offered, 'the second work has no type to show');
        $this->assertStringEndsWith('(retired)', $offered[$this->hpa->id]);
    }

    /**
     * The save a browser makes from that page: a work's box posts its type
     * only when the type is among the choices. The office adds a remark and
     * nothing else, and it is saved.
     */
    public function test_a_folder_with_a_retired_second_type_can_be_saved(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $this->retire($this->hpa);

        $page = $this->draw($file);
        $offered = $this->offered($page);

        $items = collect($page['items'])->mapWithKeys(fn ($work) => [$work['id'] => array_filter([
            'work_type_id' => array_key_exists($work['work_type_id'], $offered) ? (string) $work['work_type_id'] : null,
            'customer_amount' => (string) $work['customer_amount'],
            'vendor_amount' => '',
        ], fn ($value) => $value !== null)])->all();

        $this->actingAs($this->admin)
            ->from(route('workfile.edit', $file->id))
            ->post(route('workfile.edit', $file->id), [
                'file_no' => $file->file_no,
                'received_date' => '2026-09-01',
                'work_type_id' => $file->work_type_id,
                'registration_no' => $file->registration_no,
                'customer_id' => $file->customer_id,
                'customer_amount' => (string) $file->customer_amount,
                'status' => $file->status,
                'remarks' => 'Customer rang about the NOC',
                'items' => $items,
                'drawn' => $page['drawn'],
                'was_status' => $page['wasStatus'],
            ])->assertSessionHasNoErrors()->assertRedirect(route('workfile.index'));

        $this->assertSame('Customer rang about the NOC', $file->fresh()->remarks);

        // And the work is still what it was.
        $this->assertSame(
            [$this->tr->id, $this->hpa->id],
            $file->items()->orderBy('id')->pluck('work_type_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    /** Only the folder's own: a type switched off and on no work of it stays off. */
    public function test_a_retired_type_the_folder_does_not_use_is_not_offered(): void
    {
        $file = $this->file([$this->tr, $this->hpa]);
        $other = $this->workType('HPT');
        $this->retire($other);

        $this->assertArrayNotHasKey($other->id, $this->offered($this->draw($file)));
    }
}

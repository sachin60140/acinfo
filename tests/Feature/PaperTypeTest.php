<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\User;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The papers the office deals in, and which works need them.
 *
 * Every file's checklist is built from this list, so the thing worth holding
 * here is that the list says what the form said: a paper needed outright, one
 * needed only if applicable, and one a work does not need at all — and that a
 * retired work type's list is not wiped by a form that never showed it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class PaperTypeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Paper Admin';
        $user->email = 'paper-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function workType(string $name, bool $active = true): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->is_active = $active ? 1 : 0;
        $type->save();

        return $type;
    }

    private function need(PaperTypeModel $paper, WorkTypeModel $type): ?string
    {
        return $paper->fresh()->needs()[$type->id] ?? null;
    }

    public function test_the_starting_list_is_in_place(): void
    {
        foreach (['RC (original)', 'Form 29', 'Form 30', 'Form 34', 'Form 35', 'Bank NOC / loan closure letter'] as $name) {
            $this->assertTrue(PaperTypeModel::where('name', $name)->exists(), "$name was not seeded");
        }

        // And a transfer, where there is one, is asked for both transfer forms.
        $tr = WorkTypeModel::whereRaw('UPPER(TRIM(name)) = ?', ['TR'])->first();

        if ($tr) {
            $needs = DB::table('work_type_paper')
                ->join('paper_type', 'paper_type.id', '=', 'work_type_paper.paper_type_id')
                ->where('work_type_id', $tr->id)
                ->pluck('work_type_paper.required', 'paper_type.name');

            $this->assertEquals(1, $needs['Form 29']);
            $this->assertEquals(1, $needs['Form 30']);
            $this->assertEquals(0, $needs["Buyer's undertaking"], 'only if applicable');
        }
    }

    public function test_a_paper_is_added_with_what_each_work_needs_of_it(): void
    {
        $hpt = $this->workType('HPT test');
        $tr = $this->workType('TR test');
        $hpa = $this->workType('HPA test');

        $this->actingAs($this->admin())->post(route('papertype.index'), [
            'name' => 'Seller signature proof',
            'needs' => [$hpt->id => 'required', $tr->id => 'optional', $hpa->id => ''],
        ])->assertRedirect(route('papertype.index'))->assertSessionHas('success');

        $paper = PaperTypeModel::where('name', 'Seller signature proof')->firstOrFail();

        $this->assertSame('required', $this->need($paper, $hpt));
        $this->assertSame('optional', $this->need($paper, $tr));
        $this->assertNull($this->need($paper, $hpa));

        // Goes to the foot of the checklist when no order is given.
        $this->assertSame((int) PaperTypeModel::max('sort'), (int) $paper->sort);
    }

    public function test_editing_changes_the_needs_and_can_take_one_away(): void
    {
        $hpt = $this->workType('HPT test');
        $tr = $this->workType('TR test');

        $paper = new PaperTypeModel;
        $paper->name = 'Test paper '.uniqid();
        $paper->save();
        $paper->setNeeds([$hpt->id => 'required', $tr->id => 'required']);

        $this->actingAs($this->admin())->post(route('papertype.edit', $paper->id), [
            'name' => $paper->name,
            'is_active' => '1',
            'needs' => [$hpt->id => 'optional', $tr->id => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame('optional', $this->need($paper, $hpt));
        $this->assertNull($this->need($paper, $tr), 'the transfer no longer needs it');
    }

    /** The form lists active work types only, so it must not wipe a retired one's list. */
    public function test_a_retired_work_type_keeps_its_list(): void
    {
        $active = $this->workType('Active');
        $retired = $this->workType('Retired', false);

        $paper = new PaperTypeModel;
        $paper->name = 'Test paper '.uniqid();
        $paper->save();
        $paper->setNeeds([$active->id => 'required', $retired->id => 'required']);

        $this->actingAs($this->admin())->post(route('papertype.edit', $paper->id), [
            'name' => $paper->name,
            'is_active' => '1',
            'needs' => [$active->id => ''],
        ]);

        $this->assertNull($this->need($paper, $active));
        $this->assertSame('required', $this->need($paper, $retired));
    }

    public function test_two_papers_cannot_share_a_name(): void
    {
        $this->actingAs($this->admin())->post(route('papertype.index'), ['name' => 'Form 35'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_paper_can_be_retired(): void
    {
        $paper = new PaperTypeModel;
        $paper->name = 'Test paper '.uniqid();
        $paper->save();

        $this->actingAs($this->admin())->post(route('papertype.edit', $paper->id), [
            'name' => $paper->name,
            // is_active left unticked
        ])->assertSessionHasNoErrors();

        $this->assertFalse((bool) $paper->fresh()->is_active);
    }

    public function test_a_paper_nothing_uses_can_be_deleted_and_takes_its_needs_with_it(): void
    {
        $type = $this->workType('HPT test');

        $paper = new PaperTypeModel;
        $paper->name = 'Test paper '.uniqid();
        $paper->save();
        $paper->setNeeds([$type->id => 'required']);

        $this->actingAs($this->admin())->post(route('papertype.delete', $paper->id))
            ->assertRedirect(route('papertype.index'));

        $this->assertNull(PaperTypeModel::find($paper->id));
        $this->assertFalse(DB::table('work_type_paper')->where('paper_type_id', $paper->id)->exists());
    }

    public function test_the_list_says_what_each_paper_is_needed_for(): void
    {
        $type = $this->workType('HPT test');

        $paper = new PaperTypeModel;
        $paper->name = 'Test paper '.uniqid();
        $paper->save();
        $paper->setNeeds([$type->id => 'optional']);

        $unused = new PaperTypeModel;
        $unused->name = 'Unused paper '.uniqid();
        $unused->save();

        $rows = collect($this->actingAs($this->admin())->getJson(route('papertype.index'))->assertOk()->json('props.rows'))
            ->keyBy('id');

        $this->assertSame('if applicable: '.$type->name, $rows[$paper->id]['needed_for']);
        $this->assertStringContainsString('never asked for', $rows[$unused->id]['needed_for']);
    }

    public function test_the_form_offers_every_active_work_type_with_its_current_need(): void
    {
        $type = $this->workType('HPT test');
        $retired = $this->workType('Retired', false);

        $paper = new PaperTypeModel;
        $paper->name = 'Test paper '.uniqid();
        $paper->save();
        $paper->setNeeds([$type->id => 'required']);

        $page = $this->actingAs($this->admin())->getJson(route('papertype.edit', $paper->id))->json('page');
        $offered = collect($page['workTypes'])->keyBy('id');

        $this->assertSame('required', $offered[$type->id]['need']);
        $this->assertFalse($offered->has($retired->id));
    }

    public function test_nobody_signed_out_can_reach_it(): void
    {
        $this->get(route('papertype.index'))->assertRedirect(url('/admin'));
        $this->post(route('papertype.index'), ['name' => 'Sneaky'])->assertRedirect(url('/admin'));
        $this->assertFalse(PaperTypeModel::where('name', 'Sneaky')->exists());
    }
}

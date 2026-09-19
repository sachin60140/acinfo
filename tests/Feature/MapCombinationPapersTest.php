<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Giving the retired combination types the papers their parts need.
 *
 * Before a file could hold several works, one type stood for all of them. Those
 * types were retired; the files booked under them were not, and they are still
 * in the office. Nobody mapped papers to them, so they could not be audited —
 * which is what left nine of them stuck in Paper Pendency with no screen to
 * appear on.
 *
 * The answer is not a special case in the code. An HPT + TR + HPA file needs
 * the papers of an HPT, a TR and an HPA, and the office has already decided
 * each of those lists.
 *
 * It writes to the lists the dispatch gate enforces, so it says what it would
 * do and does nothing until it is told twice.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class MapCombinationPapersTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, WorkTypeModel> */
    private array $types = [];

    /** @var array<string, PaperTypeModel> */
    private array $papers = [];

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique per run, so these types never collide with the real ones.
        $this->tag = strtoupper(substr(uniqid(), -6));
    }

    private function paper(string $name): PaperTypeModel
    {
        if (isset($this->papers[$name])) {
            return $this->papers[$name];
        }

        $paper = new PaperTypeModel;
        $paper->name = $name.' '.$this->tag;
        $paper->is_active = 1;
        $paper->save();

        return $this->papers[$name] = $paper;
    }

    /** A work type, and the papers it asks for: name => required. */
    private function type(string $name, array $papers = [], bool $active = true): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $this->named($name);
        $type->is_active = $active ? 1 : 0;
        $type->save();

        foreach ($papers as $paper => $required) {
            DB::table('work_type_paper')->insert([
                'work_type_id' => $type->id,
                'paper_type_id' => $this->paper($paper)->id,
                'required' => $required ? 1 : 0,
            ]);
        }

        return $this->types[$name] = $type;
    }

    /** "AA + BB" becomes "AA-TAG + BB-TAG", so the parts match by name. */
    private function named(string $name): string
    {
        return collect(explode('+', $name))
            ->map(fn ($part) => trim($part).'-'.$this->tag)
            ->implode(' + ');
    }

    private function map(bool $apply = false): int
    {
        return $this->artisan('papers:map-combinations', $apply ? ['--apply' => true] : [])->run();
    }

    /** @return array<int, int> paper type id => required */
    private function listFor(WorkTypeModel $type): array
    {
        return DB::table('work_type_paper')
            ->where('work_type_id', $type->id)
            ->pluck('required', 'paper_type_id')
            ->map(fn ($required) => (int) $required)
            ->all();
    }

    // -------------------------------------------------------------- the mapping

    public function test_a_combination_gets_the_papers_of_every_part(): void
    {
        $this->type('AA', ['Form 29' => true, 'Insurance' => false]);
        $this->type('BB', ['Form 30' => true]);
        $combined = $this->type('AA + BB', [], false);

        $this->map(true);

        $list = $this->listFor($combined);

        $this->assertCount(3, $list, 'the union is not the papers of both parts');
        $this->assertArrayHasKey($this->paper('Form 29')->id, $list);
        $this->assertArrayHasKey($this->paper('Form 30')->id, $list);
        $this->assertArrayHasKey($this->paper('Insurance')->id, $list);
    }

    /** A paper both parts ask for is asked for once. */
    public function test_a_paper_both_parts_need_is_listed_once(): void
    {
        $this->type('CC', ['Form 29' => true]);
        $this->type('DD', ['Form 29' => true, 'NOC' => false]);
        $combined = $this->type('CC + DD', [], false);

        $this->map(true);

        $this->assertCount(2, $this->listFor($combined));
    }

    /**
     * Required wins. A paper the transfer cannot go without is not optional
     * because the hypothecation could manage without it.
     */
    public function test_required_on_one_part_is_required_on_the_whole(): void
    {
        $this->type('EE', ['Form 29' => false]);
        $this->type('FF', ['Form 29' => true]);

        // Both ways round: whichever part is read last must not have the last
        // word, or the answer depends on how somebody typed the name.
        $optionalFirst = $this->type('EE + FF', [], false);
        $requiredFirst = $this->type('FF + EE', [], false);

        $this->map(true);

        $form29 = $this->paper('Form 29')->id;

        $this->assertSame(1, $this->listFor($optionalFirst)[$form29]);
        $this->assertSame(1, $this->listFor($requiredFirst)[$form29], 'the optional part had the last word');
    }

    // ------------------------------------------------------------ what it leaves

    public function test_a_type_that_already_has_a_list_is_left_alone(): void
    {
        $this->type('GG', ['Form 29' => true]);
        $this->type('HH', ['Form 30' => true]);
        $combined = $this->type('GG + HH', ['NOC' => false], false);

        $this->map(true);

        $this->assertSame([$this->paper('NOC')->id => 0], $this->listFor($combined),
            'a list somebody set by hand was added to');
    }

    public function test_a_part_nobody_can_find_is_skipped_rather_than_guessed(): void
    {
        $this->type('II', ['Form 29' => true]);
        $combined = $this->type('II + NOT A TYPE HERE', [], false);

        $this->map(true);

        $this->assertSame([], $this->listFor($combined), 'papers were invented for an unknown part');
    }

    public function test_an_ordinary_work_type_is_never_touched(): void
    {
        $plain = $this->type('JJ', ['Form 29' => true]);

        $this->map(true);

        $this->assertCount(1, $this->listFor($plain));
    }

    // ------------------------------------------------------------------ the guard

    public function test_it_writes_nothing_until_it_is_told_twice(): void
    {
        $this->type('KK', ['Form 29' => true]);
        $this->type('LL', ['Form 30' => true]);
        $combined = $this->type('KK + LL', [], false);

        $this->map();

        $this->assertSame([], $this->listFor($combined), 'a dry run wrote to the paper lists');

        $this->map(true);

        $this->assertCount(2, $this->listFor($combined));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->type('MM', ['Form 29' => true]);
        $this->type('NN', ['Form 30' => true]);
        $combined = $this->type('MM + NN', [], false);

        $this->map(true);
        $before = $this->listFor($combined);

        $this->map(true);

        $this->assertSame($before, $this->listFor($combined), 'a second run duplicated the list');
    }

    // ------------------------------------------------------------- the whole point

    /**
     * The file that was stuck is now a file waiting to be checked.
     *
     * This is what the exercise was for: it leaves the section on Paper Audit
     * that no screen could help with, and joins the queue that can.
     */
    public function test_a_stuck_file_becomes_one_the_office_can_audit(): void
    {
        $admin = new User;
        $admin->name = 'Mapping Admin';
        $admin->email = 'mapping-'.uniqid().'@example.com';
        $admin->password = Hash::make('password-for-tests');
        $admin->user_type = 1;
        $admin->save();

        $customer = new PartyModel;
        $customer->party_type = 'customer';
        $customer->name = 'Customer '.uniqid().' with a combination file';
        $customer->mobile = '93400'.random_int(10000, 99999);
        $customer->is_active = 1;
        $customer->save();

        $this->type('OO', ['Form 29' => true]);
        $this->type('PP', ['Form 30' => true]);
        $combined = $this->type('OO + PP', [], false);

        $file = new WorkFileModel;
        $file->file_no = 'F-MC-'.uniqid();
        $file->received_date = now()->subDays(30)->toDateString();
        $file->registration_no = 'BR01MC'.random_int(1000, 9999);
        $file->work_type_id = $combined->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = 5000;
        $file->status = WorkFileModel::PAPER_PENDENCY;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $combined->id;
        $item->customer_amount = 5000;
        $item->status = WorkFileModel::PAPER_PENDENCY;
        $item->save();

        $file->syncLedger();

        $before = $this->actingAs($admin)->getJson(route('workfile.paperaudit'))->assertOk();

        $this->assertNotNull(collect($before->json('props.stuck'))->firstWhere('id', $file->id),
            'the file is not stuck to begin with');

        $this->map(true);

        $after = $this->actingAs($admin)->getJson(route('workfile.paperaudit'))->assertOk();

        $this->assertNull(collect($after->json('props.stuck'))->firstWhere('id', $file->id),
            'the file is still stuck after its papers were mapped');
        $this->assertNotNull(collect($after->json('props.toCheck'))->firstWhere('id', $file->id),
            'the file did not join the queue to be checked');

        // And the checklist it would be checked against is both parts' papers.
        $this->assertCount(2, $file->fresh()->paperChecklist());
    }
}

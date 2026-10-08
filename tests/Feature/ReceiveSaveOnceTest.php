<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One envelope, one file, however many times Receive Files is pressed.
 *
 * Found in the health check: a double click sent the batch twice. With no
 * registration number on a card — it is optional — nothing could tell the
 * second from a new envelope, so two files were opened and the customer was
 * charged twice. With a number, the second was refused only if the first had
 * finished: two presses in flight together were both checked before either had
 * written, and both went in.
 *
 * So the page carries a one-time token, and the same batch sent again under it
 * is told it was saved. And the vehicle check is asked under a lock on the
 * customer, inside the save, where a second press waits for the first.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ReceiveSaveOnceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    private string $plate;

    /** The token the page was drawn with; this test's own. */
    private string $once;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Receive Once Admin';
        $this->admin->email = 'receive-once-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' receiving once';
        $this->customer->mobile = '93601'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();

        $this->plate = 'BR01SO'.random_int(1000, 9999);
        $this->once = 'drawn-'.uniqid();
    }

    /** A batch as the screen posts it, under the token the page was drawn with. */
    private function batch(array $rows, ?string $once = null, bool $withToken = true): array
    {
        $batch = [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => $rows,
        ];

        if ($withToken) {
            $batch['once'] = $once ?? $this->once;
        }

        return $batch;
    }

    private function row(?string $plate = '', string $amount = '5000', string $description = 'Papers'): array
    {
        return [
            'registration_no' => $plate,
            'description' => $description,
            'works' => [['work_type_id' => $this->tr->id, 'amount' => $amount]],
        ];
    }

    private function receive(array $batch)
    {
        return $this->actingAs($this->admin)->post(route('workfile.receive'), $batch);
    }

    /** The token a freshly drawn Receive Files page carries. */
    private function drawnToken(): ?string
    {
        $page = $this->actingAs($this->admin)->get(route('workfile.receive'))->assertOk()->getContent();

        return preg_match('/name="once" value="([^"]+)"/', $page, $match) ? $match[1] : null;
    }

    private function files(): int
    {
        return WorkFileModel::where('customer_id', $this->customer->id)->count();
    }

    /** What the customer's statement says they owe. */
    private function owes(): float
    {
        return (float) PartyLedgerModel::where('party_id', $this->customer->id)
            ->selectRaw(str_replace('party_ledger.', '', PartyLedgerModel::BALANCE_SQL).' as balance')
            ->value('balance');
    }

    // ------------------------------------------------------------ the token

    public function test_the_page_carries_a_token_of_its_own_each_time_it_is_drawn(): void
    {
        $one = $this->drawnToken();
        $two = $this->drawnToken();

        $this->assertNotEmpty($one, 'the form posts no token');
        $this->assertNotEmpty($two);
        $this->assertNotSame($one, $two, 'two pages drawn with the same token');
    }

    /** The finding itself: no number, pressed twice, opened twice. */
    public function test_a_batch_with_no_number_sent_twice_opens_one_file(): void
    {
        $batch = $this->batch([$this->row('')]);

        $this->receive($batch)->assertRedirect(route('workfile.index'))->assertSessionMissing('error');

        $number = WorkFileModel::where('customer_id', $this->customer->id)->value('file_no');

        $again = $this->receive($batch);

        $this->assertSame(1, $this->files(), 'the same envelope was opened twice');
        $this->assertEqualsWithDelta(5000, $this->owes(), 0.005, 'the customer was charged twice');

        // Answered as the first press was, since its answer is the one shown.
        $again->assertRedirect(route('workfile.index'))
            ->assertSessionMissing('error')
            ->assertSessionHas('success', fn ($message) => str_contains($message, $number)
                && str_contains($message, 'pressed twice'));
    }

    /*
     * The same, with the cache where the server keeps it: a table in the same
     * database, written inside the save. Rolled back with everything else.
     */
    public function test_it_holds_with_the_cache_kept_in_the_database(): void
    {
        Cache::setDefaultDriver('database');

        $batch = $this->batch([$this->row('')]);

        $this->receive($batch);
        $this->receive($batch)->assertSessionHas('success', fn ($message) => str_contains($message, 'pressed twice'));

        $this->assertSame(1, $this->files());
    }

    /** Every card of it, not just the first. */
    public function test_a_batch_of_several_sent_twice_opens_each_once_and_names_them(): void
    {
        $batch = $this->batch([$this->row('', '5000', 'First'), $this->row('', '3000', 'Second')]);

        $this->receive($batch);

        $numbers = WorkFileModel::where('customer_id', $this->customer->id)->orderBy('id')->pluck('file_no')->all();

        $again = $this->receive($batch);

        $this->assertSame(2, $this->files());
        $this->assertEqualsWithDelta(8000, $this->owes(), 0.005);

        $again->assertSessionHas('success', fn ($message) => str_contains($message, $numbers[0])
            && str_contains($message, $numbers[1]));
    }

    /*
     * With a number, the second press used to be refused as work already in
     * hand — and told to tick "take it in anyway", which is the one thing that
     * would have opened the duplicate. It was saved; it says so.
     */
    public function test_a_batch_with_a_number_sent_twice_is_told_it_was_saved(): void
    {
        $batch = $this->batch([$this->row($this->plate)]);

        $this->receive($batch);

        $again = $this->receive($batch);

        $this->assertSame(1, $this->files());

        $again->assertRedirect(route('workfile.index'))
            ->assertSessionMissing('error')
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'pressed twice'));
    }

    /*
     * The same page sent again with something changed on it — come back to
     * with Back and typed over — is not a second press. Told it was saved, the
     * office would believe those papers were in; taken in unasked, a figure
     * corrected on it would open the first envelope a second time. Refused,
     * with what was typed kept, and the page it comes back on is a new one:
     * pressed again, it goes in.
     */
    public function test_a_used_page_sent_with_other_papers_is_refused_and_kept(): void
    {
        $this->receive($this->batch([$this->row('', '5000', 'First envelope')]));

        $first = WorkFileModel::where('customer_id', $this->customer->id)->value('file_no');

        $this->receive($this->batch([$this->row('', '3000', 'Second envelope')]))
            ->assertSessionHas('error', fn ($message) => str_contains($message, $first)
                && str_contains($message, 'nothing was saved'))
            ->assertSessionHasInput('rows');

        $this->assertSame(1, $this->files(), 'a changed page went in on a used token');

        $fresh = $this->drawnToken();

        $this->assertNotEmpty($fresh);
        $this->assertNotSame($this->once, $fresh);

        $this->receive($this->batch([$this->row('', '3000', 'Second envelope')], $fresh))
            ->assertRedirect(route('workfile.index'))
            ->assertSessionMissing('error')
            ->assertSessionHas('success', fn ($message) => is_string($message) && ! str_contains($message, 'pressed twice'));

        $this->assertSame(2, $this->files());
        $this->assertEqualsWithDelta(8000, $this->owes(), 0.005);
    }

    /** Another page is another batch, even with the same papers on it. */
    public function test_another_page_is_another_batch(): void
    {
        $this->receive($this->batch([$this->row('')], 'one-page-'.uniqid()));
        $this->receive($this->batch([$this->row('')], 'another-page-'.uniqid()));

        $this->assertSame(2, $this->files());
    }

    /*
     * Only a save marks the token. A batch refused — here for work already in
     * hand — and sent again once that is cleared up is saved, not waved off as
     * a repeat of something that never went in.
     */
    public function test_a_refused_batch_can_be_sent_again(): void
    {
        $open = $this->existing();
        $batch = $this->batch([$this->row($this->plate)]);

        $this->receive($batch)->assertSessionHas('error');
        $this->assertSame(1, $this->files());

        $open->status = WorkFileModel::APPROVED;
        $open->save();
        $open->items()->update(['status' => WorkFileModel::APPROVED]);

        $this->receive($batch)
            ->assertRedirect(route('workfile.index'))
            ->assertSessionMissing('error')
            ->assertSessionHas('success', fn ($message) => is_string($message) && ! str_contains($message, 'pressed twice'));

        $this->assertSame(2, $this->files());
    }

    /*
     * Nor does a save that failed. With the cache somewhere the database
     * cannot undo — a file, or memory as here — a save that broke after its
     * mark was written left the mark behind, naming files that were never
     * kept. The page's next press would have been told they were saved.
     */
    public function test_a_save_that_did_not_go_through_does_not_count(): void
    {
        $batch = $this->batch([$this->row('')]);

        Event::listen(KeyWritten::class, function (KeyWritten $event) {
            if (str_starts_with($event->key, 'receive-once.')) {
                throw new \RuntimeException('The save broke at its last step.');
            }
        });

        try {
            $this->withoutExceptionHandling()->receive($batch);
            $this->fail('the premise: the save broke');
        } catch (\RuntimeException $broke) {
            $this->assertSame('The save broke at its last step.', $broke->getMessage());
        }

        $this->assertSame(0, $this->files(), 'the premise: nothing was kept');

        Event::forget(KeyWritten::class);
        $this->withExceptionHandling();

        $this->receive($batch)
            ->assertRedirect(route('workfile.index'))
            ->assertSessionMissing('error')
            ->assertSessionHas('success', fn ($message) => is_string($message) && ! str_contains($message, 'pressed twice'));

        $this->assertSame(1, $this->files(), 'told it was saved, and nothing was');
    }

    /** A page drawn before the token existed is saved as it always was. */
    public function test_a_page_without_a_token_is_still_saved(): void
    {
        $this->receive($this->batch([$this->row('')], null, false))
            ->assertRedirect(route('workfile.index'))
            ->assertSessionMissing('error');

        $this->assertSame(1, $this->files());
    }

    // -------------------------------------------------------------- the lock

    /*
     * Two presses in flight together, played out in one process.
     *
     * The other press's file is written the moment this one's save begins —
     * which is where it lands when this press has waited on the customer's
     * lock for the other to finish. Asked before the save began, as it was,
     * the vehicle still looked clear, and a second file went in beside it.
     */
    public function test_a_file_written_while_this_press_waited_is_found(): void
    {
        $written = false;

        Event::listen(TransactionBeginning::class, function () use (&$written) {
            if (! $written) {
                $written = true;
                $this->existing();
            }
        });

        // No token, so only the vehicle check can stop it.
        $this->receive($this->batch([$this->row($this->plate)], null, false))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'already open for this vehicle'));

        $this->assertTrue($written, 'the premise: the other press wrote its file');
        $this->assertSame(1, $this->files(), 'both presses opened a file');
    }

    /*
     * What makes that safe when the two really are at once: the customer is
     * locked first thing in the save, and both questions — has this page saved
     * already, does this vehicle have the work — are asked after it, inside the
     * same transaction. A second press waits on the lock until the first has
     * written, and only then looks. The cache is the database's here, as on
     * the server, so its read is a query that can be placed.
     */
    public function test_both_questions_are_asked_under_the_customers_lock_inside_the_save(): void
    {
        Cache::setDefaultDriver('database');

        $outside = DB::transactionLevel();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'bindings' => implode(' ', array_map('strval', $query->bindings)),
                'level' => $query->connection->transactionLevel(),
            ];
        });

        $this->receive($this->batch([$this->row($this->plate)]))->assertSessionMissing('error');

        $lock = collect($queries)->search(fn ($q) => str_contains($q['sql'], 'from `party`')
            && str_contains($q['sql'], 'for update'));

        $token = collect($queries)->search(fn ($q) => str_contains($q['sql'], 'from `cache`')
            && str_contains($q['bindings'], 'receive-once.'));

        $check = collect($queries)->search(fn ($q) => str_contains($q['sql'], 'from `work_file_item`')
            && str_contains($q['sql'], '`work_file`.`registration_no` = ?'));

        $write = collect($queries)->search(fn ($q) => str_starts_with($q['sql'], 'insert into `work_file`'));

        $this->assertNotFalse($lock, 'the customer is never locked');
        $this->assertNotFalse($token, 'the token is never asked about');
        $this->assertNotFalse($check, 'the vehicle is never checked');
        $this->assertNotFalse($write, 'nothing was written');

        $this->assertGreaterThan($outside, $queries[$lock]['level'], 'the lock is taken outside the save');
        $this->assertGreaterThan($outside, $queries[$token]['level'], 'the token is asked about outside the save');
        $this->assertGreaterThan($outside, $queries[$check]['level'], 'the vehicle is checked outside the save');
        $this->assertLessThan($token, $lock, 'the token is asked about before the customer is locked');
        $this->assertLessThan($check, $lock, 'the vehicle is checked before the customer is locked');
        $this->assertLessThan($write, $check, 'the vehicle is checked after the file is written');
    }

    /** A file already open for this vehicle and this work. */
    private function existing(): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-SO-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = $this->plate;
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 5000;
        $file->status = WorkFileModel::DISPATCHED;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = 5000;
        $item->status = WorkFileModel::DISPATCHED;
        $item->save();

        return $file;
    }
}

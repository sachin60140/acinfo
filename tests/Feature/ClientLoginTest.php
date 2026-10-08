<?php

namespace Tests\Feature;

use App\Models\ClientModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The old client portal's front door, at /user.
 *
 * Closed to new clients but kept open for the old ones, so it still answers
 * anybody who posts a mobile number to it — and what it must not answer is
 * whether that number belongs to one of the agency's clients. The words were
 * made the same for every refusal long ago. The time each refusal takes was
 * not: a number that was not a client's, or a client never given a password,
 * was turned away before any password was checked, in a few milliseconds,
 * while a real client's wrong password paid for a bcrypt check of a quarter
 * of a second. The same message, told apart by a stopwatch.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ClientLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'a-real-password-8';

    /**
     * A client of the old book.
     *
     * The password is hashed at cost 12 on purpose. That is what every hash the
     * old book holds was made at ($2y$12$), and this suite runs bcrypt at 4
     * rounds to stay quick — a client hashed at the suite's rounds would make
     * a real check look as cheap as a skipped one.
     */
    private function client(?string $password = self::PASSWORD): ClientModel
    {
        $client = new ClientModel;
        $client->name = 'Login Client '.uniqid();
        $client->mobile = '92800'.random_int(10000, 99999);
        // Never given one: empty, which the column takes however it was made —
        // NOT NULL where the table was created with it, nullable where a later
        // migration added it. The sign-in reads both alike; see filled().
        $client->password = $password === null ? '' : Hash::make($password, ['rounds' => 12]);
        // Not nullable.
        $client->address = 'Near the RTO, Motihari';
        $client->save();

        return $client;
    }

    private function signIn(string $mobile, string $password)
    {
        return $this->post('/user-login', [
            'username' => $mobile,
            'password' => $password,
        ]);
    }

    public function test_a_client_with_a_password_gets_in(): void
    {
        $client = $this->client();

        $this->signIn($client->mobile, self::PASSWORD)
            ->assertRedirect('user/dashboard');

        $this->assertSame($client->id, session('userid'));
    }

    /**
     * Every way of being turned away.
     *
     * The first two are the ones that used to come back without checking a
     * password at all. The wrong password always paid for its check, and is
     * here as the measure the other two have to match.
     */
    public static function refusals(): array
    {
        return [
            'a number that is not a client\'s' => ['unknown'],
            'a client never given a password' => ['no password'],
            'a client with the wrong password' => ['wrong password'],
        ];
    }

    /**
     * Every refusal reads the same, and costs the same.
     *
     * Costing the same is the part a stopwatch can see, so this counts the
     * bcrypt checks rather than timing them: exactly one per attempt, against
     * a hash of the same cost as the real client's. A number nobody holds and a
     * client with no password are checked against a stand-in hash that matches
     * nothing, so they wait as long as a real client does.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_every_refusal_reads_the_same_and_costs_the_same(string $case): void
    {
        $real = $this->client();

        [$mobile, $password] = match ($case) {
            'unknown' => ['10000'.random_int(10000, 99999), self::PASSWORD],
            'no password' => [$this->client(null)->mobile, self::PASSWORD],
            'wrong password' => [$real->mobile, 'not-the-password'],
        };

        if ($case === 'unknown') {
            $this->assertFalse(ClientModel::where('mobile', $mobile)->exists(), 'the number is nobody\'s');
        }

        // Every hash the login checks a password against, in order.
        $checked = [];

        Hash::shouldReceive('check')
            ->once()
            ->andReturnUsing(function ($value, $hash) use (&$checked) {
                $checked[] = $hash;

                return password_verify($value, $hash);
            });

        $this->signIn($mobile, $password)
            ->assertRedirect('/')
            ->assertSessionHas('error', 'Mobile number or password is incorrect.');

        $this->assertNull(session('userid'));

        $this->assertCount(1, $checked, 'one bcrypt check, however the attempt fails');
        $this->assertSame('bcrypt', password_get_info($checked[0])['algoName']);
        $this->assertSame(
            password_get_info($real->password)['options']['cost'],
            password_get_info($checked[0])['options']['cost'],
            'as slow a check as a real client\'s'
        );
    }
}

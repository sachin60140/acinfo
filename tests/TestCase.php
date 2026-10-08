<?php

namespace Tests;

use App\Models\PartyModel;
use App\Models\WorkTypeModel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * actingAs() as a sign-in, as far as the office pages can tell.
     *
     * Every office page checks that the password the session was signed in
     * with is still the account's (AuthenticateSession, in the 'admin' group in
     * bootstrap/app.php), and the session notes it on the first office page it
     * opens. A real sign-in starts with nothing noted: signing out, by hand or
     * by that check, empties the session. actingAs() signs nobody in or out,
     * though, and within one test the session lasts from request to request,
     * so a test that acted as one admin and then another (a fresh admin for
     * each request is common here) had the second turned away, carrying the
     * first one's password. The note is let go of here, as a sign-in would
     * find it.
     *
     * The tests of that check itself sign in through the sign-in page instead
     * (AdminPasswordTest, AdminSignInTest), and are not touched by this.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        $this->app['session']->forget('password_hash_'.$this->app['auth']->getDefaultDriver());

        return parent::be($user, $guard);
    }

    /**
     * The reference rows a work file cannot be built without.
     *
     * These tests run against a real database with DatabaseTransactions rather
     * than a rebuilt one — see the note in PartyLedgerTest — so for a long time
     * they took whichever work type and party happened to be sitting there. On
     * a machine somebody has been working in, there always is one. On a fresh
     * checkout or a build server there is not, and
     * `WorkTypeModel::query()->value('id')` comes back null: 142 tests failed
     * at once on `Column 'work_type_id' cannot be null`, none of them about
     * work types.
     *
     * So a test asks for what it needs and gets it either way. Nothing is
     * seeded ahead of time and nothing is deleted: made inside the test's own
     * transaction, a row invented here is rolled back with everything else.
     */
    protected function anyWorkType(): WorkTypeModel
    {
        $type = WorkTypeModel::where('is_active', 1)->first();

        if ($type) {
            return $type;
        }

        $type = new WorkTypeModel;
        $type->name = 'Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /** The same, for the customer or vendor a file has to belong to. */
    protected function anyParty(string $partyType): PartyModel
    {
        $party = PartyModel::where('party_type', $partyType)->where('is_active', 1)->first();

        if ($party) {
            return $party;
        }

        $party = new PartyModel;
        $party->party_type = $partyType;
        $party->name = ucfirst($partyType).' '.uniqid();
        // Unique within a role, so it cannot collide with a real one.
        $party->mobile = '9'.random_int(100000000, 999999999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }
}

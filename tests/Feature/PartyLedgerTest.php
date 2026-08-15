<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Vendor & customer ledger arithmetic.
 *
 * DatabaseTransactions, never RefreshDatabase. phpunit.xml leaves DB_CONNECTION
 * unset, so these run against the same MySQL database the application uses —
 * RefreshDatabase would migrate:fresh it and destroy the live client ledger.
 * Every test here writes inside a transaction that is rolled back afterwards.
 */
class PartyLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private function party(string $type, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.$mobile;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function entry(int $partyId, string $date, string $side, float $amount): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $partyId;
        $entry->txn_date = $date;
        $entry->entry_type = $side;
        $entry->amount = $amount;
        $entry->particular = 'Test entry';
        $entry->save();
    }

    public function test_customer_balance_is_debits_less_credits(): void
    {
        $customer = $this->party('customer', '9000000101');

        $this->entry($customer->id, '2026-01-01', 'debit', 5000);
        $this->entry($customer->id, '2026-02-10', 'debit', 2000);
        $this->entry($customer->id, '2026-03-05', 'credit', 3000);

        $this->assertSame(4000.0, PartyLedgerModel::currentBalance($customer->id));
    }

    public function test_vendor_balance_is_negative_when_payable(): void
    {
        $vendor = $this->party('vendor', '9000000102');

        $this->entry($vendor->id, '2026-02-01', 'credit', 10000);
        $this->entry($vendor->id, '2026-02-20', 'debit', 4000);

        $this->assertSame(-6000.0, PartyLedgerModel::currentBalance($vendor->id));
    }

    /**
     * The running balance has to follow date order, not the order rows were
     * entered — back-dating is routine and every balance below the inserted row
     * must move with it.
     */
    public function test_back_dated_entry_reorders_the_running_balance(): void
    {
        $customer = $this->party('customer', '9000000103');

        $this->entry($customer->id, '2026-01-01', 'debit', 5000);
        $this->entry($customer->id, '2026-03-05', 'credit', 3000);
        $this->entry($customer->id, '2026-01-15', 'debit', 500); // entered last, dated second

        $balance = 0.0;
        $sequence = [];
        foreach (PartyLedgerModel::statement($customer->id)['getRecords'] as $row) {
            $balance += $row->signedAmount();
            $sequence[] = $row->txn_date.'='.$balance;
        }

        $this->assertSame(
            ['2026-01-01=5000', '2026-01-15=5500', '2026-03-05=2500'],
            $sequence
        );
    }

    public function test_filtered_statement_brings_forward_the_earlier_balance(): void
    {
        $customer = $this->party('customer', '9000000104');

        $this->entry($customer->id, '2026-01-01', 'debit', 5000);
        $this->entry($customer->id, '2026-02-10', 'debit', 2000);
        $this->entry($customer->id, '2026-03-05', 'credit', 3000);

        $statement = PartyLedgerModel::statement($customer->id, '2026-02-01', '2026-03-31');

        $this->assertSame(5000.0, $statement['opening'], 'balance brought forward');
        $this->assertSame(2000.0, $statement['debits']);
        $this->assertSame(3000.0, $statement['credits']);
        $this->assertSame(4000.0, $statement['closing']);
        $this->assertCount(2, $statement['getRecords']);
    }

    public function test_statement_to_date_includes_that_whole_day(): void
    {
        $customer = $this->party('customer', '9000000105');

        $this->entry($customer->id, '2026-02-10', 'debit', 100);
        $this->entry($customer->id, '2026-02-11', 'debit', 100);

        $this->assertCount(1, PartyLedgerModel::statement($customer->id, null, '2026-02-10')['getRecords']);
    }

    public function test_balances_are_formatted_as_dr_or_cr_never_signed(): void
    {
        $this->assertSame('4,000.00 Dr', PartyLedgerModel::formatBalance(4000));
        $this->assertSame('6,000.00 Cr', PartyLedgerModel::formatBalance(-6000));
        $this->assertSame('125,000.50 Cr', PartyLedgerModel::formatBalance(-125000.5));
        $this->assertSame('0.00', PartyLedgerModel::formatBalance(0), 'a settled account has no side');
    }

    public function test_the_same_mobile_can_be_both_a_customer_and_a_vendor(): void
    {
        $this->party('customer', '9000000106');
        $vendor = $this->party('vendor', '9000000106');

        $this->assertNotNull($vendor->id);
    }

    public function test_a_mobile_cannot_repeat_within_one_role(): void
    {
        $this->party('customer', '9000000107');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->party('customer', '9000000107');
    }

    public function test_inactive_parties_leave_the_entry_form_but_stay_on_the_list(): void
    {
        $customer = $this->party('customer', '9000000108');

        $listed = PartyModel::withBalance('customer')->count();
        $selectable = PartyModel::selectList('customer')->count();

        $customer->is_active = 0;
        $customer->save();

        $this->assertSame($listed, PartyModel::withBalance('customer')->count(), 'still listed');
        $this->assertSame($selectable - 1, PartyModel::selectList('customer')->count(), 'no longer selectable');
    }

    /**
     * An edit form must keep offering whatever the record already points at.
     * Without this the field re-saves as something else, silently moving money.
     */
    public function test_a_deactivated_party_is_still_offered_when_already_selected(): void
    {
        $customer = $this->party('customer', '9000000109');
        $customer->is_active = 0;
        $customer->save();

        $this->assertTrue(
            PartyModel::selectList('customer', $customer->id)->contains('id', $customer->id)
        );
    }

    public function test_a_party_with_no_entries_reports_a_zero_balance(): void
    {
        $customer = $this->party('customer', '9000000110');

        $this->assertSame(0.0, PartyLedgerModel::currentBalance($customer->id));
        $this->assertSame(0.0, PartyLedgerModel::openingBalance($customer->id, '2026-01-01'));

        $row = PartyModel::withBalance('customer')->firstWhere('id', $customer->id);
        $this->assertSame(0.0, (float) $row->current_balance);
        $this->assertSame(0, (int) $row->entry_count);
    }
}

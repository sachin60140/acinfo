<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientLedgerModel extends Model
{
    public $table="client_ledger";

    use HasFactory;


    static function getRecord($id)
    {
        $return = ClientLedgerModel::select('client_ledger.*', 'client.name as client_name','payment_mode as payment_type')
                    ->join('client','client.id', 'client_ledger.client_id')
                    ->join('payment_type','payment_type.id', 'client_ledger.payment_by')
                    ->where('client_id', $id)
                    ->orderBy('id', 'asc')
                    ->get();
                    return $return;
    }
}

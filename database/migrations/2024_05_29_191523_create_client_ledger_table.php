<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('payment_by');
            $table->double('amount');
            $table->string('particular');
            $table->date('txn_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_ledger');
    }
};

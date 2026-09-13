<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('transfer_id')->unique();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('remarks');
            $table->decimal('balance_before', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('failure_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};

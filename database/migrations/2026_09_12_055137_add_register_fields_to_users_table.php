<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('user_id')->unique()->nullable()->after('id');
            $table->string('first_name')->nullable()->after('user_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone_number')->unique()->nullable()->after('last_name');
            $table->string('address')->nullable()->after('phone_number');
            $table->string('pin')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'user_id',
                'first_name',
                'last_name',
                'phone_number',
                'address',
                'pin'
            ]);
        });
    }
};
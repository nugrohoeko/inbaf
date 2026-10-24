<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_profiles', function (Blueprint $table) {
            $table->string('phone', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_language', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
        Schema::table('participant_profiles', function (Blueprint $table) {
            $table->dropColumn(['phone', 'date_of_birth', 'gender']);
        });
    }
};

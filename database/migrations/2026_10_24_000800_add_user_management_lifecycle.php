<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->boolean('activation_pending')->default(false);
            $table->unsignedInteger('session_version')->default(1);
            $table->softDeletes();
            $table->index(['role', 'active', 'deleted_at'], 'users_management_access_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_management_access_index');
            $table->dropColumn(['phone', 'must_change_password', 'activation_pending', 'session_version', 'deleted_at']);
        });
    }
};

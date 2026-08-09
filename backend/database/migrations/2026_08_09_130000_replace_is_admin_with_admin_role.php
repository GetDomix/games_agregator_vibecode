<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('admin_role', 20)->default('user')->index());
        DB::table('users')->where('is_admin', true)->update(['admin_role' => 'admin']);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_admin_role_check CHECK (admin_role IN ('user','admin','owner'))");
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        DB::table('users')->whereIn('admin_role', ['admin', 'owner'])->update(['is_admin' => true]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_admin_role_check');
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_role'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('daily_search_quotas');

        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = [];
        if (Schema::hasColumn('users', 'plan_expires_at')) {
            $columns[] = 'plan_expires_at';
        }
        if (Schema::hasColumn('users', 'plan')) {
            $columns[] = 'plan';
        }

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        // Structure-only rollback: deleted plans and quota counters cannot be restored.
        if (Schema::hasTable('users')) {
            if (! Schema::hasColumn('users', 'plan')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->string('plan', 32)->default('free')->after('email');
                });
            }

            if (! Schema::hasColumn('users', 'plan_expires_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('plan_expires_at')->nullable()->after('plan');
                });
            }
        }

        if (! Schema::hasTable('daily_search_quotas')) {
            Schema::create('daily_search_quotas', function (Blueprint $table) {
                $table->id();
                $table->string('quota_key', 120);
                $table->string('day', 10);
                $table->unsignedInteger('count')->default(0);
                $table->timestamps();
                $table->unique(['quota_key', 'day']);
                $table->index('day');
            });
        }
    }
};

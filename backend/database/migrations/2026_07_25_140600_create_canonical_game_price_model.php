<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('steam_appid')->unique();
            $table->string('name', 200);
            $table->string('header_image', 500)->nullable();
            $table->string('release_status', 20)->default('unknown');
            $table->date('release_date')->nullable();
            $table->timestamps();
            $table->index(['release_status', 'release_date']);
        });

        Schema::create('game_source_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('source', 20);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_refresh_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'source']);
            $table->index(['status', 'next_refresh_at']);
        });

        Schema::create('current_game_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('offer_kind', 20);
            $table->decimal('min_price_rub', 12, 2);
            $table->decimal('avg_price_rub', 12, 2)->nullable();
            $table->unsignedInteger('offer_count')->default(0);
            $table->string('cheapest_offer_title', 500)->nullable();
            $table->string('cheapest_offer_url', 1000)->nullable();
            $table->string('popular_offer_title', 500)->nullable();
            $table->string('popular_offer_url', 1000)->nullable();
            $table->decimal('popular_offer_price_rub', 12, 2)->nullable();
            $table->unsignedInteger('popular_offer_sales')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['game_id', 'source', 'offer_kind']);
            $table->index(['source', 'offer_kind', 'min_price_rub']);
        });

        Schema::create('game_price_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('offer_kind', 20);
            $table->decimal('min_price_rub', 12, 2);
            $table->string('offer_title', 500)->nullable();
            $table->string('offer_url', 1000)->nullable();
            $table->unsignedInteger('offer_sales')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(
                ['game_id', 'source', 'offer_kind', 'observed_at'],
                'game_price_observations_lookup_index'
            );
        });

        Schema::table('favorites', function (Blueprint $table) {
            $table->foreignId('game_id')
                ->nullable()
                ->after('user_id')
                ->constrained('games')
                ->nullOnDelete();
            $table->index(['game_id', 'updated_at']);
        });

        $this->addCheckConstraints();
        $this->backfillCanonicalData();
    }

    public function down(): void
    {
        Schema::table('favorites', function (Blueprint $table) {
            $table->dropIndex(['game_id', 'updated_at']);
            $table->dropConstrainedForeignId('game_id');
        });

        Schema::dropIfExists('game_price_observations');
        Schema::dropIfExists('current_game_prices');
        Schema::dropIfExists('game_source_states');
        Schema::dropIfExists('games');
    }

    private function addCheckConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE games ADD CONSTRAINT games_release_status_check CHECK (release_status IN ('unknown', 'announced', 'released'))");
        DB::statement("ALTER TABLE game_source_states ADD CONSTRAINT game_source_states_source_check CHECK (source IN ('steam', 'plati', 'ggsel'))");
        DB::statement("ALTER TABLE game_source_states ADD CONSTRAINT game_source_states_status_check CHECK (status IN ('pending', 'fresh', 'stale', 'failed'))");
        DB::statement("ALTER TABLE current_game_prices ADD CONSTRAINT current_game_prices_source_check CHECK (source IN ('steam', 'plati', 'ggsel'))");
        DB::statement("ALTER TABLE current_game_prices ADD CONSTRAINT current_game_prices_offer_kind_check CHECK (offer_kind IN ('official', 'key', 'gift', 'account', 'rent', 'other'))");
        DB::statement('ALTER TABLE current_game_prices ADD CONSTRAINT current_game_prices_amounts_check CHECK (min_price_rub >= 0 AND (avg_price_rub IS NULL OR avg_price_rub >= 0) AND (popular_offer_price_rub IS NULL OR popular_offer_price_rub >= 0))');
        DB::statement("ALTER TABLE game_price_observations ADD CONSTRAINT game_price_observations_source_check CHECK (source IN ('steam', 'plati', 'ggsel'))");
        DB::statement("ALTER TABLE game_price_observations ADD CONSTRAINT game_price_observations_offer_kind_check CHECK (offer_kind IN ('official', 'key', 'gift', 'account', 'rent', 'other'))");
        DB::statement('ALTER TABLE game_price_observations ADD CONSTRAINT game_price_observations_amount_check CHECK (min_price_rub >= 0)');
    }

    private function backfillCanonicalData(): void
    {
        $this->backfillGamesFrom('search_histories', 'created_at');
        $this->backfillGamesFrom('favorites', 'updated_at');
        $this->backfillFavoriteLinks();
        $this->backfillSteamPricesFromFavorites();
        $this->backfillSteamPricesFromSnapshots();
        $this->createBackfilledSteamHistory();
    }

    private function backfillGamesFrom(string $table, string $timestampColumn): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->whereNotNull('appid')
            ->orderBy($timestampColumn)
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($timestampColumn) {
                foreach ($rows as $row) {
                    $appid = (int) $row->appid;
                    if ($appid < 1) {
                        continue;
                    }

                    $existing = DB::table('games')->where('steam_appid', $appid)->first();
                    $candidateName = trim((string) ($row->game_name ?? ''));
                    $candidateImage = trim((string) ($row->header_image ?? ''));
                    $timestamp = $row->{$timestampColumn} ?? now();

                    $values = [
                        'name' => mb_substr(
                            $candidateName !== '' ? $candidateName : ($existing->name ?? "Steam app {$appid}"),
                            0,
                            200
                        ),
                        'header_image' => $candidateImage !== '' ? mb_substr($candidateImage, 0, 500) : ($existing->header_image ?? null),
                        'updated_at' => $timestamp,
                    ];

                    if ($existing) {
                        DB::table('games')->where('id', $existing->id)->update($values);
                    } else {
                        DB::table('games')->insert([
                            'steam_appid' => $appid,
                            'release_status' => 'unknown',
                            'release_date' => null,
                            'created_at' => $timestamp,
                            ...$values,
                        ]);
                    }
                }
            });
    }

    private function backfillFavoriteLinks(): void
    {
        DB::table('favorites')->orderBy('id')->chunkById(500, function ($favorites) {
            $gameIds = DB::table('games')
                ->whereIn('steam_appid', $favorites->pluck('appid')->map(fn ($appid) => (int) $appid)->all())
                ->pluck('id', 'steam_appid');

            foreach ($favorites as $favorite) {
                $gameId = $gameIds->get((int) $favorite->appid);
                if ($gameId !== null) {
                    DB::table('favorites')->where('id', $favorite->id)->update(['game_id' => $gameId]);
                }
            }
        });
    }

    private function backfillSteamPricesFromFavorites(): void
    {
        DB::table('favorites')
            ->whereNotNull('game_id')
            ->whereNotNull('last_steam_price_rub')
            ->orderBy('id')
            ->chunkById(500, function ($favorites) {
                foreach ($favorites as $favorite) {
                    $this->upsertSteamPrice(
                        (int) $favorite->game_id,
                        $favorite->last_steam_price_rub,
                        $favorite->updated_at ?? now()
                    );
                }
            });
    }

    private function backfillSteamPricesFromSnapshots(): void
    {
        if (! Schema::hasTable('price_snapshots')) {
            return;
        }

        DB::table('price_snapshots')
            ->whereNotNull('appid')
            ->whereNotNull('steam_price_rub')
            ->orderBy('id')
            ->chunkById(500, function ($snapshots) {
                foreach ($snapshots as $snapshot) {
                    $appid = (int) $snapshot->appid;
                    if ($appid < 1) {
                        continue;
                    }

                    $gameId = $this->gameIdForAppid($appid, $snapshot->created_at ?? now());
                    $this->upsertSteamPrice(
                        $gameId,
                        $snapshot->steam_price_rub,
                        $snapshot->created_at ?? now()
                    );
                }
            });
    }

    private function gameIdForAppid(int $appid, mixed $timestamp): int
    {
        $gameId = DB::table('games')->where('steam_appid', $appid)->value('id');
        if ($gameId !== null) {
            return (int) $gameId;
        }

        return (int) DB::table('games')->insertGetId([
            'steam_appid' => $appid,
            'name' => "Steam app {$appid}",
            'header_image' => null,
            'release_status' => 'unknown',
            'release_date' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function upsertSteamPrice(int $gameId, mixed $price, mixed $observedAt): void
    {
        if (! is_numeric($price) || (float) $price < 0) {
            return;
        }

        $key = [
            'game_id' => $gameId,
            'source' => 'steam',
            'offer_kind' => 'official',
        ];
        $observedAt = CarbonImmutable::parse($observedAt);
        $existing = DB::table('current_game_prices')->where($key)->first();

        if ($existing && CarbonImmutable::parse($existing->observed_at)->isAfter($observedAt)) {
            return;
        }

        $values = [
            'min_price_rub' => $price,
            'avg_price_rub' => null,
            'offer_count' => 1,
            'cheapest_offer_title' => null,
            'cheapest_offer_url' => null,
            'popular_offer_title' => null,
            'popular_offer_url' => null,
            'popular_offer_price_rub' => null,
            'popular_offer_sales' => null,
            'observed_at' => $observedAt,
            'updated_at' => $observedAt,
        ];

        if ($existing) {
            DB::table('current_game_prices')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('current_game_prices')->insert([
            ...$key,
            ...$values,
            'created_at' => $observedAt,
        ]);
    }

    private function createBackfilledSteamHistory(): void
    {
        DB::table('current_game_prices')
            ->where('source', 'steam')
            ->where('offer_kind', 'official')
            ->orderBy('id')
            ->chunkById(500, function ($prices) {
                foreach ($prices as $price) {
                    DB::table('game_price_observations')->updateOrInsert(
                        [
                            'game_id' => $price->game_id,
                            'source' => 'steam',
                            'offer_kind' => 'official',
                            'observed_at' => $price->observed_at,
                        ],
                        [
                            'min_price_rub' => $price->min_price_rub,
                            'offer_title' => null,
                            'offer_url' => null,
                            'offer_sales' => null,
                            'created_at' => $price->observed_at,
                            'updated_at' => $price->observed_at,
                        ]
                    );

                    DB::table('game_source_states')->updateOrInsert(
                        [
                            'game_id' => $price->game_id,
                            'source' => 'steam',
                        ],
                        [
                            'last_attempt_at' => $price->observed_at,
                            'last_success_at' => $price->observed_at,
                            'next_refresh_at' => now(),
                            'status' => 'stale',
                            'last_error' => null,
                            'created_at' => $price->observed_at,
                            'updated_at' => now(),
                        ]
                    );
                }
            });
    }
};

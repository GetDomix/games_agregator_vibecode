<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Services\Pricing\GgselService;
use App\Services\Pricing\PlatiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceCatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_plati_reuses_cached_id_cb_without_suggest(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 1145360,
            'name' => 'Hades',
            'plati_id_cb' => 948,
            'plati_catalog_name' => 'Hades',
            'plati_catalog_resolved_at' => now(),
        ]);

        Http::fake([
            'https://plati.market/api/suggest.ashx*' => Http::response([], 500),
            'https://plati.market/asp/block_goods_category_2.asp*' => Http::response(
                '1|100|100|<a href="/itm/hades/1" title="Hades Steam Key" product_id="1">'
                .'<span class="title-bold color-text-title">100&nbsp;₽</span></a>'
            ),
        ]);

        [$offers, , $err] = app(PlatiService::class)->searchForGame($game);
        $this->assertNull($err);
        $this->assertCount(1, $offers);
        $this->assertSame(100.0, $offers[0]['price_rub']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'suggest.ashx'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'block_goods_category_2.asp')
            && ($request['id_cb'] ?? null) == 948);
    }

    public function test_plati_negative_card_cache_skips_suggest_but_keeps_full_title_fallback(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 3215190,
            'name' => 'Backrooms: Found Footage',
            'plati_id_cb' => null,
            'plati_catalog_resolved_at' => now(),
        ]);

        Http::fake([
            'https://plati.market/api/suggest.ashx*' => Http::response([
                ['name' => 'Hades', 'type' => 'Игры', 'link' => '/games/hades/948/'],
            ]),
            'https://plati.market/api/search.ashx*' => Http::response([
                'Pagenum' => 1,
                'Pagesize' => 100,
                'Totalpages' => 1,
                'items' => [],
            ]),
        ]);

        [$offers, , $err] = app(PlatiService::class)->searchForGame($game);
        $this->assertNull($err);
        $this->assertSame([], $offers);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'suggest.ashx'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'search.ashx'));
    }

    public function test_plati_retries_an_old_negative_cache_and_accepts_an_expanded_catalog_alias(): void
    {
        config()->set('gpa.catalog_negative_ttl_hours', 1);
        $game = Game::query()->create([
            'steam_appid' => 730,
            'name' => 'Counter-Strike 2',
            'plati_catalog_resolved_at' => now()->subHours(2),
        ]);

        Http::fake([
            'https://plati.market/api/suggest.ashx*' => Http::response([
                ['name' => 'Counter-Strike: Global Offensive (CS 2)', 'type' => 'Игры', 'link' => '/games/counter-strike-global-offensive-cs-2/277/'],
            ]),
            'https://plati.market/asp/block_goods_category_2.asp*' => Http::response(
                '1|1275|1275|<a href="/itm/cs2-prime/2" title="Counter-Strike 2 Prime Status" product_id="2">'
                .'<span class="title-bold color-text-title">1&nbsp;275&nbsp;₽</span></a>'
            ),
        ]);

        [$offers, , $error] = app(PlatiService::class)->searchForGame($game);

        $this->assertNull($error);
        $this->assertCount(1, $offers);
        $this->assertSame(1275.0, $offers[0]['price_rub']);
        $this->assertSame(277, $game->fresh()->plati_id_cb);
    }

    public function test_plati_falls_back_to_relevant_full_title_lots_when_catalog_has_no_game_card(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 2141730,
            'name' => 'Backrooms: Escape Together',
        ]);

        Http::fake([
            'https://plati.market/api/suggest.ashx*' => Http::response([
                [
                    'name' => 'Backrooms Escape together',
                    'type' => 'Товары',
                    'link' => '/search/Backrooms Escape together',
                ],
            ]),
            'https://plati.market/api/search.ashx*' => Http::response([
                'Pagenum' => 1,
                'Pagesize' => 100,
                'Totalpages' => 1,
                'items' => [
                    [
                        'id' => 3509590,
                        'name' => 'Backrooms: Escape Together - STEAM GIFT RU',
                        'price_rur' => 458,
                        'price_usd' => 5.70,
                        'price_eur' => 4.90,
                        'url' => 'https://plati.market/itm/3509590',
                        'numsold' => 544,
                    ],
                    [
                        'id' => 3566760,
                        'name' => 'Backrooms: Escape Together STEAM Аккаунт',
                        'price_rur' => 100,
                        'url' => 'https://plati.market/itm/3566760',
                        'numsold' => 47,
                    ],
                    [
                        'id' => 3221234,
                        'name' => 'Escape the Backrooms STEAM',
                        'price_rur' => 99,
                        'url' => 'https://plati.market/itm/3221234',
                        'numsold' => 999,
                    ],
                ],
            ]),
        ]);

        [$offers, $total, $error] = app(PlatiService::class)->searchForGame($game);

        $this->assertNull($error);
        $this->assertSame(2, $total);
        $this->assertCount(2, $offers);
        $this->assertSame(
            ['Backrooms: Escape Together - STEAM GIFT RU', 'Backrooms: Escape Together STEAM Аккаунт'],
            array_column($offers, 'title')
        );
        $this->assertNull($game->fresh()->plati_id_cb);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'search.ashx'));
    }

    public function test_plati_empty_lots_with_cached_id_forces_re_resolve(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 99,
            'name' => 'Hades',
            'plati_id_cb' => 111,
            'plati_catalog_name' => 'Hades',
            'plati_catalog_resolved_at' => now(),
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'suggest.ashx')) {
                return Http::response([
                    ['name' => 'Hades', 'type' => 'Игры', 'link' => '/games/hades/948/'],
                ]);
            }
            if (str_contains($url, 'block_goods_category_2.asp')) {
                $id = (int) ($request['id_cb'] ?? 0);
                if ($id === 111) {
                    return Http::response('0|0|0|');
                }

                return Http::response(
                    '1|200|200|<a href="/itm/hades/2" title="Hades Gift" product_id="2">'
                    .'<span class="title-bold color-text-title">200&nbsp;₽</span></a>'
                );
            }

            return Http::response('not found', 404);
        });

        [$offers] = app(PlatiService::class)->searchForGame($game);
        $this->assertCount(1, $offers);
        $this->assertSame(200.0, $offers[0]['price_rub']);
        $game->refresh();
        $this->assertSame(948, $game->plati_id_cb);
    }

    public function test_ggsel_persists_category_on_resolve(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 1145360,
            'name' => 'Hades',
        ]);

        Http::fake([
            'https://api.ggsel.com/elastic/goods/query-categories' => Http::response([
                'data' => [
                    ['name' => 'Hades II', 'url' => 'hades-ii'],
                    ['name' => 'Hades', 'url' => 'hades-13346'],
                ],
            ]),
            'https://api.ggsel.com/categories/hades-13346' => Http::response([
                'data' => [
                    'id' => 13346,
                    'name' => 'Hades',
                    'url' => 'hades-13346',
                    'digi_catalog' => 40910,
                    'is_base_game' => true,
                ],
            ]),
            'https://api.ggsel.com/elastic/goods/categories' => Http::response([
                'data' => [
                    'total' => 1,
                    'items' => [[
                        'id_goods' => 1,
                        // The card id is authoritative; a seller title need not repeat the game name.
                        'name' => 'Автодоставка Steam Gift',
                        'search_title' => 'Россия и СНГ',
                        'price_wmr' => 150,
                        'cnt_sell' => 3,
                        'is_active' => true,
                    ]],
                ],
            ]),
        ]);

        [$offers] = app(GgselService::class)->searchForGame($game);
        $this->assertCount(1, $offers);
        $game->refresh();
        $this->assertSame('Hades', $game->ggsel_category_name);
        $this->assertSame('hades-13346', $game->ggsel_category_slug);
        $this->assertSame(40910, $game->ggsel_digi_catalog_id);
        $this->assertNotNull($game->ggsel_catalog_resolved_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'elastic/goods/categories')
            && ($request['digi_catalog'] ?? null) === 40910);
    }

    public function test_ggsel_reuses_cached_catalog_id_without_text_search(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 1245620,
            'name' => 'ELDEN RING',
            'ggsel_digi_catalog_id' => 55555,
            'ggsel_category_slug' => 'elden-ring',
            'ggsel_category_name' => 'ELDEN RING',
            'ggsel_catalog_resolved_at' => now(),
        ]);

        Http::fake([
            'https://api.ggsel.com/elastic/goods/categories' => Http::response([
                'data' => [
                    'total' => 1,
                    'items' => [[
                        'id_goods' => 9,
                        'name' => 'Steam ключ',
                        'price_wmr' => 999,
                        'is_active' => true,
                    ]],
                ],
            ]),
        ]);

        [$offers, $total, $error] = app(GgselService::class)->searchForGame($game);

        $this->assertNull($error);
        $this->assertSame(1, $total);
        $this->assertCount(1, $offers);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'query-categories')
            || str_contains($request->url(), '/categories/elden-ring'));
    }

    public function test_ggsel_resolves_equivalent_platform_title_then_fetches_by_card_id(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 271590,
            'name' => 'Grand Theft Auto V',
        ]);

        Http::fake([
            'https://api.ggsel.com/elastic/goods/query-categories' => Http::response([
                'data' => [
                    ['name' => 'Grand Theft Auto 5 ключи Social Club', 'url' => 'grand-theft-auto-v-3'],
                    ['name' => 'Grand Theft Auto 6', 'url' => 'grand-theft-auto-6'],
                    ['name' => 'Grand Theft Auto 5', 'url' => 'grand-theft-auto-v-gta-5'],
                ],
            ]),
            'https://api.ggsel.com/categories/grand-theft-auto-v-gta-5' => Http::response([
                'data' => [
                    'id' => 9902,
                    'name' => 'Grand Theft Auto V (GTA 5)',
                    'url' => 'grand-theft-auto-v-gta-5',
                    'digi_catalog' => 34750,
                    'is_base_game' => true,
                ],
            ]),
            'https://api.ggsel.com/elastic/goods/categories' => Http::response([
                'data' => [
                    'total' => 1,
                    'items' => [[
                        'id_goods' => 10,
                        'name' => 'Premium Online Edition',
                        'price_wmr' => 1200,
                        'is_active' => true,
                    ]],
                ],
            ]),
        ]);

        [$offers, , $error] = app(GgselService::class)->searchForGame($game);

        $this->assertNull($error);
        $this->assertCount(1, $offers);
        $this->assertSame(34750, $game->fresh()->ggsel_digi_catalog_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'elastic/goods/categories')
            && ($request['digi_catalog'] ?? null) === 34750
            && ! array_key_exists('search_term', $request->data()));
    }

    public function test_ggsel_category_metadata_tolerates_an_upstream_control_byte(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 2141730,
            'name' => 'Backrooms: Escape Together',
        ]);

        Http::fake([
            'https://api.ggsel.com/elastic/goods/query-categories' => Http::response([
                'data' => [[
                    'name' => 'Backrooms: Escape Together',
                    'url' => 'backrooms-escape-together',
                ]],
            ]),
            'https://api.ggsel.com/categories/backrooms-escape-together' => Http::response(
                "{\"data\":{\"id\":55966,\"name\":\"Backrooms: Escape Together\",\"description\":\"bad\x01byte\",\"url\":\"backrooms-escape-together\",\"digi_catalog\":81270}}",
                200,
                ['Content-Type' => 'application/json']
            ),
            'https://api.ggsel.com/elastic/goods/categories' => Http::response([
                'data' => [
                    'total' => 1,
                    'items' => [[
                        'id_goods' => 11,
                        'name' => 'Steam Gift',
                        'price_wmr' => 394,
                    ]],
                ],
            ]),
        ]);

        [$offers, , $error] = app(GgselService::class)->searchForGame($game);

        $this->assertNull($error);
        $this->assertCount(1, $offers);
        $this->assertSame(81270, $game->fresh()->ggsel_digi_catalog_id);
    }
}

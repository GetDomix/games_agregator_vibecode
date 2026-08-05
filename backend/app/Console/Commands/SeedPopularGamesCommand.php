<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\GameRefreshRequestService;
use Illuminate\Console\Command;

/**
 * Наполняет локальную базу реальными популярными играми Steam.
 *
 * Ничего не выдумываем: создаётся запись Game (с детерминированной обложкой
 * из CDN Steam), а реальные цены догружает существующий конвейер
 * RefreshGameSourceJob через очередь `prices` (её обслуживает воркер compose).
 */
class SeedPopularGamesCommand extends Command
{
    protected $signature = 'games:seed-popular
        {--sources=steam,plati,ggsel : Источники цен для обновления}
        {--limit=50 : Сколько игр из списка сидить}';

    protected $description = 'Seed popular Steam games and dispatch real price refresh jobs';

    /** Популярное в Steam (appid => название). Проверенные публичные appid. */
    private const POPULAR = [
        1086940 => 'Baldur\'s Gate 3',
        1245620 => 'ELDEN RING',
        1091500 => 'Cyberpunk 2077',
        292030 => 'The Witcher 3: Wild Hunt',
        1174180 => 'Red Dead Redemption 2',
        271590 => 'Grand Theft Auto V',
        489830 => 'The Elder Scrolls V: Skyrim Special Edition',
        377160 => 'Fallout 4',
        435150 => 'Divinity: Original Sin 2',
        620 => 'Portal 2',
        105600 => 'Terraria',
        367520 => 'Hollow Knight',
        413150 => 'Stardew Valley',
        391540 => 'Undertale',
        268910 => 'Cuphead',
        646570 => 'Slay the Spire',
        960090 => 'Bloons TD 6',
        945360 => 'Among Us',
        381210 => 'Dead by Daylight',
        578080 => 'PUBG: BATTLEGROUNDS',
        252490 => 'Rust',
        1097150 => 'Fall Guys',
        322330 => 'Don\'t Starve Together',
        108600 => 'Project Zomboid',
        227300 => 'Euro Truck Simulator 2',
        275850 => 'No Man\'s Sky',
        289070 => 'Sid Meier\'s Civilization VI',
        1158310 => 'Crusader Kings III',
        1243835 => 'Overcooked! 2',
        1293830 => 'Forza Horizon 4',
        261550 => 'Mount & Blade II: Bannerlord',
        1940340 => 'Darkest Dungeon II',
        1551360 => 'Forager',
        1623730 => 'Palworld',
        2050650 => 'Resident Evil 4',
        990080 => 'Hogwarts Legacy',
        1172620 => 'Sea of Thieves',
        1326470 => 'Sons Of The Forest',
        594650 => 'Hunt: Showdown',
        359550 => 'Tom Clancy\'s Rainbow Six Siege',
        374320 => 'DARK SOULS III',
        548430 => 'DARK SOULS: REMASTERED',
        238960 => 'Path of Exile',
        266410 => 'Anno 1800',
        1145360 => 'Hades',
    ];

    public function handle(GameRefreshRequestService $refresh): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sources = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('sources')))));
        if ($sources === []) {
            $sources = ['steam'];
        }

        $picked = array_slice(self::POPULAR, 0, $limit, preserve_keys: true);
        $created = 0;

        foreach ($picked as $appid => $name) {
            $game = Game::query()->firstOrCreate(
                ['steam_appid' => $appid],
                [
                    'name' => $name,
                    // Детерминированный реальный URL обложки из CDN Steam.
                    'header_image' => "https://cdn.akamai.steamstatic.com/steam/apps/{$appid}/header.jpg",
                ]
            );
            if ($game->wasRecentlyCreated) {
                $created++;
            }
            $refresh->request($game, $sources);
            $this->line("  {$appid} → {$name} [".implode('+', $sources).']');
        }

        $this->info('Готово: обработано '.count($picked).' игр, создано новых '.$created.'.');
        $this->comment('Цены догружает воркер очереди `prices` (docker compose worker).');

        return self::SUCCESS;
    }
}

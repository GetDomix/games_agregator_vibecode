<?php

namespace App\Services\Alerts;

use App\Models\AlertEvent;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use Illuminate\Support\Facades\DB;

class FavoriteAlertSettingsService
{
    public function assertValid(array $data): void
    {
        $condition = $data['condition_type'] ?? 'target_price';
        if (! in_array($condition, ['target_price', 'discount_percent', 'new_low'], true)) {
            throw new \InvalidArgumentException('Некорректное условие алерта');
        }
        if ($condition === 'target_price' && (! array_key_exists('target_value', $data)
            || ! is_numeric($data['target_value']) || (float) $data['target_value'] <= 0)) {
            throw new \InvalidArgumentException('Некорректная целевая цена');
        }

        if ($condition === 'discount_percent'
            && (! isset($data['target_value']) || filter_var($data['target_value'], FILTER_VALIDATE_INT) === false
                || (int) $data['target_value'] < 1 || (int) $data['target_value'] > 100)) {
            throw new \InvalidArgumentException('Скидка должна быть целым числом от 1 до 100');
        }
        if ($condition === 'new_low' && (($data['target_value'] ?? null) !== null)) {
            throw new \InvalidArgumentException('Для нового минимума целевая цена не задаётся');
        }

        $scopes = $data['scopes'] ?? [['source' => 'steam', 'offer_kind' => 'official']];
        if (! is_array($scopes) || $scopes === []) {
            throw new \InvalidArgumentException('Выберите хотя бы одну площадку');
        }

        $seen = [];
        foreach ($scopes as $scope) {
            $source = $scope['source'] ?? null;
            $offerKind = $scope['offer_kind'] ?? null;
            if (! in_array($source, ['steam', 'plati', 'ggsel'], true)
                || ($source === 'steam' && $offerKind !== 'official')
                || ($source !== 'steam' && ! in_array($offerKind, ['key', 'gift', 'account', 'rent'], true))) {
                throw new \InvalidArgumentException('Некорректная площадка или вид предложения');
            }
            $key = $source.':'.$offerKind;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Площадка и вид предложения не должны повторяться');
            }
            $seen[$key] = true;
        }
        if ($condition === 'discount_percent'
            && (count($scopes) !== 1 || ($scopes[0]['source'] ?? null) !== 'steam' || ($scopes[0]['offer_kind'] ?? null) !== 'official')) {
            throw new \InvalidArgumentException('Скидка Steam доступна только для официальной цены Steam');
        }
    }

    public function save(Favorite $favorite, array $data): FavoriteAlert
    {
        $this->assertValid($data);
        $scopes = $data['scopes'] ?? [['source' => 'steam', 'offer_kind' => 'official']];
        $normalizedScopes = $this->normalizedScopes($scopes);
        $condition = $data['condition_type'] ?? 'target_price';
        $target = $condition === 'new_low' ? null : ($data['target_value'] ?? null);

        return DB::transaction(function () use ($favorite, $scopes, $normalizedScopes, $condition, $target): FavoriteAlert {
            // Global write order: favorite -> alert -> scopes/events. It matches
            // Telegram account merge and avoids alert->favorite lock inversion.
            $favorite = Favorite::query()->lockForUpdate()->findOrFail($favorite->id);
            $alert = FavoriteAlert::query()->where('favorite_id', $favorite->id)->lockForUpdate()->firstOrNew(['favorite_id' => $favorite->id]);
            $isNew = ! $alert->exists;
            $existingScopes = $alert->exists
                ? $this->normalizedScopes($alert->scopes()->get()->map(fn ($scope) => ['source' => $scope->source, 'offer_kind' => $scope->offer_kind])->all())
                : [];
            $changed = $alert->exists && ($alert->condition_type !== $condition
                || (($alert->target_value === null) !== ($target === null))
                || ($target !== null && (float) $alert->target_value !== (float) $target)
                || $existingScopes !== $normalizedScopes);
            $alert->fill(['condition_type' => $condition, 'target_value' => $target]);
            if ($changed) {
                $alert->status = 'active';
                $alert->cycle++;
                $alert->triggered_at = null;
            }
            $alert->save();
            if ($isNew || $existingScopes !== $normalizedScopes) {
                $alert->scopes()->delete();
                foreach ($scopes as $scope) {
                    $alert->scopes()->create($scope);
                }
            }

            // Keep the legacy column as a compatibility projection only. Percent
            // and new-low conditions must never leak into a RUB target field.
            $favorite->forceFill(['target_price_rub' => $condition === 'target_price' ? (float) $target : null])->save();

            return $alert->load('scopes');
        });
    }

    public function rearm(Favorite $favorite): FavoriteAlert
    {
        return DB::transaction(function () use ($favorite): FavoriteAlert {
            $favorite = Favorite::query()->lockForUpdate()->findOrFail($favorite->id);
            $alert = FavoriteAlert::query()->where('favorite_id', $favorite->id)->lockForUpdate()->firstOrFail();
            $alert->update(['status' => 'active', 'cycle' => $alert->cycle + 1, 'triggered_at' => null]);

            return $alert->load('scopes');
        });
    }

    public function remove(Favorite $favorite): void
    {
        DB::transaction(function () use ($favorite): void {
            $favorite = Favorite::query()->lockForUpdate()->findOrFail($favorite->id);
            $alert = FavoriteAlert::query()->where('favorite_id', $favorite->id)->lockForUpdate()->first();
            if ($alert) {
                AlertEvent::query()->where('favorite_alert_id', $alert->id)->lockForUpdate()->get();
                $alert->scopes()->delete();
                $alert->delete();
            }
            $favorite->forceFill(['target_price_rub' => null])->save();
        });
    }

    private function normalizedScopes(array $scopes): array
    {
        $scopes = array_map(fn ($scope) => [
            'source' => (string) $scope['source'],
            'offer_kind' => (string) $scope['offer_kind'],
        ], $scopes);
        usort($scopes, fn (array $a, array $b) => [$a['source'], $a['offer_kind']] <=> [$b['source'], $b['offer_kind']]);

        return $scopes;
    }
}

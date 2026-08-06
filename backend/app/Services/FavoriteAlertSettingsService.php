<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\Favorite;
use App\Models\FavoriteAlert;

class FavoriteAlertSettingsService
{
    public function save(Favorite $favorite, array $data): FavoriteAlert
    {
        $scopes = $data['scopes'] ?? [['source' => 'steam', 'offer_kind' => 'official']];
        foreach ($scopes as $scope) {
            if (! in_array($scope['source'], ['steam', 'plati', 'ggsel'], true) || ($scope['source'] === 'steam' && $scope['offer_kind'] !== 'official') || ($scope['source'] !== 'steam' && ! in_array($scope['offer_kind'], ['key', 'gift', 'account', 'rent'], true))) {
                throw new \InvalidArgumentException('Некорректная площадка или вид предложения');
            }
        }
        $alert = $favorite->alert()->firstOrNew();
        $changed = $alert->exists
            && array_key_exists('target_value', $data)
            && (($alert->target_value === null) !== ($data['target_value'] === null)
                || (float) $alert->target_value !== (float) $data['target_value']);
        $alert->fill(['condition_type' => $data['condition_type'] ?? 'target_price', 'target_value' => $data['target_value'] ?? null]);
        if ($changed) {
            $alert->status = 'active';
            $alert->cycle++;
            $alert->triggered_at = null;
        } $alert->save();
        $alert->scopes()->delete();
        foreach ($scopes as $scope) {
            $alert->scopes()->create($scope);
        }

        return $alert->load('scopes');
    }

    public function rearm(Favorite $favorite): FavoriteAlert
    {
        $alert = $favorite->alert()->firstOrFail();
        $alert->update(['status' => 'active', 'cycle' => $alert->cycle + 1, 'triggered_at' => null]);

        return $alert->load('scopes');
    }

    public function remove(Favorite $favorite): void
    {
        $alert = $favorite->alert()->first();
        if (! $alert) {
            return;
        }
        AlertEvent::query()->where('favorite_alert_id', $alert->id)->delete();
        $alert->scopes()->delete();
        $alert->delete();
    }
}

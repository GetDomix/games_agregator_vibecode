<?php

namespace App\Services\Catalog;

/**
 * A deliberately conservative, local-only label for disambiguating Steam names.
 * It is advisory: it must not influence selection or cause another lookup.
 */
class GameCandidateKindClassifier
{
    public function classify(string $name): string
    {
        $name = mb_strtolower($name);

        return match (true) {
            preg_match('/\bdemo\b|демо/u', $name) === 1 => 'demo',
            preg_match('/soundtrack|саундтрек/u', $name) === 1 => 'soundtrack',
            preg_match('/\bdlc\b|add[ -]?on|дополнени/u', $name) === 1 => 'dlc',
            preg_match('/remaster|ремастер/u', $name) === 1 => 'remaster',
            preg_match('/\bedition\b|издани/u', $name) === 1 => 'edition',
            default => 'game',
        };
    }
}

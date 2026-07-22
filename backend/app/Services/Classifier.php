<?php

namespace App\Services;

class Classifier
{
    private const GGSEL_MAP = [
        1 => 'account',
        25 => 'rent',
        29 => 'account',
        31 => 'account',
        48 => 'gift',
        56 => 'gift',
        2 => 'key',
        30 => 'key',
        54 => 'key',
    ];

    public static function fromText(string $name, string ...$extra): string
    {
        $text = mb_strtolower(trim(implode(' ', array_filter([$name, ...$extra]))));
        if ($text === '') {
            return 'other';
        }
        if (preg_match('/(аренд\w*|rent(?:al|s)?|lease)/iu', $text)) {
            return 'rent';
        }
        if (preg_match('/(гифт\w*|gift\w*|подар\w*)/iu', $text)) {
            return 'gift';
        }
        if (preg_match('/(акк(?:аунт)?\w*|account\w*|оффлайн|offline|shared)/iu', $text)) {
            return 'account';
        }
        if (preg_match('/(ключ\w*|keys?|cd[\s-]?keys?|steam\s*keys?|gog\s*keys?|лиценз\w*)/iu', $text)) {
            return 'key';
        }

        return 'other';
    }

    public static function ggsel(?int $contentTypeId, string $name, string $searchTitle = ''): string
    {
        if ($contentTypeId !== null && isset(self::GGSEL_MAP[$contentTypeId])) {
            return self::GGSEL_MAP[$contentTypeId];
        }

        return self::fromText($name, $searchTitle);
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'account' => 'Аккаунт',
            'gift' => 'Гифт',
            'rent' => 'Аренда',
            'key' => 'Ключ',
            default => 'Другое',
        };
    }
}

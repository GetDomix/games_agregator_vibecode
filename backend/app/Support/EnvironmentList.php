<?php

namespace App\Support;

final class EnvironmentList
{
    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    public static function parse(?string $value, array $fallback = []): array
    {
        $items = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value ?? '')),
            static fn (string $item): bool => $item !== '',
        )));

        return $items === [] ? $fallback : $items;
    }
}

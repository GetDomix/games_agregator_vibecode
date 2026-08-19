<?php

namespace App\Services\Catalog;

final class SearchCandidateRanker
{
    public function rank(array $candidates, string $query, int $limit): array
    {
        $query = $this->normalize($query);
        if ($query === '') {
            return array_slice($candidates, 0, $limit);
        }

        $ranked = [];
        foreach (array_values($candidates) as $index => $candidate) {
            $name = $this->normalize((string) ($candidate['name'] ?? ''));
            $ranked[] = [
                'candidate' => $candidate,
                'relevance' => $this->relevance($name, $query),
                'kind' => $this->kindPenalty((string) ($candidate['candidate_kind'] ?? 'game')),
                'index' => $index,
            ];
        }

        usort($ranked, static fn (array $left, array $right): int => [
            $left['relevance'],
            $left['kind'],
            $left['index'],
        ] <=> [
            $right['relevance'],
            $right['kind'],
            $right['index'],
        ]);

        return array_slice(array_column($ranked, 'candidate'), 0, $limit);
    }

    private function relevance(string $name, string $query): int
    {
        if ($name === $query) {
            return 0;
        }
        if (str_starts_with($name, $query.' ')) {
            return 1;
        }
        if (str_contains(' '.$name.' ', ' '.$query.' ')) {
            return 2;
        }

        $nameTokens = array_flip(explode(' ', $name));
        foreach (explode(' ', $query) as $token) {
            if (! isset($nameTokens[$token])) {
                return 4;
            }
        }

        return 3;
    }

    private function kindPenalty(string $kind): int
    {
        return match ($kind) {
            'game' => 0,
            'edition', 'remaster' => 1,
            'dlc' => 2,
            'demo' => 3,
            'soundtrack' => 4,
            default => 5,
        };
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

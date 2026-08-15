<?php

namespace Tests\Unit;

use App\Support\EnvironmentList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnvironmentListTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: array<int, string>, 2: array<int, string>}>
     */
    public static function values(): array
    {
        return [
            'missing value uses fallback' => [null, ['127.0.0.1', '::1'], ['127.0.0.1', '::1']],
            'blank value uses fallback' => ['  , ', ['127.0.0.1'], ['127.0.0.1']],
            'values are trimmed deduplicated and ordered' => [
                ' https://games.example ,https://admin.example, https://games.example ,,',
                ['*'],
                ['https://games.example', 'https://admin.example'],
            ],
            'explicit wildcard remains explicit' => ['*', ['127.0.0.1'], ['*']],
        ];
    }

    /**
     * @param  array<int, string>  $fallback
     * @param  array<int, string>  $expected
     */
    #[DataProvider('values')]
    public function test_it_parses_comma_separated_security_values(
        ?string $value,
        array $fallback,
        array $expected,
    ): void {
        $this->assertSame($expected, EnvironmentList::parse($value, $fallback));
    }
}

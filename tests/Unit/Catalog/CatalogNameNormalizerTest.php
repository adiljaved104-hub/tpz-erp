<?php

namespace Tests\Unit\Catalog;

use App\Services\CatalogNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogNameNormalizerTest extends TestCase
{
    #[DataProvider('variants')]
    public function test_it_normalizes_case_and_unicode_whitespace(string $value, string $display, string $normalized): void
    {
        $names = new CatalogNameNormalizer;
        $this->assertSame($display, $names->display($value));
        $this->assertSame($normalized, $names->normalize($value));
    }

    public static function variants(): array
    {
        return [
            [' HP ', 'HP', 'hp'], ['Hp', 'Hp', 'hp'], ["\u{00A0}HP\u{00A0}", 'HP', 'hp'],
            ['All  in   One', 'All in One', 'all in one'], ['Hewlett-Packard', 'Hewlett-Packard', 'hewlett-packard'],
        ];
    }
}

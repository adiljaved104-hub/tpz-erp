<?php

namespace Tests\Unit\ProductIntelligence;

use App\Contracts\ProductQueryInterpreterInterface;
use App\Enums\ProductMatchContext;
use App\Services\ProductIntelligence\ProductMatchScorer;
use App\Services\ProductIntelligence\ProductQueryNormalizer;
use Tests\TestCase;

class ProductQueryParserTest extends TestCase
{
    public function test_safe_aliases_normalize_consistently(): void
    {
        $normalizer = app(ProductQueryNormalizer::class);

        $this->assertSame('core ultra 5 155u', $normalizer->normalize('Intel Core Ultra5-155U'));
        $this->assertSame('core ultra 5 155u', $normalizer->normalize('U5 155U'));
        $this->assertSame('rtx 5060', $normalizer->normalize('GeForce RTX5060'));
        $this->assertSame('16 gb', $normalizer->normalize('16G'));
    }

    public function test_parser_extracts_structured_laptop_specs_without_inventing_missing_values(): void
    {
        $parsed = app(ProductQueryInterpreterInterface::class)->interpret(
            'Lenovo Ultra 5 155U RTX 5060 16GB 512GB NVMe',
            knownBrands: ['Lenovo'],
        );

        $this->assertSame('lenovo', $parsed->brand);
        $this->assertSame('core ultra 5', $parsed->cpuFamily);
        $this->assertSame('155U', $parsed->cpuModel);
        $this->assertSame('NVIDIA', strtoupper((string) $parsed->gpuVendor));
        $this->assertSame('RTX 5060', $parsed->gpuModel);
        $this->assertSame(16384, $parsed->ramMb);
        $this->assertSame(512, $parsed->storageGb);
        $this->assertSame('nvme', $parsed->storageType);
        $this->assertNull($parsed->screenSizeInches);
    }

    public function test_storage_and_ram_shorthand_are_equivalent(): void
    {
        $interpreter = app(ProductQueryInterpreterInterface::class);
        $shorthand = $interpreter->interpret('Lenovo T14 155U 32/1TB', knownBrands: ['Lenovo']);
        $explicit = $interpreter->interpret('Lenovo T14 155U 32GB 1024GB', knownBrands: ['Lenovo']);

        $this->assertSame(32768, $shorthand->ramMb);
        $this->assertSame(1024, $shorthand->storageGb);
        $this->assertSame($explicit->ramMb, $shorthand->ramMb);
        $this->assertSame($explicit->storageGb, $shorthand->storageGb);
    }

    public function test_structured_values_override_ambiguous_title_text(): void
    {
        $parsed = app(ProductQueryInterpreterInterface::class)->interpret('New laptop', [
            'brand' => 'HP',
            'model' => '840 G8',
            'processor' => 'Intel Core i5-1135G7',
            'ram' => '16 GB',
            'storage' => '512 GB SSD',
            'graphics' => 'Intel Iris Xe',
        ], ['HP']);

        $this->assertSame('hp', $parsed->brand);
        $this->assertSame('840G8', $parsed->model);
        $this->assertSame('1135G7', $parsed->cpuModel);
        $this->assertSame(16384, $parsed->ramMb);
        $this->assertSame(512, $parsed->storageGb);
    }

    public function test_hard_gpu_and_cpu_conflicts_are_explicit_and_never_exact(): void
    {
        $interpreter = app(ProductQueryInterpreterInterface::class);
        $searched = $interpreter->interpret('Lenovo Ultra 7 155U RTX 5060 16 512', knownBrands: ['Lenovo']);
        $candidate = $interpreter->interpret('Lenovo laptop', [
            'brand' => 'Lenovo', 'processor' => 'Core Ultra 7 155H', 'graphics' => 'RTX 4060', 'ram' => '16GB', 'storage' => '512GB',
        ], ['Lenovo']);

        $score = app(ProductMatchScorer::class)->score($searched, $candidate, ProductMatchContext::Order);
        $messages = collect($score['reasons'])->pluck('message')->implode(' ');

        $this->assertStringContainsString('CPU differs', $messages);
        $this->assertStringContainsString('155U', $messages);
        $this->assertStringContainsString('155H', $messages);
        $this->assertStringContainsString('GPU differs', $messages);
        $this->assertStringContainsString('RTX 5060', $messages);
        $this->assertStringContainsString('RTX 4060', $messages);
        $this->assertNotSame('exact', $score['classification']->value);
    }

    public function test_typo_similarity_is_only_a_supporting_signal(): void
    {
        $interpreter = app(ProductQueryInterpreterInterface::class);
        $searched = $interpreter->interpret('lenvo 155u 5060');
        $candidate = $interpreter->interpret('Lenovo laptop', ['brand' => 'Lenovo', 'processor' => 'Ultra 5 155U', 'graphics' => 'RTX 5060'], ['Lenovo']);

        $score = app(ProductMatchScorer::class)->score($searched, $candidate, ProductMatchContext::Order);

        $this->assertGreaterThanOrEqual(60, $score['score']);
        $this->assertFalse(collect($score['reasons'])->contains(fn ($reason): bool => $reason->field === 'brand' && $reason->status === 'match'));
    }

    public function test_common_catalog_shorthand_parses_without_requiring_formal_titles(): void
    {
        $interpreter = app(ProductQueryInterpreterInterface::class);
        $hp = $interpreter->interpret('HP 840 G8 i5 11th 16 512', knownBrands: ['HP']);
        $dell = $interpreter->interpret('Dell 5420 i7 16/512', knownBrands: ['Dell']);
        $lenovo = $interpreter->interpret('Lenovo T14 Gen 2 i5 16GB 512 SSD', knownBrands: ['Lenovo']);

        $this->assertSame('840G8', $hp->model);
        $this->assertSame('I5', $hp->cpuFamily);
        $this->assertSame(16384, $hp->ramMb);
        $this->assertSame(512, $hp->storageGb);
        $this->assertSame('5420', $dell->model);
        $this->assertSame('I7', $dell->cpuFamily);
        $this->assertSame(16384, $dell->ramMb);
        $this->assertSame(512, $dell->storageGb);
        $this->assertSame('T14GEN2', $lenovo->model);
        $this->assertSame('I5', $lenovo->cpuFamily);
        $this->assertSame(16384, $lenovo->ramMb);
        $this->assertSame(512, $lenovo->storageGb);
        $this->assertSame('ssd', $lenovo->storageType);
    }
}

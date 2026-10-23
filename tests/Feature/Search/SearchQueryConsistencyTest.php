<?php

namespace Tests\Feature\Search;

use App\Models\Product;
use App\Services\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchQueryConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_spaces_and_multiple_terms_match_across_sku_name_and_model_without_treating_wildcards_as_patterns(): void
    {
        $match = Product::factory()->create(['sku' => 'ABC-123', 'name' => 'ProBook Laptop', 'model' => 'G8']);
        Product::factory()->create(['sku' => 'ABC-123-X', 'name' => 'Different Laptop', 'model' => 'G9']);

        $query = DB::table('products')->select('id');
        $this->assertSame([$match->id], SearchQuery::match($query, ['sku', 'name', 'model'], "  abc-123\n  PROBOOK   g8  ")->pluck('id')->all());
        $this->assertSame([], SearchQuery::match(DB::table('products'), ['sku'], 'ABC%')->pluck('id')->all());
        $this->assertTrue(SearchQuery::matchesText(['ABC-123', 'ProBook Laptop', 'G8'], '  probook    g8  '));
        $this->assertFalse(SearchQuery::matchesText(['ABC-123', 'ProBook Laptop', 'G8'], 'probook g9'));
    }

    public function test_exact_sku_ranks_before_prefix_and_partial_and_filtered_results_remain_paginated(): void
    {
        $exact = Product::factory()->create(['sku' => 'ABC-123']);
        $prefix = Product::factory()->create(['sku' => 'ABC-123-X']);
        $partial = Product::factory()->create(['sku' => 'X-ABC-123']);
        Product::factory()->create(['sku' => 'DIFFERENT']);

        $query = SearchQuery::match(DB::table('products')->select('id'), ['sku'], '  abc-123  ');
        $ordered = SearchQuery::rank($query, 'sku', '  abc-123  ')->pluck('id')->all();
        $this->assertSame([$exact->id, $prefix->id, $partial->id], $ordered);

        $filtered = SearchQuery::match(DB::table('products')->whereIn('id', [$exact->id, $prefix->id]), ['sku'], 'abc');
        $page = $filtered->orderBy('id')->paginate(1);
        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());
    }
}

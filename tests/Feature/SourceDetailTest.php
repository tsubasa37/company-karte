<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Ai\AnalysisRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SourceDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_ソース詳細ページで全文が見られる(): void
    {
        $company = Company::create(['name' => 'A社']);
        $source = $company->sourceDocuments()->create([
            'type' => 'jobposting',
            'origin' => 'paste',
            'raw_text' => '求人本文',
        ]);

        $this->get("/sources/{$source->id}")
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Sources/Show')
                ->where('source.length', 4)
                ->where('source.analyzedText', '求人本文')
                ->where('source.truncatedText', '')
                ->where('company.name', 'A社'));
    }

    public function test_上限超のソースは分析範囲と切り捨て範囲に分かれる(): void
    {
        // 求人票は上限が広い（給与・勤務地が後半にあるため）
        $limit = AnalysisRunner::MAX_JOBPOSTING_CHARS;
        $company = Company::create(['name' => 'B社']);
        $source = $company->sourceDocuments()->create([
            'type' => 'jobposting',
            'origin' => 'paste',
            'raw_text' => str_repeat('あ', $limit + 500),
        ]);

        $this->get("/sources/{$source->id}")
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Sources/Show')
                ->where('source.length', $limit + 500)
                ->where('source.analyzedText', str_repeat('あ', $limit))
                ->where('source.truncatedText', str_repeat('あ', 500))
                ->where('analysisSourceLimit', $limit));
    }

    public function test_求人票以外は標準の上限が使われる(): void
    {
        $limit = AnalysisRunner::MAX_SOURCE_CHARS;
        $company = Company::create(['name' => 'C社']);
        $source = $company->sourceDocuments()->create([
            'type' => 'news',
            'origin' => 'paste',
            'raw_text' => str_repeat('い', $limit + 100),
        ]);

        $this->get("/sources/{$source->id}")
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('analysisSourceLimit', $limit)
                ->where('source.truncatedText', str_repeat('い', 100)));
    }
}

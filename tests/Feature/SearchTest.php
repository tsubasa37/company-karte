<?php

namespace Tests\Feature;

use App\Actions\SearchDocumentsAction;
use App\Enums\IssueStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\OpenIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ソース本文を横断して引ける(): void
    {
        $a = $this->companyWithSource('A社', '残業は月20時間程度です。フルリモート可。');
        $this->companyWithSource('B社', '完全週休2日制。');

        $result = $this->search('残業');

        $this->assertSame(1, $result['total']);
        $this->assertSame($a->id, $result['groups'][0]['companyId']);
        $this->assertSame('残業', $result['groups'][0]['hits'][0]['snippets'][0]['match']);
    }

    public function test_口コミと論点と選考メモも対象になる(): void
    {
        $company = Company::create(['name' => 'A社', 'selection_notes' => '残業の実態を要確認']);
        $source = $company->sourceDocuments()->create([
            'type' => SourceType::Review,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '口コミ全文',
        ]);
        $company->reviewItems()->create([
            'source_document_id' => $source->id,
            'body' => '残業が多い',
            'body_hash' => hash('sha256', '残業が多い'),
        ]);
        OpenIssue::create([
            'company_id' => $company->id,
            'claim' => '残業は月20時間程度',
            'claim_hash' => OpenIssue::hashFor('残業は月20時間程度'),
            'verdict' => 'contradict',
            'status' => IssueStatus::Open,
        ]);

        $kinds = array_column($this->search('残業')['groups'][0]['hits'], 'kind');

        $this->assertContains('review', $kinds);
        $this->assertContains('issue', $kinds);
        $this->assertContains('note', $kinds);
    }

    public function test_1文字の検索語は短すぎるとして扱う(): void
    {
        $this->companyWithSource('A社', '残業は月20時間');

        $result = $this->search('残');

        $this->assertTrue($result['tooShort']);
        $this->assertSame(0, $result['total']);
    }

    public function test_未入力は短すぎる扱いにしない(): void
    {
        $result = $this->search(null);

        $this->assertFalse($result['tooShort']);
        $this->assertSame([], $result['groups']);
    }

    public function test_ワイルドカードは文字として検索される(): void
    {
        $this->companyWithSource('A社', '達成率100%の実績');
        $this->companyWithSource('B社', '残業は月20時間');

        // '%' が LIKE のワイルドカードとして効くと全件ヒットしてしまう
        $this->assertSame(1, $this->search('100%')['total']);
    }

    public function test_検索ページが結果を返す(): void
    {
        $this->companyWithSource('A社', '残業は月20時間程度です。');

        $this->get(route('search', ['q' => '残業']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Search')
                ->where('total', 1)
                ->where('groups.0.companyName', 'A社')
            );
    }

    private function companyWithSource(string $name, string $text): Company
    {
        $company = Company::create(['name' => $name]);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => $text,
        ]);

        return $company;
    }

    /** @return array<string, mixed> */
    private function search(?string $query): array
    {
        return $this->app->make(SearchDocumentsAction::class)->execute($query);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 企業1社の削除（基本情報タブの最下部）。
 * 分析の実行中に消すと、走っている AI が削除済み企業のために消費を続けるため、そのガードを重点的に確かめる。
 */
class CompanyDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithSource(): Company
    {
        $company = Company::create(['name' => '削除対象社']);
        $company->sourceDocuments()->create([
            'type' => SourceType::JobPosting,
            'origin' => SourceOrigin::Paste,
            'raw_text' => '求人票本文',
        ]);

        return $company;
    }

    public function test_企業を関連データごと削除できる(): void
    {
        $company = $this->companyWithSource();

        $this->delete(route('companies.destroy', $company))->assertRedirect(route('companies.index'));

        $this->assertNull(Company::find($company->id));
        $this->assertSame(0, SourceDocument::count(), 'ソースはカスケードで消える');
    }

    public function test_分析の実行中は削除できず理由が返る(): void
    {
        $company = $this->companyWithSource();
        $company->analysisRuns()->create(['status' => RunStatus::Running, 'started_at' => now()]);

        $this->delete(route('companies.destroy', $company))->assertSessionHasErrors('delete');

        $this->assertNotNull(Company::find($company->id));
    }

    public function test_待機中のランがあっても削除できない(): void
    {
        $company = $this->companyWithSource();
        $company->analysisRuns()->create(['status' => RunStatus::Queued]);

        $this->delete(route('companies.destroy', $company))->assertSessionHasErrors('delete');

        $this->assertNotNull(Company::find($company->id));
    }

    public function test_一括削除でも実行中の企業が混ざっていれば全体を止める(): void
    {
        $idle = $this->companyWithSource();
        $running = $this->companyWithSource();
        $running->analysisRuns()->create(['status' => RunStatus::Running, 'started_at' => now()]);

        $this->delete('/companies', ['ids' => [$idle->id, $running->id]])->assertSessionHasErrors('ids');

        $this->assertNotNull(Company::find($idle->id), '半端に消えない');
        $this->assertNotNull(Company::find($running->id));
    }

    public function test_失敗で終わったランは削除の妨げにならない(): void
    {
        $company = $this->companyWithSource();
        $company->analysisRuns()->create(['status' => RunStatus::Failed, 'finished_at' => now()]);

        $this->delete(route('companies.destroy', $company))->assertRedirect(route('companies.index'));

        $this->assertNull(Company::find($company->id));
    }
}

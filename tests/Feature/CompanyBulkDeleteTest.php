<?php

namespace Tests\Feature;

use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Models\Company;
use App\Models\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 企業一覧のチェックボックスからの一括削除。
 * 1社ずつ基本情報タブの最下部まで行く手間を無くすための導線で、
 * 削除の実体は単体削除と同じ（関連データはDBのカスケードで消える）。
 */
class CompanyBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_選択した企業だけが関連データごと削除される(): void
    {
        $a = Company::create(['name' => '削除対象A社']);
        $b = Company::create(['name' => '削除対象B社']);
        $keep = Company::create(['name' => '残す社']);

        foreach ([$a, $b, $keep] as $company) {
            $company->sourceDocuments()->create([
                'type' => SourceType::JobPosting,
                'origin' => SourceOrigin::Paste,
                'raw_text' => '求人票本文',
            ]);
        }

        $this->delete('/companies', ['ids' => [$a->id, $b->id]])
            ->assertRedirect(route('companies.index'));

        $this->assertNull(Company::find($a->id));
        $this->assertNull(Company::find($b->id));
        $this->assertNotNull(Company::find($keep->id), '選択していない企業は消えない');
        $this->assertSame(1, SourceDocument::count(), '削除した企業のソースはカスケードで消える');
    }

    public function test_空の選択では削除できない(): void
    {
        Company::create(['name' => '株式会社テスト']);

        $this->delete('/companies', ['ids' => []])->assertSessionHasErrors('ids');

        $this->assertSame(1, Company::count());
    }

    public function test_存在しない企業idが混ざっていたら何も消さない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        // 半端に消えると「どれが消えたか」が分からなくなるため、全件検証してから消す
        $this->delete('/companies', ['ids' => [$company->id, 999999]])
            ->assertSessionHasErrors('ids.1');

        $this->assertSame(1, Company::count());
    }
}

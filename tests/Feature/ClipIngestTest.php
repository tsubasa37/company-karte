<?php

namespace Tests\Feature;

use App\Actions\IngestClipAction;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\Ai\ClipClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ClipIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_法人格の表記ゆれを同一企業に名寄せする(): void
    {
        // 口コミソースの作成は分割ジョブを積む。テストは sync 実行なので fake しないと
        // ReviewSplitter が実際に codex を起動してサブスクを消費する
        Queue::fake();
        $existing = Company::create(['name' => '株式会社テスト']);

        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'company_name' => 'テスト',
            'source_type' => 'review',
            'summary' => 'テスト社の口コミ',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);

        $result = $this->app->make(IngestClipAction::class)
            ->execute('タイトル', 'https://example.com/review', '口コミ本文テキスト');

        $this->assertTrue($result['ok']);
        $this->assertSame($existing->id, $result['company_id']);
        $this->assertSame(1, Company::count(), '表記ゆれで重複作成されないこと');
    }

    public function test_クリップのページタイトルとai要約が保存される(): void
    {
        Queue::fake();
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'company_name' => '株式会社テスト',
            'source_type' => 'review',
            'summary' => 'テスト社の口コミ（残業と評価制度の話題）',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);

        $this->app->make(IngestClipAction::class)
            ->execute('テスト社の評判・口コミ | OpenWork', 'https://example.com/review', '口コミ本文テキスト');

        $source = SourceDocument::firstOrFail();
        $this->assertSame('テスト社の評判・口コミ | OpenWork', $source->title);
        $this->assertSame('テスト社の口コミ（残業と評価制度の話題）', $source->summary);
    }

    public function test_企業を指定したら_a_iの企業判定は使わない(): void
    {
        Queue::fake();
        $target = Company::create(['name' => '株式会社ターゲット']);

        // AI は別企業を返す（表記ゆれで別企業に割れる状況）が、指定が勝つこと
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldReceive('classify')->andReturn([
            'is_company_related' => true,
            'is_multi_company' => false,
            'company_name' => '株式会社まったく別の会社',
            'source_type' => 'news',
            'summary' => '記事',
        ]);
        $this->app->instance(ClipClassifier::class, $mock);

        $result = $this->app->make(IngestClipAction::class)
            ->execute('記事', 'https://example.com/n', '記事本文', null, $target->id);

        $this->assertSame($target->id, $result['company_id']);
        $this->assertSame(1, Company::count(), 'AIの企業名で新規作成しない');
        $this->assertSame($target->id, SourceDocument::firstOrFail()->company_id);
        // 種別が未指定なので判定のために AI は呼ぶ（その結果の種別は使う）
        $this->assertSame('news', $result['source_type']);
    }

    public function test_企業と種別の両方を指定したら_a_iを呼ばない(): void
    {
        Queue::fake();
        $target = Company::create(['name' => '株式会社ターゲット']);

        // codex を1回も消費しないこと（呼ばれたら期待違反で失敗する）
        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldNotReceive('classify');
        $this->app->instance(ClipClassifier::class, $mock);

        $result = $this->app->make(IngestClipAction::class)
            ->execute('口コミページ', 'https://example.com/r', '口コミ本文', 'review', $target->id);

        $this->assertSame($target->id, $result['company_id']);
        $this->assertSame('review', $result['source_type']);
        $this->assertNull(SourceDocument::firstOrFail()->summary, 'AI要約は無いので null');
    }

    public function test_ap_iでも投入先企業を指定できる(): void
    {
        Queue::fake();
        config(['services.clip.token' => 'secret-token']);
        $target = Company::create(['name' => '株式会社ターゲット']);

        $mock = Mockery::mock(ClipClassifier::class);
        $mock->shouldNotReceive('classify');
        $this->app->instance(ClipClassifier::class, $mock);

        $this->postJson('/api/clip', [
            'text' => '口コミ本文',
            'url' => 'https://example.com/r',
            'type' => 'review',
            'company_id' => $target->id,
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['ok' => true, 'company_id' => $target->id]);
    }

    public function test_存在しない企業_idは弾く(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->postJson('/api/clip', [
            'text' => '本文',
            'company_id' => 999,
        ], ['X-Clip-Token' => 'secret-token'])
            ->assertStatus(422);
    }

    public function test_企業一覧を拡張向けに返す(): void
    {
        config(['services.clip.token' => 'secret-token']);
        Company::create(['name' => 'B社']);
        Company::create(['name' => 'A社']);

        $this->getJson('/api/companies', ['X-Clip-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('companies.0.name', 'A社')
            ->assertJsonPath('companies.1.name', 'B社');
    }

    public function test_企業一覧はトークン無しでは返さない(): void
    {
        config(['services.clip.token' => 'secret-token']);

        $this->getJson('/api/companies')->assertStatus(401);
    }
}

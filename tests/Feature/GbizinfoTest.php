<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OfficialMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GbizinfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_認定と件数が公式数値に登録される(): void
    {
        config(['services.gbizinfo.token' => 'test-token']);
        Http::fake([
            'info.gbiz.go.jp/hojin/v1/hojin/1234567890123/certification' => Http::response([
                'hojin-infos' => [[
                    'certification' => [
                        ['title' => 'くるみん', 'date_of_approval' => '2023-05-01'],
                        ['title' => 'えるぼし（3段階目）', 'date_of_approval' => '2024-02-01'],
                    ],
                ]],
            ]),
            'info.gbiz.go.jp/hojin/v1/hojin/1234567890123/subsidy' => Http::response([
                'hojin-infos' => [['subsidy' => [['title' => 'ものづくり補助金']]]],
            ]),
            'info.gbiz.go.jp/hojin/v1/hojin/1234567890123/procurement' => Http::response([], 404),
        ]);

        $company = Company::create(['name' => '株式会社テスト', 'legal_number' => '1234567890123']);

        $this->post(route('gbizinfo.store', $company))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(4, OfficialMetric::count(), '認定2 + 補助金 + 調達');
        $cert = OfficialMetric::where('key', '認定: くるみん')->firstOrFail();
        $this->assertSame('あり', $cert->value);
        $this->assertSame('2023', $cert->fiscal_year);
        $this->assertSame('1', OfficialMetric::where('key', '補助金交付')->firstOrFail()->value);
        $this->assertSame('0', OfficialMetric::where('key', '国の調達実績')->firstOrFail()->value);
    }

    public function test_法人番号未設定はエラーを表示する(): void
    {
        config(['services.gbizinfo.token' => 'test-token']);
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('gbizinfo.store', $company))
            ->assertSessionHasErrors('gbizinfo');
    }

    public function test_トークン未設定はエラーを表示する(): void
    {
        config(['services.gbizinfo.token' => null]);
        $company = Company::create(['name' => '株式会社テスト', 'legal_number' => '1234567890123']);

        $this->post(route('gbizinfo.store', $company))
            ->assertSessionHasErrors('gbizinfo');
    }

    public function test_トークン未設定なら押す前からボタンが無効になり理由が出る(): void
    {
        // 「押してから失敗」だと、back() でタブが戻されてエラーが別タブに隠れる。
        // EDINET と同じく、押す前に理由を出して無効化する
        config(['services.gbizinfo.token' => null]);
        $company = Company::create(['name' => '株式会社テスト', 'legal_number' => '1234567890123']);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page
                ->where('edinet.gbizBlockedReason', fn (?string $r) => $r !== null
                    && str_contains($r, 'GBIZINFO_API_TOKEN')));
    }

    public function test_法人番号もトークンも揃っていればボタンは有効(): void
    {
        config(['services.gbizinfo.token' => 'test-token']);
        $company = Company::create(['name' => '株式会社テスト', 'legal_number' => '1234567890123']);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page->where('edinet.gbizBlockedReason', null));
    }
}

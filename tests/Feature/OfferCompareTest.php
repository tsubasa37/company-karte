<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OfferCompareTest extends TestCase
{
    use RefreshDatabase;

    public function test_オファー入力がoffer_jsonに保存される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'kininaru',
            'offer_annual_salary' => 600,
            'offer_notes' => '基本給45万×12 + 賞与2ヶ月',
        ])->assertRedirect();

        $offer = $company->fresh()->offer_json;
        $this->assertSame(600, $offer['annual_salary']);
        $this->assertSame('基本給45万×12 + 賞与2ヶ月', $offer['notes']);
    }

    public function test_オファー未入力ならoffer_jsonはnull(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => ['annual_salary' => 600, 'notes' => '']]);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'kininaru',
            'offer_annual_salary' => null,
            'offer_notes' => '',
        ])->assertRedirect();

        $this->assertNull($company->fresh()->offer_json);
    }

    public function test_比較ビューで現年収との差が計算される(): void
    {
        Profile::create(['current_annual_salary' => 450]);
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => ['annual_salary' => 500, 'notes' => '']]);

        // 未分析企業は既定で畳まれるため all=1 で表示する
        $this->get('/compare?all=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Compare')
                ->where('rows.0.offerSalary', 500)
                ->where('rows.0.offerDelta', 50));
    }

    public function test_現年収未設定なら差はnull(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => ['annual_salary' => 600, 'notes' => '']]);

        // 未分析企業は既定で畳まれるため all=1 で表示する
        $this->get('/compare?all=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.offerSalary', 600)
                ->where('rows.0.offerDelta', null));
    }

    public function test_固定残業込みのオファーは差額に注記が付く(): void
    {
        // 「+50万」の緑表示だけを見て実質ダウンに気づかない事故を防ぐための注記
        Profile::create(['current_annual_salary' => 450]);
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => [
            'annual_salary' => 500,
            'notes' => '',
            'fixed_overtime_hours' => 45,
            'bonus_months' => 2.0,
        ]]);

        $this->get('/compare?all=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.offerDelta', 50)
                ->where('rows.0.offerCaveat', '固定残業45h込み / 賞与2ヶ月分を含む — 現年収との単純比較はできません'));
    }

    public function test_固定残業なしのオファーには注記が付かない(): void
    {
        Profile::create(['current_annual_salary' => 450]);
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => [
            'annual_salary' => 500,
            'notes' => '',
            'fixed_overtime_hours' => 0,
            'bonus_months' => null,
        ]]);

        $this->get('/compare?all=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.offerCaveat', null));
    }
}

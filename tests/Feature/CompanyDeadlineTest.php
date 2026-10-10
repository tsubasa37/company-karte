<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * オファー回答期限とオファー内訳の保存。
 * 部分更新で既存の期限・オファーが黙って消えないことを含めて確かめる。
 * 面接日程は schedule_events 側（ScheduleEventTest）が持つ。
 */
class CompanyDeadlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_オファー回答期限を保存できる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_deadline' => '2026-08-10',
        ])->assertRedirect();

        $this->assertSame('2026-08-10', $company->fresh()->offer_deadline->format('Y-m-d'));
    }

    public function test_期限を空で送ると未設定に戻せる(): void
    {
        $company = Company::create([
            'name' => '株式会社テスト',
            'offer_deadline' => '2026-08-10',
        ]);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_deadline' => null,
        ])->assertRedirect();

        $this->assertNull($company->fresh()->offer_deadline);
    }

    public function test_条件メモだけの部分更新で期限とオファー年収が消えない(): void
    {
        $company = Company::create([
            'name' => '株式会社テスト',
            'offer_deadline' => '2026-08-10',
        ]);
        $company->update(['offer_json' => ['annual_salary' => 600, 'notes' => '旧メモ']]);

        // 期限・年収のキーを送らない部分更新（他画面・API的な部分送信を想定）
        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_notes' => '新メモ',
        ])->assertRedirect();

        $company->refresh();
        $this->assertSame('2026-08-10', $company->offer_deadline->format('Y-m-d'));
        $this->assertSame(600, $company->offer_json['annual_salary']);
        $this->assertSame('新メモ', $company->offer_json['notes']);
    }

    public function test_offer_jsonに固定残業時間と賞与月数と基本給が保存される(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_annual_salary' => 600,
            'offer_base_monthly_yen' => 450000,
            'offer_fixed_overtime_hours' => 20,
            'offer_bonus_months' => 2.5,
            'offer_notes' => '住宅手当3万',
        ])->assertRedirect();

        $offer = $company->fresh()->offer_json;
        $this->assertSame(600, $offer['annual_salary']);
        $this->assertSame(450000, $offer['base_monthly_yen']);
        $this->assertSame(20, $offer['fixed_overtime_hours']);
        $this->assertSame(2.5, $offer['bonus_months']);
        $this->assertSame('住宅手当3万', $offer['notes']);
    }

    public function test_固定残業時間だけの部分更新で年収が消えない(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => ['annual_salary' => 600, 'notes' => 'メモ']]);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_fixed_overtime_hours' => 45,
        ])->assertRedirect();

        $offer = $company->fresh()->offer_json;
        $this->assertSame(600, $offer['annual_salary']);
        $this->assertSame('メモ', $offer['notes']);
        $this->assertSame(45, $offer['fixed_overtime_hours']);
    }

    public function test_オファー項目を全て空で送るとoffer_jsonはnullに戻る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);
        $company->update(['offer_json' => ['annual_salary' => 600, 'fixed_overtime_hours' => 20, 'notes' => 'メモ']]);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_annual_salary' => null,
            'offer_notes' => '',
            'offer_base_monthly_yen' => null,
            'offer_fixed_overtime_hours' => null,
            'offer_bonus_months' => null,
        ])->assertRedirect();

        $this->assertNull($company->fresh()->offer_json);
    }

    public function test_固定残業時間の上限を超えると弾かれる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => 'offer',
            'offer_fixed_overtime_hours' => 999,
        ])->assertSessionHasErrors('offer_fixed_overtime_hours');

        $this->assertNull($company->fresh()->offer_json);
    }

    public function test_基本情報タブに期限とオファー内訳が渡る(): void
    {
        $company = Company::create([
            'name' => '株式会社テスト',
            'offer_deadline' => '2026-08-10',
        ]);
        $company->update(['offer_json' => [
            'annual_salary' => 600,
            'notes' => 'メモ',
            'base_monthly_yen' => 450000,
            'fixed_overtime_hours' => 20,
            'bonus_months' => 2.5,
        ]]);

        $this->get(route('companies.show', $company))
            ->assertInertia(fn ($page) => $page
                ->where('company.offerDeadline', '2026-08-10')
                ->where('company.offerBaseMonthlyYen', 450000)
                ->where('company.offerFixedOvertimeHours', 20)
                ->where('company.offerBonusMonths', 2.5));
    }
}

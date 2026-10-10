<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 入力を拒否した理由が日本語で返ること。
 *
 * 画面の文言はすべて日本語に揃える方針だが、翻訳ファイルが無いとフレームワーク既定の
 * 英語（The interviewer field must not be greater than 100 characters.）に落ちる。
 * ロケールと lang/ja/validation.php のどちらが欠けても英語に戻るため、実際の応答で確かめる。
 */
class ValidationMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ロケールが日本語である(): void
    {
        $this->assertSame('ja', config('app.locale'));
    }

    public function test_面接記録の文字数超過が日本語の項目名で返る(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('interviews.store', $company), [
            'interviewed_on' => '2026-09-10',
            'round' => '一次面接',
            'interviewer' => str_repeat('あ', 101),
            'answers' => '残業は月20時間との回答',
        ])->assertSessionHasErrors([
            'interviewer' => '面接官の役職は100文字以内で入力してください。',
        ]);
    }

    public function test_必須項目の未入力が日本語で返る(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        $this->post(route('interviews.store', $company), [
            'interviewed_on' => '2026-09-10',
            'round' => '',
            'answers' => '回答',
        ])->assertSessionHasErrors(['round' => '回次を入力してください。']);
    }

    public function test_数値項目の型違いが日本語の項目名で返る(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->put(route('companies.update', $company), [
            'name' => '株式会社テスト',
            'status' => CompanyStatus::Interview->value,
            'offer_annual_salary' => 'あ',
        ])
            ->assertSessionHasErrors(['offer_annual_salary' => 'オファー年収は整数で入力してください。'])
            // 他の欄の拒否に紛れて合格しないよう、送った他の値は正しいことも確かめる
            ->assertSessionDoesntHaveErrors(['name', 'status']);
    }

    public function test_ur_lの拒否理由は項目名を重ねず案内する(): void
    {
        Queue::fake();
        $company = Company::create(['name' => '株式会社テスト']);

        // 汎用文のままだと「URLはURLの形式で…」と重なるため専用の文言を持たせている
        $this->post(route('sources.store', $company), [
            'type' => 'news',
            'origin' => 'url',
            'url' => 'not-a-url',
        ])->assertSessionHasErrors([
            'url' => 'URLの形式で入力してください（http:// または https:// から始まる形）。',
        ]);
    }

    public function test_翻訳の無いキーが英語で残っていない(): void
    {
        $ja = require lang_path('ja/validation.php');
        $en = require base_path(
            'vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'
        );

        // フレームワークが持つルールはすべて訳しておく（欠けると そのルールだけ英語になる）
        $missing = array_diff(array_keys($en), array_keys($ja));

        $this->assertSame([], array_values($missing), '未翻訳のルール: '.implode(', ', $missing));
    }
}

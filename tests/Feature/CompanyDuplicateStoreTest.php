<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDuplicateStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_名寄せキーが既存企業と一致したら警告して作成しない(): void
    {
        $existing = Company::create(['name' => '株式会社芙蓉テスト工業']);

        // 法人格の位置・全角/半角・中黒が違っても同じ企業として警告する
        $this->post('/companies', ['name' => '芙蓉テスト工業（株）'])
            ->assertSessionHasErrors('duplicate');

        $this->assertSame(1, Company::count(), '確認前は作成しない');
        $this->assertStringContainsString(
            (string) $existing->id,
            session('errors')->first('duplicate'),
            '既存企業のIDを添えて知らせる',
        );
        $this->assertStringContainsString('株式会社芙蓉テスト工業', session('errors')->first('duplicate'));
    }

    public function test_別法人として確認済みなら同名でも作成する(): void
    {
        Company::create(['name' => '株式会社芙蓉テスト工業']);

        $this->post('/companies', [
            'name' => '芙蓉テスト工業株式会社',
            'confirm_duplicate' => true,
        ])->assertRedirect();

        $this->assertSame(2, Company::count(), '確認済みなら同名別法人を登録できる');
    }

    public function test_重複しない企業はそのまま作成して開く(): void
    {
        Company::create(['name' => '株式会社芙蓉テスト工業']);

        $this->post('/companies', ['name' => '株式会社別テスト商事', 'position' => 'バックエンド'])
            ->assertSessionHasNoErrors();

        $created = Company::firstWhere('name', '株式会社別テスト商事');
        $this->assertNotNull($created);
        $this->assertSame('バックエンド', $created->position);
    }
}

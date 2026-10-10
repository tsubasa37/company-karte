<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 企業のお気に入り（★）。値指定の PATCH で冪等に保存し、一覧の並び替え材料として返す。
 */
class FavoriteUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_お気に入りを付けて外せる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->patch(route('companies.favorite', $company), ['favorite' => true])->assertRedirect();
        $this->assertTrue($company->refresh()->favorite);

        $this->patch(route('companies.favorite', $company), ['favorite' => false])->assertRedirect();
        $this->assertFalse($company->refresh()->favorite);
    }

    public function test_値指定なので連打しても同じ結果になる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->patch(route('companies.favorite', $company), ['favorite' => true]);
        $this->patch(route('companies.favorite', $company), ['favorite' => true]);

        $this->assertTrue($company->refresh()->favorite);
    }

    public function test_boolean以外は検証エラーになる(): void
    {
        $company = Company::create(['name' => '株式会社テスト']);

        $this->patch(route('companies.favorite', $company), ['favorite' => 'yes'])
            ->assertSessionHasErrors('favorite');
        $this->patch(route('companies.favorite', $company), [])
            ->assertSessionHasErrors('favorite');
    }

    public function test_一覧の行にfavoriteが含まれる(): void
    {
        Company::create(['name' => '★あり', 'favorite' => true]);
        Company::create(['name' => '★なし']);

        $rows = $this->get('/companies')
            ->assertOk()
            ->inertiaProps()['companies'];

        $byName = collect($rows)->keyBy('name');
        $this->assertTrue($byName['★あり']['favorite']);
        $this->assertFalse($byName['★なし']['favorite']);
    }
}

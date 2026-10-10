<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 使い方（/help）。画面内ヘルプ（ⓘ）に収まらない「流れ・分析結果の読み方・用語」を置くページ。
 *
 * 見るのは4つ。(1) 材料が1件も無い状態でも開けること、(2) 描くコンポーネント、
 * (3) 静的な文章だけの画面なので props を増やさないこと、(4) ナビから辿れること。
 * 文章そのものはテストで固定しない（本文を直すたびにテストが落ちるのは邪魔なので）。
 */
class HelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_使い方ページが開ける(): void
    {
        $this->get('/help')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Help'));
    }

    public function test_ページ固有の_propsを持たない(): void
    {
        $props = $this->get('/help')->assertOk()->viewData('page')['props'];

        // 共有プロップス（errors / flash）以外を足さない。文章だけの画面に DB を引く経路を作らないため
        $extra = array_values(array_diff(array_keys($props), ['errors', 'flash']));

        $this->assertSame([], $extra, '使い方ページに props が増えています: '.implode(', ', $extra));
    }

    public function test_ナビから辿れる(): void
    {
        // 使い方はナビの一員ではなく、左レール最下段の常設導線として置く
        // （どの画面からも同じ場所にあり、ナビの並びに混ざらないようにするため）
        $rail = (string) file_get_contents(resource_path('js/components/shell/SideRail.vue'));

        $this->assertStringContainsString('href="/help"', $rail, 'SideRail の下段に /help の導線が無い');
    }

    public function test_拡張の手順は_onboarding_guideを再利用する(): void
    {
        // 手順の正本は OnboardingGuide.vue 側（OnboardingGuideTokenTest がそこを検証している）。
        // 同じ文言を2箇所で保守しないよう、使い方ページはコピーせず再利用する
        $help = (string) file_get_contents(resource_path('js/Pages/Help.vue'));

        $this->assertStringContainsString('OnboardingGuide', $help);
        $this->assertStringNotContainsString('chrome://extensions', $help);
    }
}

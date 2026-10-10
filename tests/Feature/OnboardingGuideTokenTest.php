<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A-8: オンボーディングに Chrome拡張のセットアップ手順を載せた。手順は画面に出すが、
 * CLIP_TOKEN の実値は画面に出さない（値は .env を直接見てもらう）という線を固定する。
 */
class OnboardingGuideTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'onboarding-guide-test-clip-token-9f2a';

    private const GUIDE = 'js/components/company/OnboardingGuide.vue';

    /**
     * @return array<string, array{0: string}>
     */
    public static function guidePages(): array
    {
        return [
            'ダッシュボード' => ['/'],
            '企業一覧' => ['/companies'],
        ];
    }

    /**
     * プロファイル未設定・企業ゼロ（=オンボーディングが出る状態）でも、
     * クリップトークンは Inertia のプロップスにもHTMLにも一切乗らない。
     */
    #[DataProvider('guidePages')]
    public function test_guide_pages_never_expose_the_clip_token(string $url): void
    {
        config(['services.clip.token' => self::TOKEN]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('hasProfile', false));
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
    }

    /**
     * README を開かずに済むよう、拡張の導入手順が画面コンポーネントに揃っていること。
     */
    public function test_guide_component_carries_the_extension_setup_steps(): void
    {
        $guide = (string) file_get_contents(resource_path(self::GUIDE));

        foreach ([
            'chrome://extensions',
            'デベロッパーモード',
            'extension/config.example.js',
            'extension/config.js',
            'CLIP_TOKEN',
        ] as $step) {
            $this->assertStringContainsString($step, $guide, "拡張セットアップ手順に「{$step}」が無い");
        }
    }

    /**
     * 手順の表示にトークンの値を持ち込まない（プロップス経由の受け渡しを作らない）。
     */
    public function test_guide_component_does_not_read_the_token_from_the_backend(): void
    {
        $guide = (string) file_get_contents(resource_path(self::GUIDE));

        foreach (['clipToken', 'clip_token', 'services.clip'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $guide);
        }
    }
}

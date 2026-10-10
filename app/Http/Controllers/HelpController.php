<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * 使い方（/help）。
 *
 * 画面内ヘルプ（ⓘ）に置くと他の画面から引けないもの ── カルテができるまでの流れ・
 * 分析結果の読み方・用語・拡張の設定 ── を1ページにまとめる。
 * 中身は静的な文章だけなので props は渡さない（この画面のために DB を引かない）。
 */
class HelpController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Help');
    }
}

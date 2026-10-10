<?php

namespace App\Exceptions;

/**
 * 外部URLの取得を「大きすぎる」で打ち切ったときの理由。
 *
 * 申告された長さで転送前に止めた場合は、Guzzle と Laravel が別の例外で包んでから外へ出すため、
 * 包みの中からこちらが決めた理由を取り出せるよう専用の型にしている
 * （「接続に失敗しました」に化けさせず、なぜ止めたのかを画面に出すため）。
 */
class FetchSizeExceededException extends \RuntimeException {}

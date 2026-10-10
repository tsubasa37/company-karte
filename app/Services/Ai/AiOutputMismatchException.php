<?php

namespace App\Services\Ai;

/**
 * AI の応答が正規スキーマに合わない（required 欠落・型不一致・余分な key・enum 外）。
 * 欠けた項目を null で埋めて通さず、ここで止める。
 */
class AiOutputMismatchException extends \RuntimeException {}

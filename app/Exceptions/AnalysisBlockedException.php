<?php

namespace App\Exceptions;

/**
 * 分析の開始・再開・中断ができない理由。文言はそのまま画面（errors.analyze）に出す。
 * サイレント失敗ではなく「なぜできないか」を必ず利用者に見せるための例外。
 */
class AnalysisBlockedException extends \RuntimeException {}

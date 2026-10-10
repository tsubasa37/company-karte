<?php

namespace App\Enums;

/**
 * AI の種類。生成（schema + prompt → 構造化出力）と判定（state + questions → 確率つき回答）は
 * 種類の違う AI で、正規パスがそれぞれ 1 本（生成は AiRunner、判定は AiDecider）。
 */
enum AiKind: string
{
    case Generation = 'generation';
    case Decision = 'decision';
}

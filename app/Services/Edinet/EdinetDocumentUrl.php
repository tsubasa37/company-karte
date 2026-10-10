<?php

namespace App\Services\Edinet;

/**
 * EDINET の書類閲覧画面URLを書類管理番号（docId）から組み立てる。
 * 有報から取り込んだ数値の出典として official_metrics.source_url に保存し、
 * 画面から原文（有報）へ1クリックで戻れるようにする（gBizINFO 側と同じ扱い）。
 *
 * API のダウンロードURL（/api/v2/documents/{docId}）は Subscription-Key が必要で、
 * 保存したURLに購読キーが混ざるため使わない。
 */
class EdinetDocumentUrl
{
    /** 閲覧サイトは書類管理番号をそのままクエリに取る（例: WZEK0040.aspx?S100AAAA=） */
    private const VIEWER_TEMPLATE = 'https://disclosure2.edinet-fsa.go.jp/WZEK0040.aspx?%s=';

    public static function viewer(string $docId): string
    {
        // docId は EDINET API 由来の外部入力。URLに素で埋めると画面のリンクに任意の文字列が入るため、
        // 書類管理番号の形式（英数字）から外れたものは例外にする（黙って壊れたURLを保存しない）
        if (preg_match('/^[A-Za-z0-9]+$/', $docId) !== 1) {
            throw new \InvalidArgumentException("EDINETの書類管理番号の形式が不正です: {$docId}");
        }

        return sprintf(self::VIEWER_TEMPLATE, $docId);
    }
}

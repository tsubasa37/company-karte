<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * メール取り込みの設定（自分専用アプリなので常に1行）。
 *
 * 秘密の値は暗号化して保存する。バックアップ（SQLiteファイルのコピー）が持ち出されても
 * そのままでは使えないようにするため（google_credentials のトークンと同じ扱い）。
 */
class MailSetting extends Model
{
    protected $fillable = [
        'google_client_id',
        'google_client_secret',
        'allowed_domains',
        'mask_words',
        'auto_fetch',
    ];

    /** 値そのものは画面に返さない（設定済みかどうかだけを見せる） */
    protected $hidden = ['google_client_secret', 'mask_words'];

    protected function casts(): array
    {
        return [
            'google_client_id' => 'encrypted',
            'google_client_secret' => 'encrypted',
            'mask_words' => 'encrypted',
            'auto_fetch' => 'boolean',
        ];
    }
}

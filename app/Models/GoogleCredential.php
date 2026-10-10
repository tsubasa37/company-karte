<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gmail 取得に使う OAuth トークン。自分専用アプリなので常に1行だけ持つ。
 */
class GoogleCredential extends Model
{
    protected $fillable = [
        'email',
        'refresh_token',
        'access_token',
        'access_token_expires_at',
        'last_fetched_at',
        'last_fetch_error',
        'last_fetch_error_at',
    ];

    protected $hidden = ['refresh_token', 'access_token'];

    protected function casts(): array
    {
        return [
            // バックアップ（SQLiteファイルのコピー）が持ち出されたときに、
            // Gmail 読み取りトークンがそのまま使える状態で残らないようにする
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_fetch_error_at' => 'datetime',
        ];
    }
}

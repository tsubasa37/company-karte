<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * プロバイダごとの接続設定（API キー・モデル・固有オプション）。プロバイダにつき1行。
 *
 * API キーは暗号化して保存する（mail_settings の google_client_secret と同じ扱い）。
 * codex 行はサブプロセス実行なので api_key は常に null で、モデル名だけを持つ。
 * モデル名は .env（CODEX_MODEL / CODEX_MODEL_LIGHT）から DB に移すため、この migration が一度だけ .env を読んで codex 行にコピーする。
 */
return new class extends Migration
{
    public function up(): void
    {
        // config キャッシュ中は .env が読まれず、書いてあったモデル指定が黙って捨てられる。表を作る前に止める
        if (app()->configurationIsCached()) {
            throw new RuntimeException('config キャッシュ中は .env の CODEX_MODEL / CODEX_MODEL_LIGHT を読めません。php artisan config:clear の後に migrate を実行してください');
        }

        Schema::create('ai_provider_configs', function (Blueprint $table) {
            $table->id();
            // AiProvider の値（codex / openai / gemini / anthropic）
            $table->string('provider')->unique();
            // 暗号化して保存する。codex 行は常に null
            $table->text('api_key')->nullable();
            // 分析・要約用 / 抽出・判定用のモデル名。null はプロバイダの既定に任せる
            $table->string('model_analysis')->nullable();
            $table->string('model_light')->nullable();
            // プロバイダ固有の設定（推論強度など）を Tier ごとに持つ。ドライバは自分の鍵だけ読む
            $table->json('options')->nullable();
            $table->timestamps();
        });

        // codex 行。.env の CODEX_MODEL / CODEX_MODEL_LIGHT を DB に移す（空なら null = codex の既定モデル）。
        // config には置かなくなった（設定画面＝DB が正）ので env() を直接読む
        $model = env('CODEX_MODEL');
        $modelLight = env('CODEX_MODEL_LIGHT');
        DB::table('ai_provider_configs')->insert([
            'provider' => 'codex',
            'api_key' => null,
            'model_analysis' => filled($model) ? $model : null,
            'model_light' => filled($modelLight) ? $modelLight : null,
            'options' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_configs');
    }
};

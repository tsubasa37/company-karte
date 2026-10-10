<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_ingests', function (Blueprint $table) {
            $table->id();
            // 同じメールを二重に取り込まないための外部ID（Gmail の messages.id）
            $table->string('external_id')->unique();
            $table->string('from_domain');
            $table->string('subject');
            $table->dateTime('received_at');
            // MailKind（scout/schedule/result/other）。codex の判定結果
            $table->string('mail_kind');
            $table->string('summary')->nullable();
            // 取り込みで作った・紐づけたもの。取り消し時に何を消すかの根拠になる
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_event_id')->nullable()->constrained()->nullOnDelete();
            // この取り込みが企業を新規作成したか。取り消し時に既存企業を巻き込んで消さないための根拠
            $table->boolean('created_company')->default(false);
            // 日時候補が複数提示された場合（予定は自動作成せず候補だけ残す）
            $table->json('event_candidates')->nullable();
            // 本人が目視したか。承認ゲートではなく既読管理
            $table->boolean('reviewed')->default(false);
            $table->timestamps();

            $table->index('received_at');
            $table->index(['reviewed', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_ingests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 面接日時の保存先を companies.next_interview_at から schedule_events へ一本化する。
 *
 * 元のカラムは1社1件しか持てず、2次面接を入れると1次が消える。予定表を追加すると
 * 同じ事実への経路が2本になるため、移送した上で削除する。
 * オファー回答期限は1社1つの「オファーの属性」なので companies に残す。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 既存の次回面接を予定1件として移す（0件でも安全に通る）
        DB::table('companies')
            ->whereNotNull('next_interview_at')
            ->orderBy('id')
            ->get(['id', 'next_interview_at'])
            ->each(fn ($company) => DB::table('schedule_events')->insert([
                'company_id' => $company->id,
                'kind' => 'interview',
                'title' => null,
                'starts_at' => $company->next_interview_at,
                'duration_minutes' => null,
                'place' => null,
                'note' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('next_interview_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dateTime('next_interview_at')->nullable();
        });

        // 面接予定のうち最も近い未来の1件を書き戻す（元のカラムは1件しか持てないため）
        DB::table('companies')->orderBy('id')->pluck('id')->each(function (int $companyId) {
            $next = DB::table('schedule_events')
                ->where('company_id', $companyId)
                ->where('kind', 'interview')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->value('starts_at');

            if ($next !== null) {
                DB::table('companies')->where('id', $companyId)->update(['next_interview_at' => $next]);
            }
        });
    }
};

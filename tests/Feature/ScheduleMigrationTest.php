<?php

namespace Tests\Feature;

use App\Enums\ScheduleKind;
use App\Models\Company;
use App\Models\ScheduleEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * companies.next_interview_at から schedule_events への移送マイグレーション。
 * 実データは移行時点で0件だったが、バックアップからの復元を考えると移送処理は動く必要がある。
 */
class ScheduleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_17_100002_move_next_interview_to_schedule_events.php');
    }

    /** 移送前（カラムがまだある）状態を作り直す */
    private function restoreOldColumn(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dateTime('next_interview_at')->nullable();
        });
    }

    public function test_既存のnext_interview_atが予定に移送される(): void
    {
        $this->restoreOldColumn();
        $company = Company::create(['name' => '移送テスト社']);
        DB::table('companies')->where('id', $company->id)
            ->update(['next_interview_at' => '2026-09-01 10:30:00']);

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('companies', 'next_interview_at'));
        $event = ScheduleEvent::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(ScheduleKind::Interview, $event->kind);
        $this->assertSame('2026-09-01 10:30', $event->starts_at->format('Y-m-d H:i'));
    }

    public function test_next_interview_atが未設定の企業は予定を作らない(): void
    {
        $this->restoreOldColumn();
        Company::create(['name' => '予定なし社']);

        $this->migration()->up();

        $this->assertSame(0, ScheduleEvent::count());
    }

    public function test_ロールバックで直近の面接予定がカラムに戻る(): void
    {
        $company = Company::create(['name' => '戻しテスト社']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => now()->addDays(5)->setTime(10, 0),
        ]);

        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('companies', 'next_interview_at'));
        $restored = DB::table('companies')->where('id', $company->id)->value('next_interview_at');
        $this->assertNotNull($restored);
        $this->assertStringContainsString(now()->addDays(5)->format('Y-m-d'), (string) $restored);
    }

    public function test_ロールバックしても過去の予定は書き戻さない(): void
    {
        $company = Company::create(['name' => '過去のみ社']);
        $company->scheduleEvents()->create([
            'kind' => ScheduleKind::Interview,
            'starts_at' => now()->subDays(5)->setTime(10, 0),
        ]);

        $this->migration()->down();

        // 元のカラムは「次回面接」なので、過ぎた予定を戻すと誤情報になる
        $this->assertNull(DB::table('companies')->where('id', $company->id)->value('next_interview_at'));
    }
}

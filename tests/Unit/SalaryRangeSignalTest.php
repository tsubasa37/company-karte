<?php

namespace Tests\Unit;

use App\Services\SalaryRangeSignal;
use PHPUnit\Framework\TestCase;

class SalaryRangeSignalTest extends TestCase
{
    private SalaryRangeSignal $signal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signal = new SalaryRangeSignal;
    }

    public function test_下限400上限1200は幅が広いと判定し倍率と差額を返す(): void
    {
        $r = $this->signal->evaluate(400, 1200);

        $this->assertTrue($r['isWide']);
        $this->assertSame(3.0, $r['ratio']);
        $this->assertSame(800, $r['spread']);
        $this->assertNull($r['unavailableReason']);
        $this->assertSame('レンジ幅3.0倍（800万差）— 下限採用の可能性を確認', $r['note']);
    }

    public function test_下限500上限800は警告しない(): void
    {
        $r = $this->signal->evaluate(500, 800);

        $this->assertFalse($r['isWide']);
        $this->assertSame(1.6, $r['ratio']);
        $this->assertSame(300, $r['spread']);
        $this->assertNull($r['note']);
        $this->assertNull($r['unavailableReason']);
    }

    public function test_ちょうど2倍はしきい値以上なので警告する(): void
    {
        $r = $this->signal->evaluate(400, 800);

        $this->assertTrue($r['isWide'], 'しきい値 2.0 は「以上」で警告する');
        $this->assertSame(2.0, $r['ratio']);
    }

    public function test_2倍を僅かに下回るケースは警告しない(): void
    {
        // 表示用に丸めると 2.0 になるが、判定は丸める前の値で行う
        $r = $this->signal->evaluate(400, 790);

        $this->assertFalse($r['isWide']);
        $this->assertNull($r['note']);
    }

    public function test_下限がnullなら算出不能を明示する(): void
    {
        $r = $this->signal->evaluate(null, 1200);

        $this->assertFalse($r['isWide']);
        $this->assertNull($r['ratio']);
        $this->assertNull($r['spread']);
        $this->assertTrue($r['hasRange'], '上限の記載はあるのでレンジの主張はある');
        $this->assertSame('年収下限の記載が無いため、レンジ幅を算出できません', $r['unavailableReason']);
    }

    public function test_下限が0なら0除算せず算出不能を明示する(): void
    {
        $r = $this->signal->evaluate(0, 1200);

        $this->assertFalse($r['isWide']);
        $this->assertNull($r['ratio']);
        $this->assertSame('年収下限が0万のため、レンジ幅を算出できません', $r['unavailableReason']);
    }

    public function test_上限がnullなら算出不能を明示する(): void
    {
        $r = $this->signal->evaluate(400, null);

        $this->assertFalse($r['isWide']);
        $this->assertNull($r['ratio']);
        $this->assertTrue($r['hasRange']);
        $this->assertSame('年収上限の記載が無いため、レンジ幅を算出できません', $r['unavailableReason']);
    }

    public function test_下限も上限も無い場合はレンジの主張自体が無い(): void
    {
        $r = $this->signal->evaluate(null, null);

        $this->assertFalse($r['isWide']);
        $this->assertFalse($r['hasRange'], '画面はレンジ幅の注記を出さない（年収セルが「記載なし」を出す）');
        $this->assertSame('年収レンジの数値の記載が無いため、レンジ幅を算出できません', $r['unavailableReason']);
    }
}

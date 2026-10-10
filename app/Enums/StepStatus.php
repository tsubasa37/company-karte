<?php

namespace App\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '未実行',
            self::Running => '実行中',
            self::Done => '完了',
            self::Failed => '失敗',
            self::Skipped => 'スキップ',
        };
    }
}

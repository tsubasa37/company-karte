<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => '待機中',
            self::Running => '分析中',
            self::Done => '完了',
            self::Failed => '失敗',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}

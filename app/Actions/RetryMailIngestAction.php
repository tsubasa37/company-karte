<?php

namespace App\Actions;

use App\Jobs\IngestMailJob;
use App\Models\MailIngest;

/**
 * 失敗した取り込みをやり直す。失敗の記録を消して同じメールをもう一度キューに積む（AI を1回使う）。
 *
 * 記録を消すのは、残したままだと FetchMailAction が「取り込み済み」とみなして二度と積まないため。
 * 成功・非該当の記録はやり直しの対象にしない（AI を使い直す理由が無い）。
 */
class RetryMailIngestAction
{
    public function execute(MailIngest $ingest): void
    {
        if ($ingest->error === null) {
            throw new \InvalidArgumentException('失敗した取り込みだけやり直せます');
        }

        $ingest->delete();

        IngestMailJob::dispatch($ingest->external_id);
    }
}

<?php

namespace App\Actions;

use App\Enums\AiProvider;
use App\Enums\AiPurpose;
use App\Models\AiUsage;
use App\Services\Ai\AiPricing;
use App\Services\Ai\Driver\TokenUsage;
use App\Services\CodexRateLimitReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * AI の使用状況（プロバイダ横断）をまとめる。
 *
 * - providers: 実行先ごとの回数・トークン・推定費用。0 件の実行先も行として出す（AiProvider::cases() 全部）
 * - rateLimit: codex のサブスク利用枠。codex を使う全ツールの合計（このアプリだけではない）。API 系は null
 * - byPurpose / byCompany / byModel: 用途別・企業別・モデル別の内訳
 *
 * サブスク実行（codex）の行には「参考換算」を添える。同じモデル名が OpenAI API の単価表にあれば
 * 「API で同じ実行をしたら幾らだったか」を出す（請求は発生しない目安。実費 costMicroUsd とは別欄）。
 *
 * 件数・合計はすべて PHP（SQL）で確定し、画面は表示（単位変換）だけを行う。
 * トークン・費用の合計は null の行を除いて足し、全行 null なら null（0 で埋めない）。
 * 「費用が出せなかった」理由（単価未登録 / トークン未取得）も件数で返し、画面が 0 円と誤読させないようにする。
 */
class BuildAiUsageAction
{
    /** 集計期間（日）。転職活動の実感に合う長さとして直近30日を既定にする */
    private const DAYS = 30;

    /** 企業別の表示件数（多い順） */
    private const TOP_COMPANIES = 5;

    /**
     * 費用を出せる材料が揃っている行。AiPricing は input か output のどちらかが null なら null を返すので、
     * 「単価未登録」と数えられるのは両方揃っている行だけ（片側 null は「トークン未取得」側）。
     */
    private const TOKENS_PRESENT = '(input_tokens IS NOT NULL AND output_tokens IS NOT NULL)';

    /** 費用を出せなかった理由が「実行先がトークンを返さなかった」側の行（AiPricing の判定と同じ条件） */
    private const TOKENS_MISSING = '(input_tokens IS NULL OR output_tokens IS NULL)';

    public function __construct(
        private readonly CodexRateLimitReader $rateLimit,
        private readonly AiPricing $pricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $since = Carbon::now()->subDays(self::DAYS);
        $providers = $this->providers($since);

        return [
            'days' => self::DAYS,
            'calls' => array_sum(array_column($providers, 'calls')),
            // ボタンを押していないのに走った分（ソース保存・メール取得に付随する自動実行）
            ...$this->autoTotals($since),
            'providers' => $providers,
            'byPurpose' => $this->byPurpose($since),
            'byCompany' => $this->byCompany($since),
            'byModel' => $this->byModel($since),
            'lastAt' => AiUsage::max('created_at'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function providers(Carbon $since): array
    {
        $auto = $this->autoPurposeValues();
        $isAuto = $this->inCondition('purpose', $auto);
        $tokensPresent = self::TOKENS_PRESENT;
        $tokensMissing = self::TOKENS_MISSING;

        $rows = AiUsage::where('created_at', '>=', $since)
            ->selectRaw(<<<SQL
                provider,
                COUNT(*) AS calls,
                SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN {$isAuto} THEN 1 ELSE 0 END) AS auto_calls,
                SUM(input_tokens) AS input_tokens,
                SUM(cached_input_tokens) AS cached_tokens,
                SUM(output_tokens) AS output_tokens,
                SUM(reasoning_tokens) AS reasoning_tokens,
                SUM(cost_micro_usd) AS cost_micro_usd,
                SUM(CASE WHEN cost_micro_usd IS NULL AND {$tokensPresent} THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(CASE WHEN {$tokensMissing} THEN 1 ELSE 0 END) AS untokened_calls
                SQL, $auto)
            ->groupBy('provider')
            ->get()
            ->keyBy(fn (AiUsage $row) => $row->provider->value);

        // 最終実行は期間で絞らない（期間外でも「いつ最後に使ったか」は分かるようにする）
        $lastAt = AiUsage::selectRaw('provider, MAX(created_at) AS last_at')
            ->groupBy('provider')
            ->get()
            ->mapWithKeys(fn (AiUsage $row) => [$row->provider->value => $row->last_at]);

        $reference = $this->subscriptionReference($since);

        return array_map(function (AiProvider $provider) use ($rows, $lastAt, $reference) {
            $row = $rows->get($provider->value);
            $ref = $reference[$provider->value] ?? ['cost' => null, 'unpriced' => 0];

            return [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'isSubprocess' => $provider->isSubprocess(),
                'calls' => (int) ($row?->calls ?? 0),
                'failed' => (int) ($row?->failed ?? 0),
                'autoCalls' => (int) ($row?->auto_calls ?? 0),
                'inputTokens' => $this->intOrNull($row?->input_tokens),
                'cachedTokens' => $this->intOrNull($row?->cached_tokens),
                'outputTokens' => $this->intOrNull($row?->output_tokens),
                'reasoningTokens' => $this->intOrNull($row?->reasoning_tokens),
                'costMicroUsd' => $this->intOrNull($row?->cost_micro_usd),
                // サブスク実行は費用が無いのが正常なので「単価未登録」には数えない
                'unpricedCalls' => $provider->isSubprocess() ? 0 : (int) ($row?->unpriced_calls ?? 0),
                'untokenedCalls' => (int) ($row?->untokened_calls ?? 0),
                // 参考換算はサブスク実行にだけ意味がある。課金される実行先では実費（costMicroUsd）が正
                'referenceCostMicroUsd' => $provider->isSubprocess() ? $ref['cost'] : null,
                'referenceUnpricedCalls' => $provider->isSubprocess() ? $ref['unpriced'] : null,
                'rateLimit' => $provider->isSubprocess() ? $this->rateLimit->read() : null,
                'lastAt' => $lastAt->get($provider->value),
            ];
        }, AiProvider::cases());
    }

    /**
     * 自動実行分（ボタンを押していないのに走った分）の回数と推定費用。
     *
     * 費用のある行が無ければ合計は null になるが、それだけでは「本当に費用が無い（サブスク実行）」のか
     * 「出せなかった」のか区別できないため、課金対象（API）の実行について理由別の件数も返す。
     *
     * @return array{autoCalls: int, autoCostMicroUsd: int|null, autoUnpricedCalls: int, autoUntokenedCalls: int}
     */
    private function autoTotals(Carbon $since): array
    {
        $billable = $this->billableProviderValues();
        $isBillable = $this->inCondition('provider', $billable);
        $tokensPresent = self::TOKENS_PRESENT;
        $tokensMissing = self::TOKENS_MISSING;

        // sum() は全行 null でも 0 を返すため、null を保つ形で取る
        $row = AiUsage::where('created_at', '>=', $since)
            ->whereIn('purpose', $this->autoPurposeValues())
            ->selectRaw(<<<SQL
                COUNT(*) AS calls,
                SUM(cost_micro_usd) AS cost_micro_usd,
                SUM(CASE WHEN cost_micro_usd IS NULL AND {$isBillable} AND {$tokensPresent} THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(CASE WHEN cost_micro_usd IS NULL AND {$isBillable} AND {$tokensMissing} THEN 1 ELSE 0 END) AS untokened_calls
                SQL, [...$billable, ...$billable])
            ->first();

        return [
            'autoCalls' => (int) $row->calls,
            'autoCostMicroUsd' => $this->intOrNull($row->cost_micro_usd),
            'autoUnpricedCalls' => (int) $row->unpriced_calls,
            'autoUntokenedCalls' => (int) $row->untokened_calls,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byPurpose(Carbon $since): array
    {
        return AiUsage::where('created_at', '>=', $since)
            ->selectRaw('provider, purpose, COUNT(*) as calls, SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END) as failed, SUM(cost_micro_usd) as cost_micro_usd')
            ->groupBy('provider', 'purpose')
            ->orderByDesc('calls')
            ->orderBy('purpose')
            ->orderBy('provider')
            ->get()
            ->map(fn (AiUsage $row) => [
                'provider' => $row->provider->value,
                'providerLabel' => $row->provider->label(),
                // 費用 null の意味が実行先で違う（サブスク実行は「無いのが正常」、API は「出せなかった」）
                'isSubprocess' => $row->provider->isSubprocess(),
                'purpose' => $row->purpose->value,
                'label' => $row->purpose->label(),
                'isExplicit' => $row->purpose->isExplicit(),
                'calls' => (int) $row->calls,
                'failed' => (int) $row->failed,
                'costMicroUsd' => $this->intOrNull($row->cost_micro_usd),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int|null, name: string, calls: int}>
     */
    private function byCompany(Carbon $since): array
    {
        // company_id が null になるのは2通り。企業の削除（外部キーの nullOnDelete）と、
        // そもそも企業に紐づかない実行（接続テスト・企業一覧の一括抽出）。
        // null を除外するとこれらの消費が内訳から黙って消えるため、1バケツにまとめて出す
        return AiUsage::where('created_at', '>=', $since)
            ->with('company:id,name')
            ->selectRaw('company_id, COUNT(*) as calls')
            ->groupBy('company_id')
            ->orderByDesc('calls')
            ->limit(self::TOP_COMPANIES)
            ->get()
            ->map(fn (AiUsage $row) => [
                'id' => $row->company_id !== null ? (int) $row->company_id : null,
                'name' => $row->company_id === null ? '（企業に紐づかない実行・削除済み企業）' : $row->company->name,
                'calls' => (int) $row->calls,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byModel(Carbon $since): array
    {
        return AiUsage::where('created_at', '>=', $since)
            ->selectRaw('provider, model, COUNT(*) as calls, SUM(cost_micro_usd) as cost_micro_usd')
            ->groupBy('provider', 'model')
            ->orderByDesc('calls')
            ->orderBy('provider')
            ->orderBy('model')
            ->get()
            ->map(fn (AiUsage $row) => [
                'provider' => $row->provider->value,
                'providerLabel' => $row->provider->label(),
                // 費用 null の意味が実行先で違う（サブスク実行は「無いのが正常」、API は「出せなかった」）
                'isSubprocess' => $row->provider->isSubprocess(),
                // null はプロバイダの既定モデルに任せた実行（codex のみ）
                'model' => $row->model,
                'calls' => (int) $row->calls,
                'costMicroUsd' => $this->intOrNull($row->cost_micro_usd),
            ])
            ->all();
    }

    /**
     * サブスク実行（codex）の参考換算。同じモデル名が OpenAI API の単価表にあれば
     * 「API で同じ実行をしたら幾らだったか」を出す。請求は発生しないので実費とは別欄で返す。
     *
     * 段階単価は1回の入力総量で帯が決まるため、モデル単位に合算せず記録1行ずつ計算する。
     * トークンが取れなかった記録（untokenedCalls で計上済み）は換算対象から外し、
     * トークンはあるが単価を引けなかった記録だけを unpriced に数える。
     *
     * @return array<string, array{cost: int|null, unpriced: int}>
     */
    private function subscriptionReference(Carbon $since): array
    {
        $result = [];

        foreach (AiProvider::cases() as $provider) {
            if (! $provider->isSubprocess()) {
                continue;
            }

            $cost = null;
            $unpriced = 0;

            // 片側だけのトークンでは AiPricing が費用を出せない（= untokenedCalls 側）ので換算対象から外す
            $rows = AiUsage::where('created_at', '>=', $since)
                ->where('provider', $provider->value)
                ->whereNotNull('input_tokens')
                ->whereNotNull('output_tokens')
                ->get(['model', 'input_tokens', 'cached_input_tokens', 'cache_write_input_tokens', 'output_tokens']);

            foreach ($rows as $row) {
                // 単価は OpenAI API の表で引く（codex の実体は OpenAI のモデル）。
                // 単価表の不備（AiPricing の例外）で設定画面ごと落とさない。AiRunner::cost() と同じ扱いで、
                // 換算できなかった1行として数える（参考換算が実際より小さいことは unpriced 件数で画面に出る）
                try {
                    $micro = $this->pricing->costMicroUsd(AiProvider::OpenAi, $row->model, $this->tokensOf($row));
                } catch (\Throwable $e) {
                    Log::warning('AI の参考換算を計算できませんでした（単価表を確認してください）: '.$e->getMessage());
                    $unpriced++;

                    continue;
                }

                if ($micro === null) {
                    $unpriced++;

                    continue;
                }

                $cost = ($cost ?? 0) + $micro;
            }

            $result[$provider->value] = ['cost' => $cost, 'unpriced' => $unpriced];
        }

        return $result;
    }

    /** 記録済みの列を AiPricing が受け取る形に戻す（取れなかった欄は null のまま） */
    private function tokensOf(AiUsage $row): TokenUsage
    {
        return new TokenUsage(
            input: $this->intOrNull($row->input_tokens),
            cached: $this->intOrNull($row->cached_input_tokens),
            cacheWrite: $this->intOrNull($row->cache_write_input_tokens),
            output: $this->intOrNull($row->output_tokens),
        );
    }

    /**
     * 自動実行（ボタンを押さずに走る用途）の保存値。
     *
     * @return array<int, string>
     */
    private function autoPurposeValues(): array
    {
        return array_values(array_map(
            fn (AiPurpose $purpose) => $purpose->value,
            array_filter(AiPurpose::cases(), fn (AiPurpose $purpose) => ! $purpose->isExplicit()),
        ));
    }

    /**
     * 課金される実行先（サブスク実行を除く）の保存値。費用を出せなかった理由を数えるのに使う。
     *
     * @return array<int, string>
     */
    private function billableProviderValues(): array
    {
        return array_values(array_map(
            fn (AiProvider $provider) => $provider->value,
            array_filter(AiProvider::cases(), fn (AiProvider $provider) => ! $provider->isSubprocess()),
        ));
    }

    /**
     * `列 IN (?, ?, …)` の条件式。値が1つも無ければ SQL の `IN ()` は構文エラーになるため、
     * 常に偽になる `0` を返す（該当が 0 件という正しい答えで、失敗を隠すための握り潰しではない）。
     * 例: AiPurpose が全て isExplicit になれば自動実行は 0 件が正しい。
     *
     * @param  array<int, string>  $values
     */
    private function inCondition(string $column, array $values): string
    {
        return $values === [] ? '0' : $column.' IN ('.implode(',', array_fill(0, count($values), '?')).')';
    }

    /** SUM の結果を整数に。null（該当行なし・全行 null）は 0 にせず null のまま返す */
    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

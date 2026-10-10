<?php

namespace App\Http\Controllers;

use App\Actions\IngestClipAction;
use App\Actions\IngestClipListAction;
use App\Enums\SourceType;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClipController extends Controller
{
    /**
     * 投入できる本文の長さ上限。
     * URL 取得経路には 10MB の上限（GuardedHttpFetcher::MAX_BYTES）があるのに、
     * 拡張からの投入だけ無制限だった。実在ページの innerText は 100 万字に届かないので、
     * 通常の利用を妨げずに「1リクエストで DB を膨らませる」ことだけを止める。
     */
    private const MAX_TEXT_LENGTH = 2000000;

    /** ページタイトルの長さ上限。source_documents.title は string(255) */
    private const MAX_TITLE_LENGTH = 255;

    public function store(Request $request, IngestClipAction $action): JsonResponse
    {
        $data = $this->validated($request, [
            // 拡張で投入先企業を選んだ場合のみ入る（無ければ AI が企業を判定する）
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        return response()->json($action->execute(
            $data['title'] ?? '',
            $data['url'] ?? '',
            $data['text'],
            $data['type'] ?? null,
            isset($data['company_id']) ? (int) $data['company_id'] : null,
        ));
    }

    /** 「お気に入り・いいね一覧」ページから企業だけを一括登録する */
    public function storeList(Request $request, IngestClipListAction $action): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(
            $action->execute($data['title'] ?? '', $data['url'] ?? '', $data['text']),
        );
    }

    /** 拡張の「投入先企業」選択UI用（登録済み企業の一覧・名前順） */
    public function companies(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'companies' => Company::orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    /**
     * @param  array<string, array<int, mixed>>  $extraRules  エンドポイント固有の追加ルール
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $extraRules = []): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            // 画面フォーム（StoreSourceDocumentRequest）と同じ基準にする。
            // javascript: や data: を source_documents.url に永続化させないため http/https 限定。
            'url' => ['nullable', 'string', 'url:http,https'],
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            // 拡張のボタンで種別を明示した場合のみ入る（無ければAIが判定）
            'type' => ['nullable', Rule::enum(SourceType::class)],
        ] + $extraRules, [
            // Chrome は location.href の「|」等をエンコードしないため、実在ページの URL でも
            // ここで落ちることがある。どの URL が原因か本人が特定できるよう先頭部分を添える。
            'url.url' => 'URLの形式が不正です（記号を含むURLは失敗することがあります）: '
                .mb_substr((string) $request->input('url'), 0, 60),
        ]);
    }
}

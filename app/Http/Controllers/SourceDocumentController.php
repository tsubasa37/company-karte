<?php

namespace App\Http\Controllers;

use App\Actions\BuildSourcePageAction;
use App\Actions\DeleteSourceDocumentAction;
use App\Actions\UpdateSourceDocumentAction;
use App\Actions\UpsertSourceDocumentAction;
use App\Enums\SourceOrigin;
use App\Enums\SourceType;
use App\Http\Requests\StoreSourceDocumentRequest;
use App\Models\Company;
use App\Models\SourceDocument;
use App\Services\UrlTextFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SourceDocumentController extends Controller
{
    /** URL取得が失敗したときに必ず添える代替手段（エラー文だけでは次の手が分からないため） */
    private const FETCH_HINT = 'ブラウザでページを開いて拡張機能でクリップするか、本文をコピーして「貼り付け」に切り替えてください。';

    /**
     * 取り込んだ本文を全画面で確認する（分析に渡る範囲・口コミ分割結果・変更履歴つき）。
     */
    public function show(SourceDocument $source, BuildSourcePageAction $action): Response
    {
        return Inertia::render('Sources/Show', $action->execute($source) + $this->editProps($source));
    }

    public function store(StoreSourceDocumentRequest $request, Company $company, UrlTextFetcher $fetcher, UpsertSourceDocumentAction $action): RedirectResponse
    {
        $data = $request->validated();

        if (! $request->isUrlOrigin()) {
            // 配列 union（+）は左辺優先で、フォームが url を送っていると null にならない。
            // 貼り付け投入に url が残ると、URL取得失敗→貼り付け切替の流れで失敗した URL が
            // ソースの同一性キーに紛れ込むため、明示的に上書きする
            $action->execute($company, array_merge($data, ['url' => null]));

            return back();
        }

        try {
            $data['raw_text'] = $fetcher->fetch($data['url']);
        } catch (\RuntimeException $e) {
            // 取得失敗はフォームのエラーとして見せる（例外を伝播させると Inertia が全画面遷移になる）
            return back()->withErrors(['url' => $this->fetchError($e)]);
        }

        $action->execute($company, $data + ['fetched_at' => now()]);

        return back();
    }

    /**
     * 既存ソースを同じURLから取り直す（求人票の書き換えに気づくため）。
     * 本文が変われば source_revisions に履歴が積まれ、ソース詳細で差分が見られる。
     * 取得に失敗した場合は既存の raw_text をそのまま残す（空や劣化本文で上書きしない）。
     */
    public function refetch(SourceDocument $source, UrlTextFetcher $fetcher, UpsertSourceDocumentAction $action): RedirectResponse
    {
        abort_if($source->url === null, 404, 'URLの無いソースは再取得できません');

        try {
            $rawText = $fetcher->fetch($source->url);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['refetch' => $this->fetchError($e)]);
        }

        $action->execute($source->company, [
            'type' => $source->type->value,
            'origin' => SourceOrigin::Url->value,
            'url' => $source->url,
            'raw_text' => $rawText,
            'fetched_at' => now(),
        ]);

        return back();
    }

    /** 種別の付け替え・企業の付け替え・面接メモ本文の編集（派生データの作り直しは Action 側） */
    public function update(Request $request, SourceDocument $source, UpdateSourceDocumentAction $action): RedirectResponse
    {
        $action->execute($source, $request->validate([
            'type' => ['required', Rule::enum(SourceType::class)],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            // 面接メモ本文の編集のみ（他種別で来た場合は Action が明示的に弾く）
            'raw_text' => ['nullable', 'string'],
        ]));

        return back();
    }

    public function destroy(SourceDocument $source, DeleteSourceDocumentAction $action): RedirectResponse
    {
        $companyId = $action->execute($source);

        // ソース詳細ページから削除された場合に消えたページへ戻らないよう、企業ページへ返す。
        return redirect()->route('companies.show', $companyId);
    }

    /** 取得できない理由だけでは手が止まるため、代替手段の案内を必ず添える */
    private function fetchError(\RuntimeException $e): string
    {
        return $e->getMessage().' ／ '.self::FETCH_HINT;
    }

    /**
     * ソース詳細の編集UI（種別変更・企業付け替え・再取得・クリーニング結果）に渡す値。
     * BuildSourcePageAction は「本文の見せ方」を担うため、編集UI用の値はここで足す。
     *
     * @return array<string, mixed>
     */
    private function editProps(SourceDocument $source): array
    {
        return [
            'sourceType' => $source->type->value,
            'sourceTypeOptions' => SourceType::options(),
            'companyOptions' => Company::orderBy('name')->get(['id', 'name'])->all(),
            'canRefetch' => $source->url !== null,
            // 拡張クリップ由来はブラウザ内で描画後のテキストを抽出している。サーバ側の再取得は
            // SPA ページだと劣化本文になり、良質な本文を上書きしうるため画面の警告を強める。
            // 手掛かりはクリップ経路だけが保存する AI 一行要約の有無
            // （本筋は origin に専用の値を持たせること。SourceOrigin は別担当の所有ファイル）。
            'likelyClip' => $source->url !== null && $source->summary !== null,
            // 除去した定型行の件数（null = この機能より前の取り込みで未記録）
            'removedLines' => $source->removed_lines === null ? null : (int) $source->removed_lines,
            'cleanerApplied' => $source->type !== SourceType::Interview,
        ];
    }
}

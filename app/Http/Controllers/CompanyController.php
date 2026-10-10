<?php

namespace App\Http\Controllers;

use App\Actions\BuildCompanyPageAction;
use App\Enums\CompanyStatus;
use App\Enums\RunStatus;
use App\Models\Company;
use App\Models\Profile;
use App\Services\CompanyNameNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    /**
     * リクエストのオファー項目 → offer_json のキー。
     * 固定残業・賞与・基本給は「オファー額の実質化」をPHP側で計算・表示するための入力で、
     * 現年収・オファー額と同様に codex には渡さない。
     */
    private const OFFER_FIELDS = [
        'offer_annual_salary' => 'annual_salary',
        'offer_notes' => 'notes',
        'offer_base_monthly_yen' => 'base_monthly_yen',
        'offer_fixed_overtime_hours' => 'fixed_overtime_hours',
        'offer_bonus_months' => 'bonus_months',
    ];

    /** 企業ページのタブ（?tab=）。この4つ以外は指定なし扱いにする */
    private const TABS = ['evaluation', 'interview', 'sources', 'info'];

    /**
     * 旧タブ名 → 現タブ名。カルテタブを「評価」と「面接準備」に割る前は karte 1つだった。
     * ダッシュボード・検索・保存済みURLが今も送ってくるので評価タブとして受ける
     * （画面側の別名表も resources/js/composables/useCompanyTab.ts に同じ対応で持つ）。
     */
    private const TAB_ALIASES = ['karte' => 'evaluation'];

    public function index(): Response
    {
        return Inertia::render('Companies/Index', [
            'companies' => Company::query()->latest('updated_at')->get()->map($this->listRow(...)),
            // オンボーディング用: プロフィール（必須条件）が未設定なら初回導線を出す
            'hasProfile' => filled(Profile::first()?->must_json),
        ]);
    }

    /**
     * 企業一覧の1行分。
     *
     * @return array<string, mixed>
     */
    private function listRow(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'listed' => $c->listed,
            'position' => $c->position,
            'favorite' => $c->favorite,
            'status' => $c->status->value,
            'statusLabel' => $c->status->label(),
            'recommendation' => $c->recommendation?->value,
            'recommendationLabel' => $c->recommendation?->label(),
            'recommendationSymbol' => $c->recommendation?->symbol(),
            'updatedAt' => $c->updated_at?->format('Y-m-d'),
        ];
    }

    public function store(Request $request, CompanyNameNormalizer $names): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'listed' => ['boolean'],
            'position' => ['nullable', 'string', 'max:255'],
            'legal_number' => ['nullable', 'string', 'max:255'],
            // 同名別法人の登録を塞がないため、警告を見た上での続行を明示フラグで受ける
            'confirm_duplicate' => ['boolean'],
        ]);

        $existing = $names->findExisting($data['name']);

        if ($existing !== null && ! ($data['confirm_duplicate'] ?? false)) {
            return $this->duplicateWarning($existing);
        }

        unset($data['confirm_duplicate']);

        return redirect()->route('companies.show', Company::create($data));
    }

    /**
     * 名寄せキーが既存企業と一致した場合の差し戻し。
     * 勝手に既存企業へ寄せたり黙って重複作成したりせず、既存企業名とIDを添えて確認を求める。
     */
    private function duplicateWarning(Company $existing): RedirectResponse
    {
        return back()
            ->withErrors([
                'duplicate' => "「{$existing->name}」（ID {$existing->id}）が同じ名前として登録済みです。"
                    .'同じ企業ならその企業ページで編集し、同名の別法人なら「別法人として登録する」を押してください。',
                // 画面から既存企業ページへ辿れるようにする（文言から ID を読み取らせない）
                'duplicateId' => (string) $existing->id,
            ]);
    }

    public function show(Request $request, Company $company, BuildCompanyPageAction $action): Response
    {
        // ?tab= でタブを URL に載せる（リロード・ソース詳細からの復帰で選択タブを失わない）
        $tab = $request->query('tab');
        $tab = is_string($tab) ? (self::TAB_ALIASES[$tab] ?? $tab) : null;

        return Inertia::render('Companies/Show', $action->execute($company) + [
            'activeTab' => in_array($tab, self::TABS, true) ? $tab : null,
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate($this->updateRules());

        $company->update($this->withOfferJson($data, $company));

        return back();
    }

    public function updateFavorite(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['favorite' => ['required', 'boolean']]);

        $company->update($data);

        return back();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function updateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'listed' => ['boolean'],
            'position' => ['nullable', 'string', 'max:255'],
            'legal_number' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(CompanyStatus::class)],
            'selection_notes' => ['nullable', 'string'],
            // オファー回答期限（1社1つのオファーの属性。面接日程は schedule_events 側で持つ）
            'offer_deadline' => ['nullable', 'date'],
            'offer_annual_salary' => ['nullable', 'integer', 'min:0'],
            'offer_notes' => ['nullable', 'string'],
            // オファー額の実質化用（基本給・固定残業時間・賞与月数）
            'offer_base_monthly_yen' => ['nullable', 'integer', 'min:0'],
            'offer_fixed_overtime_hours' => ['nullable', 'integer', 'min:0', 'max:200'],
            'offer_bonus_months' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ];
    }

    /**
     * オファー入力を offer_json に畳む。送信されたキーだけを既存 offer_json に上書きし、
     * 送信されなかったキー（年収・備考・固定残業など）はそのまま残す
     * （部分更新で既存のオファー内容が黙って消えるのを防ぐ）。
     * オファー系キーが1つも送信されていなければ offer_json に触れない。
     * 全項目が空になったら null に戻す（未提示扱い）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withOfferJson(array $data, Company $company): array
    {
        if (array_intersect_key($data, self::OFFER_FIELDS) === []) {
            return $data;
        }

        $offer = $company->offer_json ?? [];

        foreach (self::OFFER_FIELDS as $field => $key) {
            if (array_key_exists($field, $data)) {
                $offer[$key] = $field === 'offer_notes' ? trim((string) $data[$field]) : $data[$field];
            }
            unset($data[$field]);
        }

        $data['offer_json'] = $this->isEmptyOffer($offer) ? null : $offer;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function isEmptyOffer(array $offer): bool
    {
        return array_filter($offer, fn (mixed $v) => $v !== null && $v !== '') === [];
    }

    public function destroy(Company $company): RedirectResponse
    {
        if ($error = $this->deleteBlockedReason([$company->id])) {
            return back()->withErrors(['delete' => $error]);
        }

        $company->delete();

        return redirect()->route('companies.index');
    }

    /**
     * 一覧のチェックボックスからの一括削除。関連データはDBのカスケードで消える。
     * 存在しないIDが混ざっていたら全体を弾く（半端に消えると何が消えたか分からなくなる）。
     */
    public function destroyBulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:companies,id'],
        ]);

        if ($error = $this->deleteBlockedReason($data['ids'])) {
            return back()->withErrors(['ids' => $error]);
        }

        Company::whereIn('id', $data['ids'])->delete();

        return redirect()->route('companies.index');
    }

    /**
     * 削除できない理由（できるなら null）。分析の実行中に消すと、走っている codex が
     * 削除済み企業のためにサブスクを消費し続け、ジョブは FK エラーで failed_jobs に残る。
     *
     * @param  array<int, int>  $ids
     */
    private function deleteBlockedReason(array $ids): ?string
    {
        $running = Company::whereIn('id', $ids)
            ->whereHas('analysisRuns', fn ($q) => $q->whereIn('status', [RunStatus::Queued, RunStatus::Running]))
            ->pluck('name');

        if ($running->isEmpty()) {
            return null;
        }

        return '分析の実行中は削除できません（'.$running->implode(' / ').'）。完了を待ってから削除してください。';
    }
}

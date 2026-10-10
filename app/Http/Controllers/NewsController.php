<?php

namespace App\Http\Controllers;

use App\Actions\BuildNewsScreeningAction;
use App\Enums\SourceType;
use App\Models\Company;
use App\Services\NewsFeedService;
use App\Services\UrlNormalizer;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    public function __construct(private readonly UrlNormalizer $urls) {}

    /**
     * 企業名でニュースを検索して一覧表示する（取り込みは記事ごとにユーザーが選ぶ）。
     * 取込済みかどうかは source_documents の URL と PHP 側で照合して付与する。
     */
    public function index(Company $company, NewsFeedService $news, BuildNewsScreeningAction $screening): Response
    {
        // 取込済みURL → source_documents.id の対応（手貼りで URL が無いソースは対象外）。
        // Bing News のリンクは毎回変わる追跡ID(tid)を含むため、保存時と同じ UrlNormalizer で
        // 実記事URLに正規化して照合する（正規化の規則を保存側と1本に揃える）
        $importedIds = $company->sourceDocuments()
            ->where('type', SourceType::News)
            ->whereNotNull('url')
            ->pluck('id', 'url')
            ->mapWithKeys(fn ($id, $url) => [$this->urls->normalize($url) => $id]);

        $articles = array_map(fn (array $a) => $a + [
            'imported' => $importedIds->has($this->urls->normalize($a['link'])),
            'sourceId' => $importedIds->get($this->urls->normalize($a['link'])),
        ], $news->search($company->name));

        // 機械判定の結果を付けて並べ替え、判定ボタン用の状態（articles / screening）を足す
        return Inertia::render('Companies/News', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            ...$screening->execute($company, $articles),
        ]);
    }
}

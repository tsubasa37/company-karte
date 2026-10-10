<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // 疎通確認のように「押した結果をその場で出す」ものは flash で受け渡す
            'flash' => [
                'gmailTest' => fn () => $request->session()->get('gmailTest'),
                'aiTest' => fn () => $request->session()->get('aiTest'),
                'aiDecisionTest' => fn () => $request->session()->get('aiDecisionTest'),
                'newsScreen' => fn () => $request->session()->get('newsScreen'),
                'mailFetch' => fn () => $request->session()->get('mailFetch'),
                // 取り込みの取り消しで消さずに残したものの説明（残らなければ届かない）
                'mailRevert' => fn () => $request->session()->get('mailRevert'),
            ],
        ];
    }
}

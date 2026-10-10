<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * 分析プロンプトに渡る項目。これが実際に変わったときだけ
     * analysis_relevant_updated_at を進める（現年収など渡さない項目の変更で
     * 全社に再分析を促すのは誤報になるため）。
     */
    private const ANALYSIS_RELEVANT_KEYS = ['career_summary', 'must_json', 'want_json', 'ng_json', 'axis'];

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        // プロファイルは1件だけ持つ（自分専用アプリのため）
        $profile = Profile::first();

        if ($this->analysisRelevantChanged($profile, $data)) {
            $data['analysis_relevant_updated_at'] = now();
        }

        $profile ? $profile->update($data) : Profile::create($data);

        return back();
    }

    /**
     * 分析に効く項目（キャリア要約・must/want/NG・軸）が実際に変わったか。
     * 送信に含まれないキーは比較対象外。空文字・空配列・null は同じ「未設定」として扱う
     * （画面が null を '' として送るため、値が変わっていないのに変更と誤判定しない）。
     *
     * @param  array<string, mixed>  $data
     */
    private function analysisRelevantChanged(?Profile $profile, array $data): bool
    {
        foreach (self::ANALYSIS_RELEVANT_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $before = $profile?->{$key};

            if (! (blank($before) && blank($data[$key])) && $before !== $data[$key]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'career_summary' => ['nullable', 'string'],
            'must_json' => ['nullable', 'array'],
            'must_json.*' => ['string'],
            'want_json' => ['nullable', 'array'],
            'want_json.*' => ['string'],
            'ng_json' => ['nullable', 'array'],
            'ng_json.*' => ['string'],
            'axis' => ['nullable', 'string'],
            'current_annual_salary' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

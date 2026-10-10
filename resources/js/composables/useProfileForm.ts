import type { ProfileData } from '@/types/company';
import { useForm } from '@inertiajs/vue3';

const toText = (a: string[] | null | undefined) => (a ?? []).join('\n');
const toArr = (t: string) =>
    t.split('\n').map((s) => s.trim()).filter((s) => s.length > 0);

/**
 * プロファイル設定フォーム。条件系（必須/歓迎/NG）は画面では「1行=1条件」のテキスト、
 * 保存時は配列（*_json）に変換して送る。
 */
export function useProfileForm(profile: ProfileData | null) {
    const form = useForm({
        career_summary: profile?.career_summary ?? '',
        mustText: toText(profile?.must_json),
        wantText: toText(profile?.want_json),
        ngText: toText(profile?.ng_json),
        axis: profile?.axis ?? '',
        current_annual_salary: profile?.current_annual_salary ?? null,
    });

    const submit = () => {
        form
            .transform((d) => ({
                career_summary: d.career_summary,
                must_json: toArr(d.mustText),
                want_json: toArr(d.wantText),
                ng_json: toArr(d.ngText),
                axis: d.axis,
                current_annual_salary: d.current_annual_salary,
            }))
            .put('/settings/profile', { preserveScroll: true });
    };

    return { form, submit };
}

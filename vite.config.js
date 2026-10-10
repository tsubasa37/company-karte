import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            /*
             * 書体（UI再デザイン 2026-09）。self-host なのでオフラインでも同じ見た目になる。
             * ここで配る family 名を resources/css/app.css の @theme が --font-serif / --font-sans /
             * --font-mono として指す。片方だけ変えると和文が OS 既定に落ちるので必ず両方直すこと。
             *
             * subsets の既定は ['latin'] で、和文グリフが1文字も落ちてこない。
             * 和文2書体には 'japanese' を明示する。
             * preload は全書体 false。和文は1ウェイトでも重く、初回表示を押し下げるため。
             */
            fonts: [
                // 見出し・企業名・おすすめ度（◎○△―）・読む文章
                bunny('Noto Serif JP', {
                    weights: [400, 600, 700],
                    subsets: ['japanese', 'latin'],
                    preload: false,
                }),
                // UI本文・ラベル・注記
                bunny('Noto Sans JP', {
                    weights: [400, 500, 700],
                    subsets: ['japanese', 'latin'],
                    preload: false,
                }),
                // 数値・ID・トークン数・日時（tabular-nums と併用）
                bunny('JetBrains Mono', {
                    weights: [400, 500],
                    preload: false,
                }),
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

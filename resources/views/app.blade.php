<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'company-karte') }}</title>

        {{-- vite.config.js の bunny() が self-host する書体の @font-face をここで読み込む。
             これが無いと font-family は指定されるのに実体が落ちてこず、和文が OS 既定になる --}}
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-paper text-ink">
        @inertia
    </body>
</html>

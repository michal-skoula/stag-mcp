@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full bg-neutral-100 text-neutral-900 antialiased dark:bg-neutral-950 dark:text-neutral-100">
        <main class="flex min-h-full items-center justify-center px-4 py-12">
            <div class="w-full max-w-sm">
                @if ($title)
                    <h1 class="mb-6 text-xl font-semibold tracking-tight">{{ $title }}</h1>
                @endif

                <div class="rounded-xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    {{ $slot }}
                </div>
            </div>
        </main>
    </body>
</html>

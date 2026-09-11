@props([
    'token' => 'Unknown'
])

<x-layouts.auth title="IS-STAG Authorized">
    <p class="mb-1">Authorization was successful.</p>
    <div class="flex gap-3">
        <p><strong class="text-bold">Token:</strong> {{ substr($token, offset: 0, length: 15) }}...</p>
        <button id="copy-btn" class="cursor-pointer underline">Copy</button>
    </div>

    <form action="{{ route('dashboard') }}" >
        <button
            type="submit"
            class="mt-4 w-full rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800
            dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            Back to dashboard
        </button>
    </form>
    <script>
        document.getElementById('copy-btn').addEventListener('click', async () => {
            await navigator.clipboard.writeText("{{ $token }}");
            alert('Copied token to your clipboard.');
        });
    </script>
</x-layouts.auth>

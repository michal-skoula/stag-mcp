@props([
    'token' => 'Unknown'
])

<x-layouts.auth title="IS-STAG Authorized">
    <p class="mb-1">Authorization was successful.</p>
    <p><strong class="text-bold">Token:</strong> {{ substr($token, offset: 0, length: 15) }}...</p>

    <form action="{{ route('dashboard') }}" >
        <button
            type="submit"
            class="mt-4 w-full rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800
            dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            Back to dashboard
        </button>
    </form>

</x-layouts.auth>

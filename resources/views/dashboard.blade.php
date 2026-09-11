<x-layouts.auth title="Dashboard">
    <p class="text-sm">
        Signed in as <span class="font-medium">{{ auth()->user()->name }}</span>.
    </p>

    <div class="mt-5 space-y-3">

        <livewire:stag-authorization/>

        <livewire:mcp-token/>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit"
                    class="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800">
                Log out
            </button>
        </form>
    </div>
</x-layouts.auth>

<?php

use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public ?string $city = null;

    public bool $saved = false;

    public function mount(): void
    {
        $this->city = $this->user()->preferences?->city;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'city' => ['nullable', 'string', 'max:255'],
        ]);

        $this->user()->preferences()->updateOrCreate([], $validated);

        $this->saved = true;
    }

    private function user(): User
    {
        return auth()->user();
    }
};
?>

<div>
    <h2 class="mb-0.5 text-sm font-bold">City</h2>
    <p class="mb-3 text-sm text-neutral-700 dark:text-neutral-300">
        Used to narrow down STAG buildings and rooms to your campus.
    </p>

    <form wire:submit="save" class="flex gap-2">
        <input
            wire:model="city"
            type="text"
            placeholder="Plzeň"
            aria-label="City"
            class="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
        >

        <button
            type="submit"
            class="shrink-0 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800
            dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            Save
        </button>
    </form>

    @error('city')
        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    @if ($saved)
        <p class="mt-2 text-sm text-green-600 dark:text-green-400">Saved.</p>
    @endif
</div>

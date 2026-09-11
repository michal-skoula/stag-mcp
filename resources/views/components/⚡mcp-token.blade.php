<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** @var string Label for the client this token belongs to, e.g. "Claude Code". */
    public string $name = '';

    /** @var string|null Plaintext token, held only for the render that follows minting. */
    public ?string $plainTextToken = null;

    public function generate(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->plainTextToken = $this->user()->createToken($validated['name'])->plainTextToken;
        $this->name = '';

        unset($this->tokens);
    }

    /**
     * Scoped to the current user's tokens so an id from elsewhere cannot revoke them.
     */
    public function revoke(int $tokenId): void
    {
        $this->user()->tokens()->whereKey($tokenId)->delete();

        $this->plainTextToken = null;

        unset($this->tokens);
    }

    /**
     * @return Collection<int, \Laravel\Sanctum\PersonalAccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return $this->user()->tokens()->latest()->get();
    }

    private function user(): User
    {
        return auth()->user();
    }
};
?>

<div>
    <h2 class="mb-0.5 text-sm font-bold">MCP access tokens</h2>
    <p class="mb-3 text-sm text-neutral-700 dark:text-neutral-300">
        One per client, so you can revoke a single one without disturbing the others.
    </p>

    @if ($plainTextToken)
        <div class="mb-3 rounded-md border border-neutral-300 p-3 dark:border-neutral-700">
            <p class="mb-1 text-xs text-neutral-700 dark:text-neutral-300">
                Copy it now. It will not be shown again.
            </p>
            <div class="flex items-center gap-3">
                <code class="truncate text-xs">{{ $plainTextToken }}</code>
                <button
                    type="button"
                    id="copy-mcp-token"
                    data-token="{{ $plainTextToken }}"
                    class="shrink-0 cursor-pointer text-sm underline"
                >Copy</button>
            </div>
        </div>

        <script>
            document.getElementById('copy-mcp-token').addEventListener('click', async (event) => {
                await navigator.clipboard.writeText(event.target.dataset.token);
                alert('Copied token to your clipboard.');
            });
        </script>
    @endif

    <form wire:submit="generate" class="mb-3 flex gap-2">
        <input
            wire:model="name"
            type="text"
            placeholder="Claude Code"
            aria-label="Client name"
            class="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"
        >

        <button
            type="submit"
            class="shrink-0 rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800
            dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            Generate
        </button>
    </form>

    @error('name')
        <p class="mb-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror

    @if ($this->tokens->isNotEmpty())
        <ul class="divide-y divide-neutral-200 border-t border-neutral-200 dark:divide-neutral-800 dark:border-neutral-800">
            @foreach ($this->tokens as $token)
                <li wire:key="token-{{ $token->id }}" class="flex items-center justify-between gap-3 py-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $token->name }}</p>
                        <p class="text-xs text-neutral-600 dark:text-neutral-400">
                            {{ $token->last_used_at ? 'Last used '.$token->last_used_at->diffForHumans() : 'Never used' }}
                        </p>
                    </div>

                    <button
                        wire:click="revoke({{ $token->id }})"
                        type="button"
                        class="shrink-0 cursor-pointer text-sm underline"
                    >Revoke</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>

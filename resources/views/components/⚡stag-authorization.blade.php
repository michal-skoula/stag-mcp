<?php

use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /** @var string STAG login URL. todo: resolve URL dynamically based on selected school. */
    protected const string STAG_AUTH_BASE_URL = 'https://stag-ws.zcu.cz/ws/login';

    /** @var bool Only support the primary login method set by STAG admins. */
    protected const bool MAIN_LOGIN_METHOD_ONLY = true;

    /** @var string Named route handling STAG token storage. */
    protected const string CALLBACK_ROUTE = 'stag.authorize';

    /** @var bool URL Parameter flag for longer-lived token (ticket). */
    public bool $longLivedToken = true;


    public function mount(): void
    {
        $this->callbackUrl = route('stag.authorize');
    }


    public function stagUrl(): string
    {
        $url = self::STAG_AUTH_BASE_URL;
        $url .= '?originalURL=' . urlencode(route(self::CALLBACK_ROUTE));

        if ($this->longLivedToken) {
            // Callback URL parameter
            $url .= urlencode('?long=1');

            // STAG URL parameter
            $url .= '&longTicket=1';
        }

        if (self::MAIN_LOGIN_METHOD_ONLY) {
            $url .= '&onlyMainLoginMethod=1';
        }

        return $url;
    }

    public function navigate(): RedirectResponse
    {
        return redirect()->away($this->stagUrl(), status: 301);
    }
};
?>

<div>
    <h2 class="text-sm font-bold mb-0.5">Authorize IS-STAG</h2>
    <p class="text-sm text-neutral-700 dark:text-neutral-300 mb-3">You will be redirected to a STAG authorization page.</p>

    <form wire:submit="navigate">
        <label for="longLivedToken" class="flex items-center gap-2 text-sm mb-2">
            <input
                wire:model="longLivedToken"
                id="longLivedToken"
                name="longLivedToken"
                type="checkbox"
                class="rounded border-neutral-300 dark:border-neutral-700 dark:bg-neutral-950"
            >
            Keep token valid for longer
        </label>

        <button
            type="submit"
            class="w-full rounded-md bg-neutral-900 px-3 py-2 text-sm font-medium text-white hover:bg-neutral-800
            dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            Authorize
        </button>
    </form>


</div>

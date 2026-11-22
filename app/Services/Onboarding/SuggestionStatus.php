<?php

namespace App\Services\Onboarding;

enum SuggestionStatus: string
{
    /** Nothing has been fetched for this user yet. */
    case None = 'none';

    /** A fetch is queued or running. Earlier suggestions, if any, are still shown. */
    case Pending = 'pending';

    case Ready = 'ready';

    /** The last fetch gave up. `failure` on the record says why. */
    case Failed = 'failed';
}

<?php

namespace App\Services\Teachers;

use App\Contracts\StagClient;
use App\Exceptions\StagException;

/**
 * A person's business card, from `vizitka/getVizitkaByUcitidno`.
 *
 * The card is the only place STAG reliably records where someone sits:
 * `getUcitelInfo`'s own zkrBudovy and cisloMistnosti are sparse, and agree
 * with the card when present. Student teachers have no card at all, and STAG
 * answers `[]` for them, so an absent card is ordinary rather than an error.
 *
 * Its `uredniHodiny` block is never read. Nobody maintains STAG's copy of
 * office hours, see StudyAdvisorTool::officeHoursNote().
 *
 * @phpstan-type Office array{building: ?string, room: ?string, workplace: ?string}
 */
final readonly class BusinessCard
{
    /**
     * @param  array<string, mixed>  $card
     */
    private function __construct(private array $card) {}

    /**
     * Degrades to an empty card rather than failing, so a refused or missing
     * card still leaves the caller's other details answerable.
     */
    public static function fetch(StagClient $stag, int|string $teacherId): self
    {
        try {
            $card = $stag->get('vizitka/getVizitkaByUcitidno', ['ucitidno' => $teacherId]);
        } catch (StagException) {
            return new self([]);
        }

        return new self(array_is_list($card) ? [] : $card);
    }

    public function exists(): bool
    {
        return $this->card !== [];
    }

    /**
     * STAG lists one phone-directory row per line and repeats the room on each,
     * so a person with two lines in one office yields two identical locations.
     * Empty when the person hid their room on the card.
     *
     * @return list<Office>
     */
    public function offices(): array
    {
        if (($this->card['info']['showMistnost'] ?? null) === 'N') {
            return [];
        }

        $offices = [];

        foreach ($this->card['tsLinky']['linky'] ?? [] as $line) {
            $office = [
                'building' => $line['budova'] ?? null,
                'room' => $line['mistnost'] ?? null,
                'workplace' => $line['zkratkaPracoviste'] ?? null,
            ];

            $offices[implode('|', array_map(strval(...), $office))] = $office;
        }

        return array_values($offices);
    }

    /** When the card was last edited, as an ISO date. */
    public function updated(): ?string
    {
        return $this->card['info']['update'] ?? null;
    }

    /** Personal web page, verbatim. */
    public function website(): ?string
    {
        $website = trim((string) ($this->card['info']['www'] ?? ''));

        return $website === '' ? null : $website;
    }
}

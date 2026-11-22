<?php

namespace App\Services\Reports;

/**
 * Best-effort removal of personal identifiers from what an agent sends in a
 * report. It cannot understand prose, so the tool description also tells the
 * agent not to paste personal data; this is the net underneath.
 */
final class ReportSanitizer
{
    /** Argument names whose values are never kept. Matched anywhere in the key. */
    private const string SENSITIVE_KEY = '/os_?cislo|ticket|token|password|cookie|login|e_?mail|name|jmeno|prijmeni|rodne|birth|narozen|grade|znamk|point|bod|hodnoceni/i';

    private const int MAX_ARGUMENT_LENGTH = 200;

    /**
     * Scrubs a free-text string: student numbers, birth numbers, e-mail
     * addresses, bearer tokens and long token-like strings (STAG tickets).
     */
    public function text(string $text): string
    {
        $patterns = [
            '/\b(osCislo|stagUser|ticket|token|password|WSCOOKIE)(\s*[=:]\s*)\S+/i' => '$1$2[removed]',
            '/\bBearer\s+\S+/i' => 'Bearer [removed]',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
            '/\b[A-Za-z]\d{2}[A-Za-z]\d{4}[A-Za-z]\b/' => '[student-number]',
            '/\b\d{6}\/?\d{3,4}\b/' => '[id-number]',
            '/\b(?=[A-Za-z_-]*\d)[A-Za-z0-9_-]{24,}\b/' => '[token]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? '';
    }

    /**
     * Keeps flat scalar arguments only. A value under a personal key is blanked,
     * and nested values are dropped, since they are the likeliest place for
     * a whole student record to hide.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, scalar|null>
     */
    public function arguments(array $arguments): array
    {
        $clean = [];

        foreach ($arguments as $key => $value) {
            $key = (string) $key;

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $clean[$key] = match (true) {
                preg_match(self::SENSITIVE_KEY, $key) === 1 => '[removed]',
                is_string($value) => mb_substr($this->text($value), 0, self::MAX_ARGUMENT_LENGTH),
                default => $value,
            };
        }

        return $clean;
    }
}

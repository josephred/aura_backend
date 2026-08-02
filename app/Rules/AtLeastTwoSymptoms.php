<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Requires the consultation reason to name at least two symptoms.
 *
 * The clinical history is opened from this text, so a single vague word gives
 * the professional nothing to prepare with. Two distinct complaints
 * ("dolor de cabeza y fiebre") are enough to triage.
 *
 * Accepts the separators people actually type: commas, "y", "e", semicolons,
 * slashes, plus signs and line breaks.
 */
class AtLeastTwoSymptoms implements ValidationRule
{
    /** Minimum length for a fragment to count as a symptom, not a filler word. */
    private const MIN_FRAGMENT_LENGTH = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            $fail('Describe el motivo de consulta antes de continuar.');
            return;
        }

        if (count(self::split($value)) < 2) {
            $fail('Indica al menos dos síntomas o motivos, separados por coma o «y». Por ejemplo: «dolor de cabeza y fiebre».');
        }
    }

    /**
     * Splits free text into symptom fragments.
     *
     * Public so the same rule can be reused elsewhere (and unit-tested)
     * without going through the validator.
     *
     * @return array<int, string>
     */
    public static function split(string $value): array
    {
        // "y"/"e" only count as separators when standing alone as words, so
        // "dolor de cabeza" is not chopped at the "e" inside "cabeza".
        $normalized = preg_replace('/\s+(y|e|más|mas|además|ademas)\s+/iu', ',', $value) ?? $value;
        $fragments = preg_split('/[,;\/\+\n\r]+/u', $normalized) ?: [];

        $clean = [];
        foreach ($fragments as $fragment) {
            $fragment = trim($fragment);
            if (mb_strlen($fragment) >= self::MIN_FRAGMENT_LENGTH) {
                $clean[] = $fragment;
            }
        }

        // Repeating the same complaint twice is not two symptoms.
        return array_values(array_unique(array_map('mb_strtolower', $clean)));
    }

    /** True when the text already names two or more symptoms. */
    public static function passes(?string $value): bool
    {
        return $value !== null && count(self::split($value)) >= 2;
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Exactly one emoji, as a person sees it: one grapheme (so skin tones,
// flags, keycaps and joined sequences like 👨‍👩‍👧 count as one) that's an
// emoji, not a letter, digit or anything else. Chat's reactions and channel
// icons -- whatever the full picker offers.
class SingleEmoji implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ok = is_string($value)
            && mb_strlen($value) <= 16
            && grapheme_strlen($value) === 1
            && preg_match('/\p{Extended_Pictographic}|\p{Regional_Indicator}|\x{20E3}/u', $value) === 1;

        if (! $ok) {
            $fail('Pick a single emoji.');
        }
    }
}

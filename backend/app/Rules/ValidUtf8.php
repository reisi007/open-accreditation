<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects string values that are not valid UTF-8.
 *
 * The `string` and `max` rules accept raw invalid byte sequences (e.g. a
 * form-encoded `name=\xFF`), which then reach the database and the JSON
 * response encoder and turn the request into an HTTP 500 instead of a 422.
 * This rule closes that gap at the validation boundary; non-string values
 * (null on partial updates, ints, …) are left to the surrounding rules.
 */
class ValidUtf8 implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $fail('The :attribute must be valid UTF-8.');
        }
    }
}

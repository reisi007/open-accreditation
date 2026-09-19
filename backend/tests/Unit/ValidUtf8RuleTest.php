<?php

namespace Tests\Unit;

use App\Rules\ValidUtf8;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unit coverage for {@see ValidUtf8}: the rule closes the gap the `string`
 * and `max` rules leave open for raw invalid byte sequences.
 */
class ValidUtf8RuleTest extends TestCase
{
    public function test_it_rejects_invalid_utf8_bytes(): void
    {
        $validator = Validator::make(
            ['name' => "\xFF"],
            ['name' => [new ValidUtf8]],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    public function test_it_accepts_plain_ascii(): void
    {
        $validator = Validator::make(
            ['name' => 'Bundesliga'],
            ['name' => [new ValidUtf8]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_it_accepts_umlauts_and_emoji(): void
    {
        $validator = Validator::make(
            ['name' => 'Südkurve München ⚽'],
            ['name' => [new ValidUtf8]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_it_ignores_non_string_values(): void
    {
        // `null` on a partial update, integers etc. are the surrounding
        // rules' business — this rule must not turn them into errors.
        $validator = Validator::make(
            ['name' => null, 'quota' => 10],
            ['name' => [new ValidUtf8], 'quota' => [new ValidUtf8]],
        );

        $this->assertFalse($validator->fails());
    }
}

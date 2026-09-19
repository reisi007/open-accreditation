<?php

namespace Tests\Feature;

use App\Services\ImageUploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * W6 / W2-F2 L1 — the shared upload contract for public brand media. The
 * extension always derives from the validated MIME type; an unexpected MIME
 * type is a 422, never a silent fallback to the client-supplied extension.
 */
class ImageUploadRulesTest extends TestCase
{
    public function test_extension_is_derived_from_the_mime_type(): void
    {
        $this->assertSame('png', ImageUploadRules::extensionFor(UploadedFile::fake()->image('x.png')));
        $this->assertSame('jpg', ImageUploadRules::extensionFor(UploadedFile::fake()->image('x.jpg')));
        $this->assertSame('webp', ImageUploadRules::extensionFor(UploadedFile::fake()->image('x.webp')));
    }

    public function test_unknown_mime_type_is_rejected_instead_of_falling_back_to_the_client_extension(): void
    {
        $gif = UploadedFile::fake()->image('logo.gif');

        try {
            ImageUploadRules::extensionFor($gif);
            $this->fail('Expected a ValidationException for a non-whitelisted MIME type.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_dimension_limit_accepts_the_exact_boundary(): void
    {
        ImageUploadRules::assertWithinDimensionLimit(
            UploadedFile::fake()->image('ok.png', ImageUploadRules::MAX_DIMENSION, ImageUploadRules::MAX_DIMENSION),
        );

        $this->addToAssertionCount(1);
    }

    public function test_dimension_limit_rejects_oversized_images(): void
    {
        $this->expectException(ValidationException::class);

        ImageUploadRules::assertWithinDimensionLimit(
            UploadedFile::fake()->image('big.png', ImageUploadRules::MAX_DIMENSION + 1, 10),
        );
    }
}

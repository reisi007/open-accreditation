<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * WP-1-e / R-D8: production boot guard for `APP_KEY`.
 *
 * `deployment/docker-compose.yml` shipped `APP_KEY: ${APP_KEY:-base64:AAAA…}` —
 * a WORKING key (32 zero bytes) that is public knowledge because it is
 * committed. Every `Crypt` payload and every HMAC-signed token (QR codes) would
 * be forgeable, silently and without a single error message. The compose side
 * removes the default; this guard is the application-side backstop for every
 * other deployment path.
 */
class ProductionAppKeyGuardTest extends TestCase
{
    /**
     * The exact value the compose file defaulted to.
     */
    private const COMPOSE_PLACEHOLDER = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /* ------------------------------------------------------------------ */
    /* Refusals */
    /* ------------------------------------------------------------------ */

    public function test_production_boot_with_the_compose_placeholder_key_aborts(): void
    {
        config(['app.key' => self::COMPOSE_PLACEHOLDER]);

        $this->asProduction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        AppServiceProvider::assertProductionAppKeyIsStrong();
    }

    public function test_production_boot_with_an_empty_key_aborts(): void
    {
        config(['app.key' => '']);

        $this->asProduction();

        $this->expectException(RuntimeException::class);

        AppServiceProvider::assertProductionAppKeyIsStrong();
    }

    public function test_production_boot_with_a_missing_key_aborts(): void
    {
        config(['app.key' => null]);

        $this->asProduction();

        $this->expectException(RuntimeException::class);

        AppServiceProvider::assertProductionAppKeyIsStrong();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function placeholderKeys(): array
    {
        return [
            'compose default (32 zero bytes)' => [self::COMPOSE_PLACEHOLDER],
            'raw 32 zero bytes' => [str_repeat("\0", 32)],
            'base64 without prefix' => [base64_encode(str_repeat("\0", 32))],
            '32 × A' => [str_repeat('A', 32)],
            'base64 of 32 × A' => ['base64:'.base64_encode(str_repeat('A', 32))],
        ];
    }

    #[DataProvider('placeholderKeys')]
    public function test_every_known_placeholder_key_aborts(string $key): void
    {
        config(['app.key' => $key]);

        $this->asProduction();

        $this->expectException(RuntimeException::class);

        AppServiceProvider::assertProductionAppKeyIsStrong();
    }

    /* ------------------------------------------------------------------ */
    /* Non-refusals */
    /* ------------------------------------------------------------------ */

    public function test_production_boot_with_a_generated_key_continues(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->asProduction();

        AppServiceProvider::assertProductionAppKeyIsStrong();

        $this->addToAssertionCount(1);
    }

    public function test_placeholder_keys_are_tolerated_outside_production(): void
    {
        // Dev/CI must keep booting — `php artisan key:generate` is often the
        // first thing an operator does.
        foreach (['local', 'testing', 'staging'] as $environment) {
            config(['app.key' => self::COMPOSE_PLACEHOLDER]);
            app()->detectEnvironment(fn () => $environment);

            AppServiceProvider::assertProductionAppKeyIsStrong();
        }

        $this->addToAssertionCount(1);
    }

    /* ------------------------------------------------------------------ */
    /* Wiring */
    /* ------------------------------------------------------------------ */

    public function test_the_guard_runs_from_the_provider_boot(): void
    {
        Log::spy();

        config(['app.key' => self::COMPOSE_PLACEHOLDER]);
        $this->asProduction();

        // Re-registering a provider on a booted application re-runs `boot()`,
        // which is exactly what happens on a real production request cycle.
        try {
            $this->app->register(AppServiceProvider::class, true);
            $this->fail('Expected the production boot guard to abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_KEY', $exception->getMessage());
        }

        Log::shouldHaveReceived('critical')->withArgs(
            fn (string $message): bool => str_contains($message, 'Refusing to boot')
        );
    }

    public function test_the_provider_boots_normally_with_a_valid_production_key(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->asProduction();

        $this->app->register(AppServiceProvider::class, true);

        $this->assertTrue(app()->providerIsLoaded(AppServiceProvider::class));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function asProduction(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }
}

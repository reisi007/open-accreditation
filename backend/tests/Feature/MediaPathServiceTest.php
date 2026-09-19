<?php

namespace Tests\Feature;

use App\Services\MediaPathService;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * W1: path contract of the public media layout served by Caddy. The service is
 * pure (no disk/DB access) — this suite pins the path matrix, host
 * normalization edge cases and the traversal hardening.
 */
class MediaPathServiceTest extends TestCase
{
    private MediaPathService $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paths = app(MediaPathService::class);
    }

    public function test_root_file_is_returned_relative_to_the_media_root(): void
    {
        $this->assertSame('logo.svg', $this->paths->rootFile('logo.svg'));
    }

    public function test_domain_file_is_prefixed_with_the_normalized_host(): void
    {
        $this->assertSame(
            'accreditation.test/logo.svg',
            $this->paths->domainFile('accreditation.test', 'logo.svg'),
        );
    }

    public function test_team_file_uses_the_teams_segment(): void
    {
        $this->assertSame(
            'accreditation.test/teams/sv-muster/logo.png',
            $this->paths->teamFile('accreditation.test', 'sv-muster', 'logo.png'),
        );
    }

    public function test_event_type_file_uses_the_event_types_segment(): void
    {
        $this->assertSame(
            'accreditation.test/event-types/bundesliga/logo.png',
            $this->paths->eventTypeFile('accreditation.test', 'bundesliga', 'logo.png'),
        );
    }

    public function test_badge_file_uses_the_badges_segment(): void
    {
        $this->assertSame(
            'accreditation.test/badges/01j0abc.png',
            $this->paths->badgeFile('accreditation.test', '01j0abc.png'),
        );
    }

    public function test_host_neutral_builders_are_prefixed_with_the_mandant_id(): void
    {
        $this->assertSame('_tenants/7/logo.png', $this->paths->hostNeutralFile(7, 'logo.png'));
        $this->assertSame(
            '_tenants/7/teams/sv-muster/logo.png',
            $this->paths->hostNeutralTeamFile(7, 'sv-muster', 'logo.png'),
        );
        $this->assertSame(
            '_tenants/7/event-types/bundesliga/logo.png',
            $this->paths->hostNeutralEventTypeFile(7, 'bundesliga', 'logo.png'),
        );
        $this->assertSame(
            '_tenants/7/badges/01j0abc.png',
            $this->paths->hostNeutralBadgeFile(7, '01j0abc.png'),
        );
    }

    public function test_host_neutral_builder_rejects_a_non_positive_mandant_id(): void
    {
        $this->expectException(DomainException::class);

        $this->paths->hostNeutralFile(0, 'logo.png');
    }

    public function test_the_host_neutral_segment_can_never_be_a_hostname(): void
    {
        // The leading underscore is not a valid hostname label, so a real
        // `<domain>/…` directory can never collide with `_tenants/…`.
        $this->expectException(DomainException::class);

        $this->paths->dirForHost(MediaPathService::HOST_NEUTRAL_SEGMENT);
    }

    public function test_every_builder_returns_a_relative_path_without_a_leading_slash(): void
    {
        $paths = [
            $this->paths->rootFile('logo.svg'),
            $this->paths->domainFile('accreditation.test', 'logo.svg'),
            $this->paths->teamFile('accreditation.test', 'sv-muster', 'logo.png'),
            $this->paths->eventTypeFile('accreditation.test', 'bundesliga', 'logo.png'),
            $this->paths->badgeFile('accreditation.test', 'badge.png'),
        ];

        foreach ($paths as $path) {
            $this->assertStringStartsNotWith('/', $path);
            $this->assertStringNotContainsString('//', $path);
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringNotContainsString('\\', $path);
        }
    }

    public function test_host_is_lower_cased(): void
    {
        $this->assertSame('accreditation.test', $this->paths->dirForHost('ACCREDITATION.TEST'));
    }

    public function test_port_is_stripped_from_the_host(): void
    {
        $this->assertSame('accreditation.test', $this->paths->dirForHost('accreditation.test:8080'));
        $this->assertSame('accreditation.test', $this->paths->dirForHost('ACCREDITATION.TEST:443'));
    }

    public function test_host_is_trimmed(): void
    {
        $this->assertSame('accreditation.test', $this->paths->dirForHost('  accreditation.test  '));
    }

    public function test_already_punycoded_host_is_kept_as_is(): void
    {
        $this->assertSame('xn--mnchen-3ya.de', $this->paths->dirForHost('xn--mnchen-3ya.de'));
    }

    public function test_idn_host_is_punycoded(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->markTestSkipped('ext-intl is not available; the ASCII-only fallback applies.');
        }

        $this->assertSame('xn--mnchen-3ya.de', $this->paths->dirForHost('münchen.de'));
    }

    public function test_local_dev_hosts_are_accepted(): void
    {
        $this->assertSame('localhost', $this->paths->dirForHost('localhost'));
        $this->assertSame('127.0.0.1', $this->paths->dirForHost('127.0.0.1:8000'));
        $this->assertSame('foo.localhost', $this->paths->dirForHost('foo.localhost:5173'));
    }

    #[DataProvider('invalidHostProvider')]
    public function test_invalid_hosts_are_rejected(string $host): void
    {
        $this->expectException(DomainException::class);

        $this->paths->dirForHost($host);
    }

    public static function invalidHostProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'scheme' => ['https://example.com'],
            'path' => ['example.com/path'],
            'traversal' => ['../etc'],
            'double dot' => ['foo..com'],
            'leading dash label' => ['-foo.com'],
            'trailing dash label' => ['foo-.com'],
            'underscore' => ['foo_bar.com'],
            'trailing dot' => ['example.com.'],
            'space inside' => ['foo bar.com'],
            'ipv6 literal' => ['[::1]:8080'],
        ];
    }

    public function test_file_name_is_lower_cased(): void
    {
        $this->assertSame('logo.png', $this->paths->sanitizeFileName('Logo.PNG'));
    }

    #[DataProvider('traversalFileNameProvider')]
    public function test_traversal_file_names_are_rejected(string $name): void
    {
        $this->expectException(DomainException::class);

        $this->paths->sanitizeFileName($name);
    }

    public static function traversalFileNameProvider(): array
    {
        return [
            'parent traversal' => ['../secret.png'],
            'nested traversal' => ['docs/../../secret.png'],
            'absolute unix' => ['/etc/passwd'],
            'windows backslash' => ['..\\secret.png'],
            'backslash' => ['sub\\file.png'],
            'bare double dot' => ['..'],
            'null byte' => ["logo\0.png"],
        ];
    }

    #[DataProvider('unsupportedFileNameProvider')]
    public function test_file_names_with_unsupported_characters_are_rejected(string $name): void
    {
        $this->expectException(DomainException::class);

        $this->paths->sanitizeFileName($name);
    }

    public static function unsupportedFileNameProvider(): array
    {
        return [
            'empty' => [''],
            'space' => ['logo image.png'],
            'at sign' => ['logo@2x.png'],
            'umlaut' => ['lögo.png'],
            'only dot' => ['.'],
        ];
    }

    #[DataProvider('builderTraversalProvider')]
    public function test_builders_reject_traversal_file_names(string $builder, string $host, string $name): void
    {
        $this->expectException(DomainException::class);

        match ($builder) {
            'domain' => $this->paths->domainFile($host, $name),
            'team' => $this->paths->teamFile($host, 'sv-muster', $name),
            'event_type' => $this->paths->eventTypeFile($host, 'bundesliga', $name),
            'badge' => $this->paths->badgeFile($host, $name),
        };
    }

    public static function builderTraversalProvider(): array
    {
        return [
            'domain' => ['domain', 'accreditation.test', '../logo.svg'],
            'team' => ['team', 'accreditation.test', '../../logo.svg'],
            'event_type' => ['event_type', 'accreditation.test', '/etc/passwd'],
            'badge' => ['badge', 'accreditation.test', 'a\\b.png'],
        ];
    }

    public function test_slugs_are_validated(): void
    {
        $this->assertSame('sv-muster', $this->paths->sanitizeSlug('SV-Muster'));
        $this->assertSame('bundesliga_2', $this->paths->sanitizeSlug('bundesliga_2'));
    }

    #[DataProvider('invalidSlugProvider')]
    public function test_invalid_slugs_are_rejected(string $slug): void
    {
        $this->expectException(DomainException::class);

        $this->paths->sanitizeSlug($slug);
    }

    public static function invalidSlugProvider(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../evil'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'dot' => ['a.b'],
            'leading hyphen' => ['-team'],
            'trailing hyphen' => ['team-'],
            'space' => ['sv muster'],
        ];
    }

    public function test_team_and_event_type_builders_reject_traversal_slugs(): void
    {
        $this->expectException(DomainException::class);

        $this->paths->teamFile('accreditation.test', '../evil', 'logo.png');
    }

    public function test_media_disk_is_configured_but_not_publicly_served(): void
    {
        $disk = config('filesystems.disks.media');

        $this->assertIsArray($disk);
        $this->assertSame('local', $disk['driver']);
        $this->assertFalse($disk['serve']);
        $this->assertSame(MediaPathService::DISK, 'media');

        // The media root defaults to storage/app/media. The test environment
        // leaves MEDIA_ROOT unset, so the booted config must already resolve
        // to the default here.
        $this->assertSame(storage_path('app/media'), $disk['root']);

        // `.env.example` ships `MEDIA_ROOT=` (present but blank). Re-evaluate
        // the config with that exact blank value: it must fall back to the
        // default instead of collapsing the disk root to '' (which would make
        // the disk write relative to the process CWD).
        $this->assertSame(
            storage_path('app/media'),
            $this->mediaRootWithBlankEnv(),
        );
    }

    /**
     * Resolve `filesystems.disks.media.root` with `MEDIA_ROOT` explicitly set
     * to an empty string, restoring the process environment afterwards.
     */
    private function mediaRootWithBlankEnv(): mixed
    {
        $previousEnv = $_ENV['MEDIA_ROOT'] ?? null;
        $previousServer = $_SERVER['MEDIA_ROOT'] ?? null;
        $previousPutenv = getenv('MEDIA_ROOT');

        $_ENV['MEDIA_ROOT'] = '';
        $_SERVER['MEDIA_ROOT'] = '';
        putenv('MEDIA_ROOT=');

        try {
            $config = require config_path('filesystems.php');
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['MEDIA_ROOT']);
            } else {
                $_ENV['MEDIA_ROOT'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['MEDIA_ROOT']);
            } else {
                $_SERVER['MEDIA_ROOT'] = $previousServer;
            }

            if ($previousPutenv === false) {
                putenv('MEDIA_ROOT');
            } else {
                putenv('MEDIA_ROOT='.$previousPutenv);
            }
        }

        return $config['disks']['media']['root'];
    }
}

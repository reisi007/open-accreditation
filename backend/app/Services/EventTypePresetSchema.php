<?php

namespace App\Services;

use App\Models\BadgeTemplate;
use App\Models\Category;
use Illuminate\Validation\ValidationException;

/**
 * Fachliches schema for `event_types.presets` (W3), schema version `v = 1`.
 *
 * Strict whitelist: unknown keys, wrong scalar types and out-of-range values
 * are rejected with a 422 on the exact leaf key (`presets.<path>`). The
 * structural envelope (object root, depth ≤ 3, scalar leaves, ≤ 16 KB, valid
 * UTF-8) is owned by `EventTypeController::assertPresetsValid()` and runs
 * before this class — the envelope is deliberately not duplicated here.
 *
 * Versioning: any non-empty preset set MUST declare `v`. Changing or widening
 * the contract is always a new version; `v = 1` never grows silently, so a
 * future resolver can branch on the persisted version.
 *
 * Tenant safety: references (`badge_template_id`, `category_slugs`) are
 * resolved against the current mandant only. A foreign id/slug can never be
 * persisted (no cross-mandant leak through the JSON envelope).
 *
 * Fallback semantics (see `features/event-type-presets.md`): a dangling
 * reference (template/category deleted after the preset was written) is not an
 * error at read time — the resolver ignores it and falls through to the
 * mandant default. Write-time existence keeps the stored envelope clean.
 */
final class EventTypePresetSchema
{
    /**
     * Persisted schema version. `presets.v` must equal this for every
     * non-empty envelope.
     */
    public const VERSION = 1;

    /** Upper bound for `defaults.quota` (plausibility guard, not a domain max). */
    public const MAX_QUOTA = 100000;

    public const MIN_DEADLINE_OFFSET_DAYS = 1;

    /** A deadline offset may span at most one year. */
    public const MAX_DEADLINE_OFFSET_DAYS = 365;

    public const MAX_CATEGORY_SLUGS = 50;

    public const MAX_CATEGORY_SLUG_LENGTH = 120;

    /**
     * Same slug contract as `categories.slug` (`CategoryController`).
     */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Whitelisted consent templates (Einverständniserklärung). v1 ships the
     * built-in `standard` template only; new templates are a v2 concern.
     */
    private const CONSENT_TEMPLATES = ['standard'];

    /**
     * Whitelisted accreditation print layouts (Akkreditierungsdruck):
     * `badge` = one A6 card per approved application (existing
     * `BadgeExportService`), `list` = printed table/list.
     */
    private const ACCREDITATION_LAYOUTS = ['badge', 'list'];

    private const ROOT_KEYS = [
        'v',
        'badge_template_id',
        'consent_pdf',
        'accreditation_pdf',
        'defaults',
    ];

    private const CONSENT_KEYS = ['enabled', 'template'];

    private const ACCREDITATION_KEYS = ['layout'];

    private const DEFAULTS_KEYS = ['quota', 'deadline_offset_days', 'auto_approve', 'category_slugs'];

    /**
     * Validate a (structurally already checked) `presets` envelope against the
     * fachliches schema. An empty envelope (`{}` / `[]`) means "no presets" and
     * is accepted without a version.
     *
     * @param  array<array-key, mixed>  $presets
     *
     * @throws ValidationException with one message per violated leaf key
     */
    public function validate(array $presets, int $mandantId): void
    {
        if ($presets === []) {
            return;
        }

        $errors = [];

        $this->validateVersion($presets, $errors);
        $this->rejectUnknownKeys($presets, self::ROOT_KEYS, 'presets', $errors);

        if (array_key_exists('badge_template_id', $presets)) {
            $this->validateBadgeTemplateId($presets['badge_template_id'], $mandantId, $errors);
        }

        if (array_key_exists('consent_pdf', $presets)) {
            $this->validateConsentPdf($presets['consent_pdf'], $errors);
        }

        if (array_key_exists('accreditation_pdf', $presets)) {
            $this->validateAccreditationPdf($presets['accreditation_pdf'], $errors);
        }

        if (array_key_exists('defaults', $presets)) {
            $this->validateDefaults($presets['defaults'], $mandantId, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<array-key, mixed>  $presets
     * @param  array<string, string>  $errors
     */
    private function validateVersion(array $presets, array &$errors): void
    {
        if (! array_key_exists('v', $presets)) {
            $errors['presets.v'] = 'Presets must declare a schema version in `v`.';

            return;
        }

        if (! is_int($presets['v']) || $presets['v'] !== self::VERSION) {
            $errors['presets.v'] = sprintf('Unsupported presets schema version; expected %d.', self::VERSION);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $allowed
     * @param  array<string, string>  $errors
     */
    private function rejectUnknownKeys(array $value, array $allowed, string $prefix, array &$errors): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[$prefix.'.'.$key] = sprintf('Unknown preset key `%s`.', (string) $key);
            }
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validateBadgeTemplateId(mixed $value, int $mandantId, array &$errors): void
    {
        if (! is_int($value) || $value < 1) {
            $errors['presets.badge_template_id'] = 'badge_template_id must be a positive integer.';

            return;
        }

        $exists = BadgeTemplate::query()
            ->forMandant($mandantId)
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $errors['presets.badge_template_id'] = 'badge_template_id does not reference a badge template of this mandant.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validateConsentPdf(mixed $value, array &$errors): void
    {
        if (! $this->isAssocObject($value)) {
            $errors['presets.consent_pdf'] = 'consent_pdf must be a JSON object.';

            return;
        }

        /** @var array<array-key, mixed> $value */
        $this->rejectUnknownKeys($value, self::CONSENT_KEYS, 'presets.consent_pdf', $errors);

        if ($value === []) {
            $errors['presets.consent_pdf'] = 'consent_pdf must define at least `enabled`.';

            return;
        }

        if (! array_key_exists('enabled', $value)) {
            $errors['presets.consent_pdf.enabled'] = 'consent_pdf.enabled is required and must be a boolean.';
        } elseif (! is_bool($value['enabled'])) {
            $errors['presets.consent_pdf.enabled'] = 'consent_pdf.enabled must be a boolean.';
        }

        if (array_key_exists('template', $value)
            && (! is_string($value['template']) || ! in_array($value['template'], self::CONSENT_TEMPLATES, true))) {
            $errors['presets.consent_pdf.template'] = sprintf(
                'consent_pdf.template must be one of: %s.',
                implode(', ', self::CONSENT_TEMPLATES),
            );
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validateAccreditationPdf(mixed $value, array &$errors): void
    {
        if (! $this->isAssocObject($value)) {
            $errors['presets.accreditation_pdf'] = 'accreditation_pdf must be a JSON object.';

            return;
        }

        /** @var array<array-key, mixed> $value */
        $this->rejectUnknownKeys($value, self::ACCREDITATION_KEYS, 'presets.accreditation_pdf', $errors);

        if ($value === []) {
            $errors['presets.accreditation_pdf'] = 'accreditation_pdf must define `layout`.';

            return;
        }

        if (! array_key_exists('layout', $value)) {
            $errors['presets.accreditation_pdf.layout'] = 'accreditation_pdf.layout is required.';

            return;
        }

        if (! is_string($value['layout']) || ! in_array($value['layout'], self::ACCREDITATION_LAYOUTS, true)) {
            $errors['presets.accreditation_pdf.layout'] = sprintf(
                'accreditation_pdf.layout must be one of: %s.',
                implode(', ', self::ACCREDITATION_LAYOUTS),
            );
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validateDefaults(mixed $value, int $mandantId, array &$errors): void
    {
        if (! $this->isAssocObject($value)) {
            $errors['presets.defaults'] = 'defaults must be a JSON object.';

            return;
        }

        /** @var array<array-key, mixed> $value */
        $this->rejectUnknownKeys($value, self::DEFAULTS_KEYS, 'presets.defaults', $errors);

        if ($value === []) {
            $errors['presets.defaults'] = 'defaults must define at least one default.';

            return;
        }

        if (array_key_exists('quota', $value)) {
            if (! is_int($value['quota']) || $value['quota'] < 0) {
                $errors['presets.defaults.quota'] = 'defaults.quota must be a non-negative integer.';
            } elseif ($value['quota'] > self::MAX_QUOTA) {
                $errors['presets.defaults.quota'] = sprintf('defaults.quota must not exceed %d.', self::MAX_QUOTA);
            }
        }

        if (array_key_exists('deadline_offset_days', $value)) {
            $offset = $value['deadline_offset_days'];

            if (! is_int($offset)
                || $offset < self::MIN_DEADLINE_OFFSET_DAYS
                || $offset > self::MAX_DEADLINE_OFFSET_DAYS) {
                $errors['presets.defaults.deadline_offset_days'] = sprintf(
                    'defaults.deadline_offset_days must be an integer between %d and %d.',
                    self::MIN_DEADLINE_OFFSET_DAYS,
                    self::MAX_DEADLINE_OFFSET_DAYS,
                );
            }
        }

        if (array_key_exists('auto_approve', $value) && ! is_bool($value['auto_approve'])) {
            $errors['presets.defaults.auto_approve'] = 'defaults.auto_approve must be a boolean.';
        }

        if (array_key_exists('category_slugs', $value)) {
            $this->validateCategorySlugs($value['category_slugs'], $mandantId, $errors);
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validateCategorySlugs(mixed $value, int $mandantId, array &$errors): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors['presets.defaults.category_slugs'] = 'defaults.category_slugs must be a JSON array of slugs.';

            return;
        }

        if (count($value) > self::MAX_CATEGORY_SLUGS) {
            $errors['presets.defaults.category_slugs'] = sprintf(
                'defaults.category_slugs must not contain more than %d entries.',
                self::MAX_CATEGORY_SLUGS,
            );

            return;
        }

        /** @var array<string, int> $indexBySlug */
        $indexBySlug = [];

        foreach ($value as $index => $slug) {
            if (! is_string($slug)
                || strlen($slug) > self::MAX_CATEGORY_SLUG_LENGTH
                || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                $errors['presets.defaults.category_slugs.'.$index] = sprintf(
                    'Each category slug must match %s and be at most %d characters.',
                    self::SLUG_PATTERN,
                    self::MAX_CATEGORY_SLUG_LENGTH,
                );

                continue;
            }

            if (array_key_exists($slug, $indexBySlug)) {
                $errors['presets.defaults.category_slugs.'.$index] = sprintf('Duplicate category slug `%s`.', $slug);

                continue;
            }

            $indexBySlug[$slug] = (int) $index;
        }

        if ($indexBySlug === []) {
            return;
        }

        /** @var list<string> $existing */
        $existing = Category::query()
            ->forMandant($mandantId)
            ->whereIn('slug', array_keys($indexBySlug))
            ->distinct()
            ->pluck('slug')
            ->all();

        foreach ($indexBySlug as $slug => $index) {
            if (! in_array($slug, $existing, true)) {
                $errors['presets.defaults.category_slugs.'.$index] = sprintf(
                    'Unknown category slug `%s` for this mandant.',
                    $slug,
                );
            }
        }
    }

    /**
     * A JSON object is an assoc map; an empty array is treated as an empty
     * object (the strictness check for content happens in the caller).
     */
    private function isAssocObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }
}

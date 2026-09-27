<?php

namespace App\Models;

use App\Support\LikeSearch;
use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-uploaded photo/attachment stored on the private (auth-gated) disk.
 * The stored `path` is never served directly; it is streamed through the
 * authenticated delivery endpoint only.
 */
#[Fillable(['user_id', 'type', 'path', 'mime', 'size', 'original_name'])]
class UserMedia extends Model
{
    use HasFactory;

    /**
     * Route-model-binding safety net (M2): a media row is only resolved when it
     * belongs to the current mandant (host-derived), so a cross-tenant token
     * replay can no longer use the 404-vs-403 split as an existence oracle.
     *
     * `SubstituteBindings` runs BEFORE `EnsureMandantMembership` (see
     * `bootstrap/app.php`), so an unscoped binding answers 404 only when the
     * row is missing; a bound foreign row survives to the membership check and
     * answers 403 — the two are distinguishable. Every other bound model is
     * mandant-scoped in its own `resolveRouteBindingQuery()`; `user_media` was
     * the exception because it carries NO `mandant_id` column at all (a single
     * account may hold roles in several mandants and upload under each
     * mandant's namespace, so `AuthMeTest` pins that the caller's own media is
     * deliberately NOT mandant-FILTERED).
     *
     * The one stored tenant marker is the storage path. `UserMediaService`
     * builds it as `user-media/{mandantSlug}/{userId}/{type}/{uuid}.{ext}` —
     * the contract every upload test asserts (`user-media/verband-a/…`) — so
     * scoping the default binding by that prefix makes a foreign row resolve
     * to `null` exactly like an unknown id. The caller's own rows and the
     * admin delivery route (applicants of the current mandant) still resolve:
     * they are stored below the current mandant's slug.
     *
     * The slug is escaped as a LITERAL before it reaches the pattern and the
     * clause carries an explicit `ESCAPE '\'`: `LIKE` treats `%`/`_` as
     * wildcards, and `_` matches ANY single character, so an unescaped slug
     * `verband_a` would also match the foreign path prefix
     * `user-media/verbandXa/…`. The predicate must not depend on every writer
     * that can produce a slug (`MandantController` validates, the factory and
     * the seeder do not have to) staying disciplined — one slug with a `_` or
     * a `%` re-opens the oracle this binding exists to close. `ESCAPE '\'` plus
     * `LikeSearch::escape()` is the same portable construct `PortalController`
     * and `LikeSearchTest` already pin on BOTH engines (§2; SQLite has no
     * default escape character). Without a resolved mandant (seeders, console
     * commands, tests) the binding stays unscoped, mirroring
     * `Accreditation::resolveRouteBindingQuery()`.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (MandantContext::hasCurrent()) {
            $slug = MandantContext::current()?->slug;

            if (is_string($slug) && $slug !== '') {
                // The table name is the model's own, not user input; the slug
                // stays a bound parameter (only the constant clause is raw).
                $query->whereRaw(
                    $query->getQuery()->from.".path like ? escape '\\'",
                    ['user-media/'.LikeSearch::escape($slug).'/%'],
                );
            }
        }

        return $query;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The authenticated delivery URL for this file.
     */
    public function url(): string
    {
        return route('api.user.media.show', ['media' => $this->id]);
    }
}

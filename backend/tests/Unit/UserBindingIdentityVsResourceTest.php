<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The model must not re-assert the claim F1 existed to correct (2026-09-29).
 *
 * ## What F1 was
 *
 * `User::resolveRouteBindingQuery()` reuses ONE predicate for its two callers,
 * and that predicate ORs in the GLOBAL `super_admin` branch: an account with a
 * `role_user` row carrying `mandant_id = null` + `team_id = null` resolves on
 * every mandant's host. That is correct for the IDENTITY question and wrong as
 * the whole RESOURCE question, and the hole was real: a `mandant_admin` could
 * hard-delete the global super admin. `21d4878` fixed the controller, its
 * tests and `features/auth/01-auth-and-roles.md`.
 *
 * ## Why this test exists at all
 *
 * It left the MODEL. And `User.php` is the natural file a reader opens about
 * route binding, so the original false reasoning — "the identity check AND the
 * resource check are deliberately the ONE rule, so they cannot drift apart",
 * and "a target that resolves is a target whose roles the controller may
 * legitimately replace" — stayed standing in the source, one commit and one
 * careless revert away from being true in the code again.
 *
 * A docblock is not behaviour, so this asserts the DOC: each of the three
 * docblocks that used to carry the false claim has to name the corrected
 * contract. Reverting the wording turns these red, which is the point — §3
 * holds that a contract only protects while somebody checks it, and the thing
 * at risk here is prose that reads as a specification.
 *
 * `ReflectionMethod::getDocComment()` rather than a file grep: each assertion
 * is bound to one method, so renaming or deleting a docblock fails instead of
 * quietly turning the check into a no-op.
 */
class UserBindingIdentityVsResourceTest extends TestCase
{
    /**
     * The three docblocks that carried the false claim.
     *
     * @return array<string, array{string}>
     */
    public static function bindingDocblockProvider(): array
    {
        return [
            'the membership gate' => ['isMemberOfMandant'],
            'the shared membership constraint' => ['constrainToMandantMembership'],
            'the route binding' => ['resolveRouteBindingQuery'],
        ];
    }

    #[DataProvider('bindingDocblockProvider')]
    public function test_the_docblock_says_which_of_the_two_questions_it_answers(string $method): void
    {
        $docblock = (new ReflectionMethod(User::class, $method))->getDocComment();

        $this->assertIsString(
            $docblock,
            "User::{$method}() has no docblock at all — the claim this test pins is not written "
            .'down anywhere, which is where it came back from the first time.',
        );

        $this->assertStringContainsString(
            'assertMandantScopedTarget',
            $docblock,
            "User::{$method}() must name the method that asks the RESOURCE question, so a reader "
            .'cannot conclude from this file that the binding answers both.',
        );

        $this->assertMatchesRegularExpression(
            '/\bIDENTITY\b/',
            $docblock,
            "User::{$method}() must say explicitly that it answers the IDENTITY question. F1 was "
            .'a reader concluding the opposite, and this wording is the only place that '
            .'conclusion is formed.',
        );
    }

    /**
     * The one sentence that has to stay gone, verbatim.
     *
     * The rest of this file is positive (say this, name that). This one is
     * negative, because it is the phrasing that survives a careless copy: it
     * reads as a justification, not as a claim, so it is exactly what a
     * rebase or a partial revert would carry back into the file.
     */
    public function test_the_binding_no_longer_promises_that_a_resolved_target_may_be_written_to(): void
    {
        $docblock = (new ReflectionMethod(User::class, 'resolveRouteBindingQuery'))->getDocComment();

        $this->assertIsString(
            $docblock,
            'User::resolveRouteBindingQuery() has no docblock — a negative assertion over an '
            .'absent one is not evidence of anything.',
        );

        $this->assertStringNotContainsString(
            'may legitimately replace',
            $docblock,
            'The route binding is the IDENTITY check and nothing more. A global super_admin '
            .'satisfies it on every host and is in no mandant\'s user list, so "a target that '
            .'resolves is a target whose roles may be written" is false — measured before the '
            .'guard existed: for that target `PUT …/roles` answered 404 while `DELETE` answered '
            .'200 and took the row.',
        );
    }
}

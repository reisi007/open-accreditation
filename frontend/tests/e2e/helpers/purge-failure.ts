/**
 * A row the purge MATCHED and could not reclaim.
 *
 * A separate class from a plain `Error` because the two must be treated
 * differently by the caller, and conflating them is what made F1 invisible:
 * `purgeAllE2EArtifacts` wrapped its whole body in one
 * `try { … } catch { console.warn }`, so a **409 on 53 venue deletes** and a
 * backend that is simply not running produced the same shrug. They are not the
 * same event. A backend that is down must not fail a run that would otherwise
 * report on its own merits; a matched row that survives its own delete means the
 * next run inherits it, and that is the bug this whole mechanism exists to
 * prevent — so it fails the run loudly, with every failed row listed.
 *
 * It lives in its own module so that BOTH reclamation nets can throw it without
 * importing each other: the serial name sweep (`global-teardown.ts` →
 * `purgeAllE2EArtifacts`) and the per-test ownership teardown
 * (`helpers/ownership.ts`). Shared class, shared semantics: "a row this suite
 * created or matched did not go back" is one failure class, not two.
 */
export class PurgeReclamationFailure extends Error {}

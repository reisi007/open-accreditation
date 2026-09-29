export interface UserRole {
    slug: string;
    name: string;
    mandant_id: number | null;
    team_id: number | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    /**
     * The mandant resolved from the request host (backend MandantContext).
     * Null when no mandant matches the host. super_admin uses this to show the
     * teams/resources of the CURRENT mandant instead of the primary one.
     */
    current_mandant_id: number | null;
    roles: UserRole[];
}

export interface SmtpConfig {
    host: string | null;
    port: number | null;
    username: string | null;
    password?: string | null;
    encryption: string | null;
}

export interface MandantDomain {
    id: number;
    hostname: string;
}

export interface Mandant {
    id: number;
    slug: string;
    name: string;
    logo_url: string | null;
    header_url: string | null;
    impressum_text: string | null;
    privacy_text: string | null;
    smtp_config: SmtpConfig | null;
    smtp_has_password: boolean;
    teams_enabled: boolean;
    is_primary: boolean;
    is_active: boolean;
    domains: MandantDomain[];
    teams_count: number;
}

/**
 * A venue as embedded in a team/event resource (W12): the reference plus the
 * RESOLVED name, so a form can render the current value without waiting for the
 * venue list. `null` when nothing is referenced.
 *
 * Note the asymmetry with the public portal resources, which keep the plain
 * name string (`PortalTeam.home_venue`, `PortalEvent.venue`): the portal must
 * not expose ids, the admin forms need both.
 */
export interface VenueReference {
    id: number;
    name: string;
}

/**
 * Mandant-scoped venue master data (W12). One list per mandant, referenced by
 * both teams (`venue_id`) and events (`venue_id`).
 *
 * A venue is DEACTIVATED, never deleted, while it is referenced — that is why
 * `teams_count` / `events_count` travel with it: the admin area needs them to
 * decide between "deactivate" (the primary action) and "delete" (the escape
 * hatch for unreferenced rows).
 */
export interface Venue {
    id: number;
    name: string;
    is_active: boolean;
    teams_count: number;
    events_count: number;
    created_at: string;
    updated_at: string;
}

export interface Team {
    id: number;
    mandant_id: number;
    slug: string;
    name: string;
    /** Reference into the mandant's `venues` list (W12). */
    venue_id: number | null;
    /** Resolved venue of `venue_id`. */
    venue: VenueReference | null;
    created_at: string;
}

export interface UserRoleAssignment {
    role: { slug: string; name: string };
    mandant_id: number | null;
    team_id: number | null;
    team: { id: number; name: string } | null;
}

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    /**
     * Loaded by `withCount()` in `UserController::index()`. They exist because
     * a deletion confirmation has to NAME the number of applications BEFORE
     * the delete — afterwards the rows are gone and a count read then is
     * always zero.
     */
    applications_count: number;
    sub_applications_count: number;
    roles: UserRoleAssignment[];
}

/**
 * The own account as `GET /api/user/account` reports it: identity plus every
 * count a deletion confirmation has to name.
 *
 * This is the self-service counterpart of `AdminUser` and deliberately a
 * DIFFERENT resource: the accreditation profile (`PUT /api/user/profile`, see
 * `User` fields like `title`/`press_id`) is a different feature from account
 * termination. Naming both "profile" would hide that.
 */
export interface AccountSummary {
    id: number;
    name: string;
    email: string;
    mandant_id: number | null;
    /** `null` when the mandant row is gone; never a reason to block the page. */
    mandant_name: string | null;
    applications_count: number;
    sub_applications_count: number;
    media_count: number;
}

/**
 * What a deletion actually removed, measured INSIDE the deletion transaction.
 *
 * `media_files_left_over` is a `string[]` of storage paths and is NOT an
 * error: a file that cannot be unlinked must never turn a completed account
 * deletion into a failed request — the account is gone either way, and the
 * residue is what an operator has to know about. An empty array is the normal
 * case; a non-empty one is a WARNING.
 */
export interface AccountDeletionResult {
    applications_deleted: number;
    sub_applications_deleted: number;
    media_files_deleted: number;
    role_assignments_deleted: number;
    sessions_deleted: number;
    media_files_left_over: string[];
}

export interface Category {
    id: number;
    mandant_id: number;
    team_id: number | null;
    name: string;
    slug: string;
    description: string | null;
    is_team_override: boolean;
    team: { id: number; name: string } | null;
}

export interface Event {
    id: number;
    mandant_id: number;
    team_id: number | null;
    title: string;
    date: string | null;
    /** Reference into the mandant's `venues` list (W12). */
    venue_id: number | null;
    /** Resolved venue of `venue_id`. */
    venue: VenueReference | null;
    competition: string | null;
    deadline_start: string | null;
    deadline_end: string | null;
    active: boolean;
    team: { id: number; name: string } | null;
}

export interface PortalTeam {
    id: number;
    name: string;
    home_venue: string | null;
}

export interface PortalMandant {
    id: number;
    slug: string;
    name: string;
    logo_url: string | null;
    header_url: string | null;
    impressum_text: string | null;
    privacy_text: string | null;
    teams_enabled: boolean;
}

export interface PortalOverview {
    mandant: PortalMandant;
    teams: PortalTeam[];
}

export interface PortalEvent {
    id: number;
    team_id: number | null;
    title: string;
    date: string | null;
    venue: string | null;
    competition: string | null;
    deadline_end: string | null;
    active: boolean;
    team: { id: number; name: string } | null;
}

export interface PortalEventDetail extends PortalEvent {
    venue_effective: string | null;
    deadline_effective: string | null;
    contact: { name: string; email: string } | null;
}

export type AccreditationScope = 'event' | 'league' | 'season';

export interface AccreditationReference {
    id: number;
    name: string;
}

export interface AccreditationEventReference {
    id: number;
    title: string;
    date: string | null;
}

export interface Accreditation {
    id: number;
    category_id: number;
    category: AccreditationReference | null;
    scope: AccreditationScope;
    event_id: number | null;
    event: AccreditationEventReference | null;
    team_id: number | null;
    team: AccreditationReference | null;
    quota: number;
    applications_count: number;
    available: number;
    deadline_start: string | null;
    deadline_end: string | null;
    auto_approve: boolean;
    active: boolean;
}

export type ApplicationStatus = 'requested' | 'approved' | 'denied' | 'blacklisted';

export interface ApplicationAccreditation {
    id: number;
    category: AccreditationReference | null;
    scope: AccreditationScope;
    event: AccreditationEventReference | null;
    team: AccreditationReference | null;
    deadline_end: string | null;
    quota: number;
    available: number;
}

export interface Application {
    id: number;
    accreditation: ApplicationAccreditation | null;
    status: ApplicationStatus;
    priority: boolean;
    reason: string | null;
    created_at: string;
}

export type SubType = 'park' | 'seat';

export type SubApplicationStatus = 'requested' | 'approved' | 'denied';

export interface SubAccreditation {
    id: number;
    accreditation_id: number;
    type: SubType;
    quota: number;
    applications_count: number;
    available: number;
    deadline_start: string | null;
    deadline_end: string | null;
    auto_approve: boolean;
    active: boolean;
}

export interface SubApplicationSubAccreditation {
    id: number;
    type: SubType;
    quota: number;
    deadline_end: string | null;
}

export interface SubApplicationAccreditation {
    id: number;
    category: { id: number; name: string } | null;
    event: { id: number; title: string; date: string | null } | null;
}

export interface SubApplication {
    id: number;
    sub_accreditation: SubApplicationSubAccreditation | null;
    accreditation: SubApplicationAccreditation | null;
    status: SubApplicationStatus;
    priority: boolean;
    reason: string | null;
    created_at: string;
}

export interface AdminUserReference {
    id: number;
    email: string;
    name: string;
}

export interface AdminApplicationAccreditation {
    id: number;
    category: AccreditationReference | null;
    scope: AccreditationScope;
    event: AccreditationEventReference | null;
    team: AccreditationReference | null;
    quota: number;
    available: number;
}

export interface AdminApplication {
    id: number;
    user: AdminUserReference | null;
    accreditation: AdminApplicationAccreditation | null;
    status: ApplicationStatus;
    priority: boolean;
    reason: string | null;
    created_at: string;
    qr_url: string | null;
}

export interface AdminSubApplicationSubAccreditation {
    id: number;
    type: SubType;
    quota: number;
    available: number;
}

export interface AdminSubApplication {
    id: number;
    user: AdminUserReference | null;
    sub_accreditation: AdminSubApplicationSubAccreditation | null;
    accreditation: SubApplicationAccreditation | null;
    status: SubApplicationStatus;
    priority: boolean;
    reason: string | null;
    created_at: string;
}

export interface AdminMedia {
    id: number;
    type: string;
    url: string;
    mime: string;
}

export interface Blacklist {
    id: number;
    email: string | null;
    domain: string | null;
    note: string | null;
    created_at: string;
}

export interface ApplicationAction {
    status?: 'approved' | 'denied';
    reason?: string;
    priority?: boolean;
}

export interface AllocationResult {
    approved: number;
    denied: number;
    skipped_blacklist: number;
}

/**
 * Data fields of a badge layout entry (schema v2 whitelist, features/
 * badge-template-editor.md): the six historical fields plus `team` and
 * `vest_number`. The dedicated `qr`/`image` entries are NOT data fields —
 * they are separate members of `BadgeLayoutEntry`.
 */
export type BadgeFieldKey = 'name' | 'category' | 'event' | 'date' | 'photo' | 'status' | 'team' | 'vest_number';

export type BadgeAlign = 'left' | 'center' | 'right';

/** A positioned data field: coordinates in mm, font size in pt. */
export interface BadgeField {
    field: BadgeFieldKey;
    x: number;
    y: number;
    w: number;
    h: number;
    size: number;
    align: BadgeAlign;
}

/**
 * The verification-QR layout entry (schema v2): positions the QR block on
 * the A6 card; `size`/`align` are allowed by the API but meaningless. At most
 * one per template.
 */
export interface BadgeQrEntry {
    field: 'qr';
    x: number;
    y: number;
    w: number;
    h: number;
}

export type BadgeImageRef = 'logo' | 'header';

export type BadgeImageFit = 'contain' | 'cover';

/**
 * The source of an `image` layout entry — a strict union of enum refs only
 * (never client-controlled paths/URLs): either the mandant's uploaded brand
 * media or an uploaded badge image by id.
 */
export type BadgeImageSource =
    | { kind: 'brand'; ref: BadgeImageRef }
    | { kind: 'upload'; image_id: number };

/** A freely placed picture entry (schema v2, user decision 2026-08-26). */
export interface BadgeImageEntry {
    field: 'image';
    x: number;
    y: number;
    w: number;
    h: number;
    src: BadgeImageSource;
    fit?: BadgeImageFit;
}

export type BadgeLayoutEntry = BadgeField | BadgeQrEntry | BadgeImageEntry;

export interface BadgeTemplate {
    id: number;
    name: string;
    layout: BadgeLayoutEntry[];
    is_default: boolean;
    updated_at: string | null;
}

export interface VerifyResult {
    status: ApplicationStatus;
    name?: string | null;
    category?: string | null;
    event?: string | null;
    date?: string | null;
    photo_url?: string | null;
}

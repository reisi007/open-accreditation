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

export interface Team {
    id: number;
    mandant_id: number;
    slug: string;
    name: string;
    home_venue: string | null;
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
    roles: UserRoleAssignment[];
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
    venue: string | null;
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

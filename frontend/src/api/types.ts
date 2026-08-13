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

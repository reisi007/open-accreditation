import type { PortalMandant } from '../api/types';

/**
 * Static React fallback logo (root asset, served by Vite from `public/`).
 * Caddy can later override it per mandant on a file basis.
 */
export const FALLBACK_LOGO_URL = '/logo.svg';

/**
 * Resolves the homepage logo: the mandant's uploaded logo while it is present
 * and has loaded successfully, otherwise the static React fallback so the
 * homepage always shows a logo.
 */
export function getHomepageLogo(mandant: PortalMandant, logoFailed: boolean): string {
    return mandant.logo_url !== null && !logoFailed ? mandant.logo_url : FALLBACK_LOGO_URL;
}

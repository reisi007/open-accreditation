import { describe, expect, it } from 'vitest';
import type { PortalMandant } from '../api/types';
import { FALLBACK_LOGO_URL, getHomepageLogo } from './homepageLogo';

const mandantBase: PortalMandant = {
    id: 1,
    slug: 'main',
    name: 'Hauptseite',
    logo_url: null,
    header_url: null,
    impressum_text: null,
    privacy_text: null,
    teams_enabled: false,
};

describe('getHomepageLogo', () => {
    it('falls back to the static React logo when the mandant has no uploaded logo', () => {
        expect(getHomepageLogo(mandantBase, false)).toBe(FALLBACK_LOGO_URL);
    });

    it('uses the uploaded logo when present and loaded', () => {
        const mandant = { ...mandantBase, logo_url: '/api/portal/mandant/logo' };
        expect(getHomepageLogo(mandant, false)).toBe('/api/portal/mandant/logo');
    });

    it('falls back to the static React logo when the uploaded logo failed to load', () => {
        const mandant = { ...mandantBase, logo_url: '/api/portal/mandant/logo' };
        expect(getHomepageLogo(mandant, true)).toBe(FALLBACK_LOGO_URL);
    });
});

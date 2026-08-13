import { describe, expect, it } from 'vitest';
import { buildMandantPayload, mandantFormDefaults, type MandantFormValues } from './mandantFormUtils';

const baseValues: MandantFormValues = {
    name: 'Verband',
    slug: 'verband',
    teams_enabled: false,
    is_active: true,
    impressum_text: '',
    privacy_text: '',
    smtp_host: '',
    smtp_port: '',
    smtp_username: '',
    smtp_password: '',
    smtp_encryption: '',
};

function valuesWith(overrides: Partial<MandantFormValues>): MandantFormValues {
    return { ...baseValues, ...overrides };
}

describe('buildMandantPayload', () => {
    it('omits smtp_config entirely when no SMTP field is filled', () => {
        const payload = buildMandantPayload(baseValues);

        expect('smtp_config' in payload).toBe(false);
        expect(payload.name).toBe('Verband');
        expect(payload.slug).toBe('verband');
    });

    it('includes the password when the user typed a new value', () => {
        const payload = buildMandantPayload(
            valuesWith({
                smtp_host: 'mail.example.com',
                smtp_port: '587',
                smtp_username: 'user',
                smtp_password: 'geheim',
            }),
        );

        expect(payload.smtp_config).toEqual({
            host: 'mail.example.com',
            port: 587,
            username: 'user',
            password: 'geheim',
            encryption: null,
        });
    });

    it('omits the password key when left empty (edit mode keeps stored password)', () => {
        const payload = buildMandantPayload(
            valuesWith({
                smtp_host: 'mail.example.com',
                smtp_username: 'user',
            }),
        );

        expect(payload.smtp_config).not.toBeNull();
        expect(payload.smtp_config).not.toHaveProperty('password');
    });

    it('sends smtp_config: null when explicitly clearing', () => {
        const payload = buildMandantPayload(
            valuesWith({
                smtp_host: 'mail.example.com',
                smtp_username: 'user',
                smtp_password: 'geheim',
            }),
            { clearSmtp: true },
        );

        expect(payload.smtp_config).toBeNull();
    });

    it('converts empty port to null and keeps numeric value otherwise', () => {
        const emptyPort = buildMandantPayload(valuesWith({ smtp_host: 'mail.example.com' }));
        expect(emptyPort.smtp_config?.port).toBeNull();

        const numericPort = buildMandantPayload(valuesWith({ smtp_host: 'mail.example.com', smtp_port: '25' }));
        expect(numericPort.smtp_config?.port).toBe(25);
    });
});

describe('mandantFormDefaults', () => {
    it('maps the stored smtp_config onto the flat form fields', () => {
        const defaults = mandantFormDefaults({
            id: 1,
            slug: 'verband',
            name: 'Verband',
            logo_url: null,
            header_url: null,
            impressum_text: null,
            privacy_text: null,
            smtp_config: { host: 'mail.example.com', port: 587, username: 'u', encryption: 'tls' },
            smtp_has_password: true,
            teams_enabled: true,
            is_primary: false,
            is_active: true,
            domains: [],
            teams_count: 0,
        });

        expect(defaults.smtp_host).toBe('mail.example.com');
        expect(defaults.smtp_port).toBe('587');
        expect(defaults.smtp_username).toBe('u');
        expect(defaults.smtp_encryption).toBe('tls');
        expect(defaults.smtp_password).toBe('');
    });
});

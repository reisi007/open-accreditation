import { describe, expect, it } from 'vitest';
import userEvent from '@testing-library/user-event';
import App from './App';
import { renderWithProviders } from './test-setup';

describe('App scaffold', () => {
    it('renders the header with the accreditation title', () => {
        const { getByRole } = renderWithProviders(<App />);

        expect(getByRole('link', { name: 'Akkreditierung' })).toBeInTheDocument();
        expect(getByRole('heading', { level: 1 })).toHaveTextContent('Akkreditierungs-Plattform');
    });

    it('switches the UI language to English', async () => {
        const user = userEvent.setup();
        const { getByRole } = renderWithProviders(<App />);

        await user.selectOptions(getByRole('combobox', { name: 'Sprache' }), 'en');

        expect(getByRole('heading', { level: 1 })).toHaveTextContent('Accreditation platform');
    });
});

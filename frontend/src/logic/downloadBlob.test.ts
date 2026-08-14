import { afterEach, describe, expect, it, vi } from 'vitest';
import { downloadBlob } from './downloadBlob';

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('downloadBlob', () => {
    it('creates an object URL, clicks a temporary download anchor and revokes it', () => {
        vi.useFakeTimers();
        const createObjectURL = vi.fn(() => 'blob:mock-url');
        const revokeObjectURL = vi.fn();
        Object.defineProperty(URL, 'createObjectURL', { value: createObjectURL, writable: true });
        Object.defineProperty(URL, 'revokeObjectURL', { value: revokeObjectURL, writable: true });
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
        const appendChild = vi.spyOn(document.body, 'appendChild');

        downloadBlob(new Blob(['Name;Kategorie'], { type: 'text/csv' }), 'badges.csv');

        const anchor = appendChild.mock.calls[0]?.[0];
        expect(anchor).toBeInstanceOf(HTMLAnchorElement);
        if (!(anchor instanceof HTMLAnchorElement)) return;
        expect(anchor.href).toBe('blob:mock-url');
        expect(anchor.download).toBe('badges.csv');
        expect(click).toHaveBeenCalledTimes(1);

        // The anchor is removed from the DOM immediately, the object URL is
        // revoked asynchronously.
        expect(document.querySelector('a[download="badges.csv"]')).toBeNull();
        expect(revokeObjectURL).not.toHaveBeenCalled();

        vi.runAllTimers();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:mock-url');
    });
});

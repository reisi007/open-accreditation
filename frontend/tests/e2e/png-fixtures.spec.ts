import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import {
    PLAIN_BADGE_IMAGE_PNG,
    PNG_FIXTURES,
    inspectPng,
    pngFixture,
    readPngPixels,
} from '../screenshots/helpers/png-fixtures';

/**
 * Every PNG fixture in the test tree is a real PNG — checked, not assumed.
 *
 * ## The failure this exists to prevent
 *
 * MEASURED on the literal that used to sit in `helpers/admin-data.ts`: its IDAT
 * chunk violates the PNG CRC (stored `0xfb7d5809`, computed `0xfb7d58c9`).
 * `magick identify` still reports `96x120` and exits 0, decoding fails with
 * `IDAT: invalid literal/lengths set`, and **dompdf renders an empty photo box
 * without an error and without a non-zero exit** — so a print check measures a
 * white rectangle and calls it "the renderer forgot the photo". A broken fixture
 * is therefore not a broken test; it is a test that DISAGREES WITH ITSELF, and
 * that is how two real rendering defects survived a review pass.
 *
 * ## Why the whole test tree is scanned
 *
 * A repaired byte is a fix that expires. The scan below makes the class
 * unrepeatable: any base64 literal in `tests/**` that decodes to a PNG must be a
 * REGISTERED fixture, and a registered fixture is CRC-checked at module load and
 * content-checked here. Adding a fourth inline PNG to a spec is therefore a
 * failing test, not another silent fixture.
 *
 * Browser-free, server-free, database-free: it must be cheap enough to run on
 * every commit, because its whole value is running BEFORE the damage does.
 */
test.describe('PNG fixtures are valid', () => {
    test('every registered fixture passes the CRC check of every chunk', () => {
        // The registry is non-empty on purpose: an empty one would make the
        // scan below vacuously true.
        expect(Object.keys(PNG_FIXTURES).length).toBeGreaterThan(0);
        for (const [name, base64] of Object.entries(PNG_FIXTURES)) {
            const info = inspectPng(Buffer.from(base64, 'base64'));
            expect(info.chunks.length, `${name} has chunks to check`).toBeGreaterThanOrEqual(3);
            for (const chunk of info.chunks) {
                expect(
                    chunk.valid,
                    `${name}: ${chunk.type} CRC stored 0x${chunk.storedCrc.toString(16)} vs computed ` +
                        `0x${chunk.computedCrc.toString(16)}`,
                ).toBe(true);
            }
        }
    });

    test('the portrait probe is square, which is what makes a stretch visible', () => {
        const info = inspectPng(pngFixture('portrait-probe'));
        expect(info.width).toBe(info.height);
        // The photo box is 22 × 28 mm (ratio 0.79) and dompdf implements no
        // `object-fit`, so a square image in a portrait box is stretched. A
        // non-square fixture would hide exactly that defect.
        expect(info.width).toBe(100);
    });

    test('the portrait probe really carries asymmetric colour bands', () => {
        // Contents, not just container: a fixture that decodes to grey mush
        // would satisfy every CRC check above and be useless for its one job.
        // The probes are data, not a helper with parameters: this directory is
        // linted with the plain-ES2020 parser, so a parameter annotation would be
        // a parse error and a bare one an implicit `any` under `tsc -b`.
        const image = readPngPixels(pngFixture('portrait-probe'));
        const GREEN = [0, 255, 0];
        const YELLOW = [255, 255, 0];
        const BLUE = [0, 0, 255];
        const RED = [255, 0, 0];
        const probes = [
            { label: 'left strip is green', x: 2, y: 50, rgb: GREEN },
            { label: 'right strip is yellow', x: image.width - 3, y: 50, rgb: YELLOW },
            { label: 'top band is blue', x: 50, y: 2, rgb: BLUE },
            { label: 'bottom band is red', x: 50, y: image.height - 3, rgb: RED },
            // The proportions are the stretch detector: a vertically stretched
            // render moves the 50 % boundary, a transposed one swaps the colours.
            { label: 'above the half-way line is blue', x: 50, y: Math.floor(image.height / 2) - 2, rgb: BLUE },
            { label: 'below the half-way line is red', x: 50, y: Math.floor(image.height / 2) + 2, rgb: RED },
            { label: 'the left strip spans the full height', x: 2, y: 2, rgb: GREEN },
            { label: 'the right strip spans the full height', x: image.width - 3, y: image.height - 3, rgb: YELLOW },
        ];
        for (const probe of probes) {
            expect(image.rgbAt(probe.x, probe.y), probe.label).toEqual(probe.rgb);
        }
    });

    test('the badge editor image is a valid, decodable PNG', () => {
        const image = readPngPixels(Buffer.from(PLAIN_BADGE_IMAGE_PNG, 'base64'));
        expect(image.width).toBe(4);
        expect(image.height).toBe(4);
        // Featureless on purpose: the editor only needs bytes that decode.
        for (let index = 0; index < image.pixels.length; index += 1) {
            expect(image.pixels[index]).toBe(255);
        }
    });

    test('the 1x1 upload fixtures are RGBA, and their alpha is what it measures', () => {
        // They are the two fixtures that go through a canvas (a logo overlay and
        // a badge image), which is why the reader supports colour type 6 next to
        // 2. The alpha is asserted as the MEASURED value (127 — both fixtures are
        // half-transparent, not clear; the older comment in
        // `admin-mandant.spec.ts` called the logo "transparent", which the bytes
        // contradict): a re-encode that silently drops or changes the alpha
        // channel then fails here instead of surfacing as a visual difference
        // nobody can explain.
        for (const name of ['tiny-badge-image', 'tiny-logo-image']) {
            const info = inspectPng(pngFixture(name));
            expect(info.width, name).toBe(1);
            expect(info.height, name).toBe(1);
            const image = readPngPixels(pngFixture(name));
            expect(image.bytesPerPixel, `${name} carries an alpha channel`).toBe(4);
            expect(image.pixels[3], `${name} alpha`).toBe(127);
        }
    });

    test('no unregistered base64 PNG is inlined anywhere under tests/', () => {
        const registered = new Set(Object.values(PNG_FIXTURES));
        // The two files that are allowed to hold PNG literals: the registry
        // itself, and this spec — which keeps the OLD corrupt bytes only to
        // prove the reader rejects them (the non-vacuity test below).
        const exempt = new Set(['png-fixtures.ts', 'png-fixtures.spec.ts']);
        const root = path.resolve(process.cwd(), 'tests');
        const offenders = [];

        // `recursive: true` instead of a recursive helper: this directory is
        // linted with the plain-ES2020 parser, so a parameter annotation would be
        // a parse error and a bare one an implicit `any` under `tsc -b`.
        for (const relative of fs.readdirSync(root, { recursive: true, encoding: 'utf8' })) {
            const name = path.basename(relative);
            if (!relative.endsWith('.ts') || exempt.has(name)) {
                continue;
            }
            const source = fs.readFileSync(path.join(root, relative), 'utf8');
            // Every run of base64-ish characters long enough to be a payload.
            for (const match of source.matchAll(/[A-Za-z0-9+/]{80,}={0,2}/g)) {
                const literal = match[0];
                const decoded = Buffer.from(literal, 'base64');
                const isPng =
                    decoded.length > 8 &&
                    decoded[0] === 0x89 &&
                    decoded[1] === 0x50 &&
                    decoded[2] === 0x4e &&
                    decoded[3] === 0x47;
                if (isPng && !registered.has(literal)) {
                    offenders.push(`${relative} (${literal.slice(0, 24)}…)`);
                }
            }
        }

        expect(
            offenders,
            'these base64 literals decode to a PNG but are not registered in ' +
                'tests/screenshots/helpers/png-fixtures.ts, so nothing checks their CRC: ' +
                `${offenders.join(', ')}. Register the fixture there — a literal in a spec is exactly ` +
                'how the corrupt IDAT survived unnoticed.',
        ).toEqual([]);
    });

    test('the corrupt fixture that started this is really rejected by the reader', () => {
        // Non-vacuity: the same reader that validates the fixtures must REJECT
        // the bytes it replaced. Without this, a reader that returned early or
        // swallowed the mismatch would make every check above meaningless.
        const corrupt =
            'iVBORw0KGgoAAAANSUhEUgAAAGAAAAB4CAIAAACCf2CZAAACjklEQVR42m2cu0oDQRSGZ0fTSFBRH0REsVV8BYNWFmJlq' +
            'RaS2sJCrcTKykrJM0hqMQRfQiy8oCI2YrBYCEuuuztn5xK/v1rIJHPm23/O3MJEj0/PCvWXBgGAAAQgAAEIQAACEAIQgAAEIAAB' +
            'aLQ0bvj9ytqy/42s3d7hILoYgAAEIAABCAEIQAACEIAABCAAIQABCEAAAhCAAAQgACEAuQNkcmipvD9WxUFWAPlsIvPY' +
            'cJAq9t8dsS6PD+KHncMTH1rVjseLLvZxXysiMhE6ydhcOiirPvVE/DDZ+i6ivEdJuvsVDTVRu7UdzyLlu2s3NJF25Z2Ubc5a' +
            'npn0SCw1rk/3cn/3vLp9Xt22X69tB/WLtTvLDs676csXQUdgFJtaqnRkwXKplYx4c/+su4WZRqWh5ZNoyqXW14/uiNDrYb4' +
            'npqyjtWXXCANKmqhtnzSYCkpzSRMZ2kfMQVNLld+Hm3xuKiITx+9pbH7Dl7WYSQuH8rLQjwYokrqaIo2D0ujl9V0pNTc7b' +
            'f5TIg4SG+ZFohGUVDzMpAEU4nbHAIlkH08d5E8aEoyELmYXkA8mko3BpYOa9Uaz3pAq5vtEMd+ksd3yhdXFrJ/asbDjUWxhd' +
            'TGmMMAj6ekE46AcK4+egLKiKSIDRsVdEyi1OnM7PujgIrZclw40bmu16KCjt/D7Oug3bMGhOtwcYaf/6kDzqLURILJ/G7Dh' +
            '8G95uRe5ui45ByYnK+HI7X3S8YFav9M0pVR8wmV+vBX2jmLHYbFiT1qxaQ8gACEAAUhiHrSyfuSw+outmTTFdq/ecBBdDEA' +
            'AAhACEIAApP7TfpDDGSAOAhCAAAQgAAEIQAhAAAIQgAAEIAABCAEIQAACEIAANFL6A/UV1Rn7fVgJAAAAAElFTkSuQmCC';
        expect(() => inspectPng(Buffer.from(corrupt, 'base64'))).toThrow(/CRC mismatch/);
    });
});

import zlib from 'node:zlib';

/**
 * The PNG fixtures of the test tree — generated, CRC-verified, and self-checking.
 *
 * ## Why this module exists: a corrupt fixture that fails SILENTLY
 *
 * `tests/e2e/helpers/admin-data.ts` used to inline a base64 portrait whose IDAT
 * chunk violates the PNG CRC (MEASURED: stored `0xfb7d5809`, computed
 * `0xfb7d58c9`). The failure mode is the dangerous kind:
 *
 * - `magick identify` reads the header and reports `96x120`, exit 0 — so a
 *   "does the fixture look like a PNG?" check passes;
 * - decoding it fails (`magick: IDAT: invalid literal/lengths set`, exit 1);
 * - **dompdf renders an EMPTY photo box, with no error and exit 0** — and
 *   "the renderer forgot the photo" is indistinguishable from that.
 *
 * A silent fixture failure is worth more than the fixture: it makes a real
 * rendering defect unprovable. So the bytes are no longer a literal somebody
 * pasted. They are generated, and this module refuses to hand out a fixture
 * whose chunks do not check out.
 *
 * ## What is guaranteed, and how
 *
 * - **Module load validates every registered fixture** (signature, per-chunk
 *   CRC32, IHDR, decodable IDAT). A corrupt fixture therefore breaks EVERY
 *   consumer loudly, not just the test that happens to look at it.
 * - **`readPngPixels` really decodes** (inflate + all five scanline filters), so
 *   the specs can assert the *contents*, not merely the container: the probe's
 *   colour bands are its whole purpose, and a fixture that decoded to grey mush
 *   would pass a CRC check and fail its job.
 * - **The geometry is asymmetric on purpose** — see `PORTRAIT_PROBE_PNG`'s
 *   docblock. A portrait box is 22 × 28 mm and dompdf implements no
 *   `object-fit`, so `width:100%;height:100%` means STRETCH. A square probe with
 *   horizontal bands (top/bottom) and vertical strips (left/right) makes a
 *   stretched AND a transposed fit visible; a symmetric image would not.
 */

/** The 8-byte PNG signature every fixture must start with. */
const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

const CRC_TABLE = (() => {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n += 1) {
        let c = n;
        for (let k = 0; k < 8; k += 1) {
            c = (c & 1) !== 0 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        table[n] = c;
    }
    return table;
})();

/** CRC-32 as PNG defines it (IEEE 802.3 polynomial, reflected, init/final inverted). */
export function pngCrc32(bytes: Buffer): number {
    let crc = 0xffffffff;
    for (const byte of bytes) {
        crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ 0xffffffff) >>> 0;
}

export interface PngChunkReport {
    type: string;
    length: number;
    storedCrc: number;
    computedCrc: number;
    valid: boolean;
}

export interface PngInfo {
    width: number;
    height: number;
    bitDepth: number;
    colourType: number;
    bytes: number;
    chunks: PngChunkReport[];
}

export interface PngImage extends PngInfo {
    /** Pixel bytes in the file's own layout: 3 per pixel for RGB, 4 for RGBA. */
    pixels: Buffer;
    bytesPerPixel: number;
    /**
     * The RGB triple at (x, y), alpha discarded. Exists so a caller can assert
     * colours without knowing which of the two supported colour types a fixture
     * uses — the 1x1 fixtures are RGBA (they need transparency), the probe is RGB.
     */
    rgbAt(x: number, y: number): [number, number, number];
}

/**
 * Parses the container and verifies every chunk CRC. Throws with the offending
 * chunk named — the whole point is that a corrupt fixture says WHICH BYTE.
 */
export function inspectPng(bytes: Buffer): PngInfo {
    if (!bytes.subarray(0, 8).equals(PNG_SIGNATURE)) {
        throw new Error('Not a PNG: the 8-byte signature is missing');
    }
    const chunks: PngChunkReport[] = [];
    let offset = 8;
    let header: { width: number; height: number; bitDepth: number; colourType: number } | null = null;
    while (offset + 8 <= bytes.length) {
        const length = bytes.readUInt32BE(offset);
        const type = bytes.toString('latin1', offset + 4, offset + 8);
        const body = bytes.subarray(offset + 4, offset + 8 + length);
        const storedCrc = bytes.readUInt32BE(offset + 8 + length);
        const computedCrc = pngCrc32(body);
        chunks.push({ type, length, storedCrc, computedCrc, valid: storedCrc === computedCrc });
        if (type === 'IHDR') {
            // `body` is chunk-TYPE + chunk-DATA (the CRC covers both, so the CRC
            // is computed over `body`); the header FIELDS start after the 4 type
            // bytes. Reading them at offset 0 yields the ASCII of "IHDR" — which
            // is a plausible-looking 0, not an error.
            const fields = body.subarray(4);
            header = {
                width: fields.readUInt32BE(0),
                height: fields.readUInt32BE(4),
                bitDepth: fields[8],
                colourType: fields[9],
            };
        }
        offset += 12 + length;
    }
    if (header === null) {
        throw new Error('Not a PNG: no IHDR chunk');
    }
    if (chunks.some((chunk) => !chunk.valid)) {
        const broken = chunks.filter((chunk) => !chunk.valid);
        throw new Error(
            'PNG chunk CRC mismatch: ' +
                broken
                    .map(
                        (chunk) =>
                            `${chunk.type} (stored 0x${chunk.storedCrc.toString(16)}, computed 0x${chunk.computedCrc.toString(16)})`,
                    )
                    .join(', '),
        );
    }
    return { ...header, bytes: bytes.length, chunks };
}

/** Bytes per pixel of the supported colour types: 2 = RGB, 6 = RGBA. */
const BYTES_PER_PIXEL: Readonly<Record<number, number>> = { 2: 3, 6: 4 };

/**
 * Full decode: inflate the IDAT stream and undo the per-scanline filters. Throws
 * on anything it does not understand rather than returning wrong pixels — a
 * lenient decoder is how a broken fixture becomes a plausible screenshot.
 */
export function readPngPixels(bytes: Buffer): PngImage {
    const info = inspectPng(bytes);
    const bytesPerPixel = BYTES_PER_PIXEL[info.colourType];
    if (info.bitDepth !== 8 || bytesPerPixel === undefined) {
        throw new Error(
            `Unsupported PNG variant: bitDepth ${info.bitDepth}, colourType ${info.colourType}. ` +
                'The reader handles 8-bit truecolour (2) and truecolour+alpha (6) and refuses to guess ' +
                'anything else.',
        );
    }

    const parts: Buffer[] = [];
    let offset = 8;
    while (offset + 8 <= bytes.length) {
        const length = bytes.readUInt32BE(offset);
        const type = bytes.toString('latin1', offset + 4, offset + 8);
        if (type === 'IDAT') {
            parts.push(bytes.subarray(offset + 8, offset + 8 + length));
        }
        offset += 12 + length;
    }
    const raw = zlib.inflateSync(Buffer.concat(parts));

    const stride = info.width * bytesPerPixel;
    const expected = info.height * (1 + stride);
    if (raw.length !== expected) {
        throw new Error(
            `PNG pixel data is ${raw.length} bytes, expected ${expected} for ${info.width}x${info.height}`,
        );
    }

    const pixels = Buffer.alloc(stride * info.height);
    for (let y = 0; y < info.height; y += 1) {
        const filter = raw[y * (1 + stride)];
        const line = raw.subarray(y * (1 + stride) + 1, (y + 1) * (1 + stride));
        const out = pixels.subarray(y * stride, (y + 1) * stride);
        const prior = y > 0 ? pixels.subarray((y - 1) * stride, y * stride) : null;
        for (let x = 0; x < stride; x += 1) {
            const left = x >= bytesPerPixel ? out[x - bytesPerPixel] : 0;
            const up = prior === null ? 0 : prior[x];
            const upLeft = prior === null || x < bytesPerPixel ? 0 : prior[x - bytesPerPixel];
            let value: number;
            switch (filter) {
                case 0:
                    value = line[x];
                    break;
                case 1:
                    value = line[x] + left;
                    break;
                case 2:
                    value = line[x] + up;
                    break;
                case 3:
                    value = line[x] + Math.floor((left + up) / 2);
                    break;
                case 4: {
                    const p = left + up - upLeft;
                    const pa = Math.abs(p - left);
                    const pb = Math.abs(p - up);
                    const pc = Math.abs(p - upLeft);
                    const predictor = pa <= pb && pa <= pc ? left : pb <= pc ? up : upLeft;
                    value = line[x] + predictor;
                    break;
                }
                default:
                    throw new Error(`Unknown PNG scanline filter ${filter} on row ${y}`);
            }
            out[x] = value & 0xff;
        }
    }
    return {
        ...info,
        pixels,
        bytesPerPixel,
        rgbAt: (x: number, y: number): [number, number, number] => {
            const at = (y * info.width + x) * bytesPerPixel;
            return [pixels[at], pixels[at + 1], pixels[at + 2]];
        },
    };
}

function fixture(base64: string): Buffer {
    return Buffer.from(base64, 'base64');
}

/**
 * A 100 × 100 px PROBE rather than a decorative portrait, and deliberately so:
 *
 * - **Square into a portrait box.** The photo box is 22 × 28 mm (ratio 0.79),
 *   the image is 1.0. A renderer that stretches (`width:100%;height:100%` with no
 *   `object-fit`) — which is what dompdf does, it implements no `object-fit` —
 *   turns the colour bands into visibly wrong proportions. A correctly fitted
 *   image crops; a stretched one distorts. The bands are the assertion.
 * - **Asymmetric, high-contrast edges.** Green left strip / yellow right strip,
 *   blue top half / red bottom half. A flipped or transposed fit is as visible
 *   as a stretched one, and the 50/25/25 proportions are what a stretch
 *   distorts.
 * - **Low resolution on purpose.** 100 px across 22 mm is ~115 dpi: enough to
 *   recognise the bands, small enough to stay a ~230 byte fixture.
 *
 * Generated by the recipe in this file's history; every chunk CRC-verified at
 * module load (see the module docblock) and asserted by
 * `tests/e2e/png-fixtures.spec.ts`.
 */
export const PORTRAIT_PROBE_PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAIAAAD/gAIDAAAAqklEQVR42u3QsQkAMBADMe+/tLOE4VMIrr5CSTNr+ZrVZlVgwYIFCxYsWLBgwYIFCxYsWLBgwYIFCxYsWLBgwYIFCxYsWLBgwYIFCxYsWLBgwYIFCxYsWLBgwYIFCxYsWLBg3WD1Uy1YsGDBggULFixYsGDBggULFixYsGDBggULFixYsGDBggULFixYsGDBggULFixYsGDBggULFixYsGDBggULFixYF1gP5Zel/fwuJpUAAAAASUVORK5CYII=';

/** A 4 × 4 pure-white badge image: valid, tiny, featureless on purpose. */
export const PLAIN_BADGE_IMAGE_PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAD0lEQVR42mP4jwQYiOMAANuQL9HXPgz1AAAAAElFTkSuQmCC';

/**
 * The badge-editor spec's 1 × 1 upload fixture, kept as it is (the test is about
 * a minimal image round-tripping) and REGISTERED rather than inlined: it is a PNG
 * literal in a spec, which is exactly the shape the tree scan below refuses.
 * CRC-verified like every other entry.
 */
export const TINY_BADGE_IMAGE_PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/**
 * The mandant-logo upload fixture: 1 × 1, fully transparent. Also formerly an
 * inline literal in `tests/e2e/admin-mandant.spec.ts`; registered for the same
 * reason as the one above.
 */
export const TINY_LOGO_IMAGE_PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * Every base64 PNG literal the test tree is allowed to contain. A fixture that
 * is not registered here has no CRC check, and the spec that scans the tree
 * fails on it — which is the whole reason the old inline literal could sit
 * broken for so long.
 */
export const PNG_FIXTURES: Readonly<Record<string, string>> = {
    'portrait-probe': PORTRAIT_PROBE_PNG,
    'plain-badge-image': PLAIN_BADGE_IMAGE_PNG,
    'tiny-badge-image': TINY_BADGE_IMAGE_PNG,
    'tiny-logo-image': TINY_LOGO_IMAGE_PNG,
};

/** Decoded bytes of a registered fixture, validated. */
export function pngFixture(name: keyof typeof PNG_FIXTURES): Buffer {
    const bytes = fixture(PNG_FIXTURES[name]);
    inspectPng(bytes);
    return bytes;
}

/**
 * Load-time gate. Every consumer of a fixture imports this module first, so a
 * corrupt byte stops the run here — with the chunk and both CRCs named — instead
 * of surfacing as an empty photo box three layers down.
 */
for (const [name, base64] of Object.entries(PNG_FIXTURES)) {
    try {
        readPngPixels(fixture(base64));
    } catch (error) {
        throw new Error(
            `PNG fixture "${name}" is unusable and the harness refuses to hand it out: ` +
                `${error instanceof Error ? error.message : String(error)}`,
        );
    }
}

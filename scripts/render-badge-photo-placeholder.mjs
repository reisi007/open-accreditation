#!/usr/bin/env node
//
// Render the badge photo placeholder (the neutral person silhouette that stands
// in for a missing portrait) from the icon the editor already uses.
//
// WHY THIS SCRIPT EXISTS — the icon must exist in the repo exactly ONCE. The
// PDF renderer (dompdf) and the badge-template editor both show it; a second
// copy would drift. So there is one committed artifact
// (`backend/resources/img/badge/photo-placeholder.png`) and this script is its
// PROVENANCE: the PNG is derived from `mdi account`, the very glyph the editor
// used before the fallback was wired up.
//
// The format is not a matter of taste — it was measured (see
// features/badge-template-editor.md, "Platzhalter für ein fehlendes Porträt"):
//
//   inline <svg> in the HTML        → dompdf drops it completely (0 ink pixels)
//   <img src="data:image/svg+xml">  → dompdf renders it, but the geometry is
//                                     mangled (the head becomes a sheared blob)
//   <img src="data:image/png">      → the silhouette, correct, 0.315 ink vs.
//                                     0.311 for the source PNG
//
// WHY PNG AND WHY *WITH* AN ALPHA CHANNEL: a PNG flattened onto white paints a
// white square over a non-white badge background (measured: the corners of the
// box go #FFFFFF where the background was #000000). The alpha version
// composites (measured: the corners stay #000000). 512 px is the smallest of
// the probed sizes that stays clean when scaled to the maximum photo box; 24 px
// renders blocky.
//
// NO SVGO, NO SVG OPTIMIZER: the icon body is a single `<path>`, there is
// nothing to optimize, and the repo's accepted risk A4 (AGENTS.md §10) is about
// svgo's `removeScripts`/sanitize advisories. This script therefore never
// touches svgo — neither at build time nor at runtime. Nothing here runs inside
// the app, the Vite build or a request; it is an authoring tool, run by hand.
//
// Usage:
//   node scripts/render-badge-photo-placeholder.mjs
//
// Requires: node (the repo's), ImageMagick `magick`, and the frontend's
// `@iconify-json/mdi` devDependency (it is where `mdi account` lives).
// It exits non-zero — loudly — when a tool is missing or the output is empty;
// a script that writes a 0-byte PNG and reports success is worse than none.

import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(new URL('..', import.meta.url)));
const ICON_JSON = join(ROOT, 'frontend/node_modules/@iconify-json/mdi/icons.json');
const TARGET = join(ROOT, 'backend/resources/img/badge/photo-placeholder.png');

/** The glyph: `mdi account` — a head over shoulders, no circle, no frame. */
const ICON = 'account';
/** Neutral placeholder grey. Measured 3.36:1 against white — visible in a
 *  grayscale print, clearly lighter than any printed content. */
const FILL = '#8C8C8C';
/** 512 px: the largest box a `photo` entry may occupy is the whole A6 card;
 *  512 px keeps the silhouette clean when scaled up (24 px was visibly blocky). */
const SIZE = 512;

function fail(message) {
    process.stderr.write(`FEHLER: ${message}\n`);
    process.exit(1);
}

// --- tool checks, by name -------------------------------------------------
try {
    execFileSync('magick', ['-version'], { stdio: 'ignore' });
} catch {
    fail('ImageMagick (`magick`) fehlt im PATH — Install: brew install imagemagick');
}

// --- 1. the icon body from the very icon set the frontend pins -----------
let body;
try {
    const icons = JSON.parse(readFileSync(ICON_JSON, 'utf8')).icons ?? {};
    body = icons[ICON]?.body;
} catch {
    fail(`Icon-Set nicht lesbar: ${ICON_JSON} — die frontend-Dependencies fehlen (pnpm install)`);
}

if (typeof body !== 'string' || body.trim() === '') {
    fail(`mdi kennt das Icon "${ICON}" nicht (icons.json geändert?)`);
}

// `currentColor` is a CSS-context colour; the PNG has to carry a real one.
const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">${body.replace(
    /currentColor/g,
    FILL,
)}</svg>`;

const work = mkdtempSync(join(tmpdir(), 'badge-photo-placeholder-'));
try {
    const svgPath = join(work, 'icon.svg');
    const pngPath = join(work, 'icon.png');
    writeFileSync(svgPath, svg);

    execFileSync('magick', ['-background', 'none', '-density', '384', svgPath, '-resize', `${SIZE}x${SIZE}`, pngPath], {
        stdio: 'inherit',
    });

    if (!statSync(pngPath).size > 0) {
        fail('magick hat keine PNG geschrieben');
    }

    // The alpha channel is load-bearing (compositing over a non-white badge
    // background), so verify it before the bytes are copied into the repo.
    const identify = execFileSync('magick', ['identify', '-format', '%wx%h %[channels]', pngPath], {
        encoding: 'utf8',
    }).trim();
    const [, channels] = identify.split(' ');
    if (channels === undefined || !channels.endsWith('a')) {
        fail(`die PNG hat keinen Alpha-Kanal (${identify}) — sie würde über farbigem Hintergrund einen weissen Kasten malen`);
    }

    mkdirSync(dirname(TARGET), { recursive: true });
    writeFileSync(TARGET, readFileSync(pngPath));
    process.stdout.write(
        `${TARGET}\n  aus mdi "${ICON}", ${SIZE}×${SIZE}, ${identify}, ${statSync(TARGET).size} bytes\n`,
    );
} finally {
    rmSync(work, { recursive: true, force: true });
}

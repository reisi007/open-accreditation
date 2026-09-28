import fs from 'node:fs';
import path from 'node:path';

/**
 * The hand-off point between the deliberately-failing probe and its driver.
 *
 * The probe (`ownership-probe/half-failure.fixture.ts`) creates a row, records
 * the id it actually got back, and then dies on purpose. The driver
 * (`ownership.spec.ts`) reads that id back and asks the database about THAT row.
 *
 * ## Why the id travels through a file at all
 *
 * Because the driver has to prove two separate things, and only the second is
 * interesting:
 *
 * 1. the probe really created a row (`id > 0`), and
 * 2. that row is gone afterwards.
 *
 * A driver that only asked "are there any rows left matching the probe's name?"
 * would satisfy (2) just as well when the create had never happened — and then
 * it would be a test that cannot fail, which is the failure mode this whole
 * exercise exists to avoid. Pinning the assertion to a concrete id removes the
 * ambiguity: no id on disk means the probe never ran, and the driver says so
 * instead of passing.
 *
 * ## Why not in `test-results/`
 *
 * It IS under `test-results/`, deliberately, and that is safe for one measured
 * reason: the probe's config sets its OWN `outputDir`
 * (`test-results/ownership-probe`), and Playwright empties `outputDir` — not its
 * parent — before a run. The record sits in the parent directory, so the probe's
 * own pre-run cleanup cannot delete the file the driver is about to read. (This
 * is the same trap `playwright.config.ts` documents for the screenshot PNGs,
 * where a shared `outputDir` did delete 60 captures.)
 *
 * Each file is removed by the driver when it has read it, and a stale one from
 * an interrupted run is harmless: the probe overwrites it before it can be
 * believed, and the driver refuses to run without a fresh, matching pid.
 */
export const PROBE_RECORD_PATH = path.resolve(process.cwd(), 'test-results/ownership-probe-record.json');

/** The negative control's record: the row the probe deliberately forgets. */
export const PROBE_CONTROL_RECORD_PATH = `${PROBE_RECORD_PATH}.control`;

/** Read one record, or `null` when the probe never wrote it. */
export function readProbeRecord(filePath = '') {
    try {
        return JSON.parse(fs.readFileSync(filePath, 'utf8'));
    } catch (error) {
        if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
            return null;
        }
        throw error;
    }
}

/** Write one record. Called by the probe from inside the test body. */
export function writeProbeRecord(filePath = '', record = { id: 0, pid: 0 }) {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
    fs.writeFileSync(filePath, JSON.stringify(record));
}

/** Remove a record. Idempotent, and never throws for a missing file. */
export function clearProbeRecord(filePath = '') {
    try {
        fs.unlinkSync(filePath);
    } catch (error) {
        if (!(error instanceof Error && 'code' in error && error.code === 'ENOENT')) {
            throw error;
        }
    }
}

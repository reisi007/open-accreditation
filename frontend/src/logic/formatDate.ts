/**
 * Date and time formatting for the UI.
 *
 * Both helpers RETURN their input unchanged when it cannot be parsed. A dead
 * letter's `failed_at` comes from the API and a `null` there is rendered as an
 * em dash by the caller, so a formatter that rendered `Invalid Date` would turn a
 * missing value into a lie about the data.
 */

/** Calendar date, day/month order decided by the locale. Input is a plain `Y-m-d`. */
export function formatDate(value: string, locale: string): string {
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
}

/** Full timestamp, ISO 8601 in. Used for "failed at", "created at", every admin list. */
export function formatDateTime(value: string, locale: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString(locale);
}

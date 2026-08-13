/**
 * Thin client for the Mailpit REST API (https://mailpit.axllent.org/docs/api-v1/).
 * Used by E2E tests to assert real mail delivery (e.g. the P1b activation mail)
 * and to extract the activation link from the delivered HTML.
 *
 * Note: files under `tests/e2e` are linted with the espree parser (ES2020, no
 * TS syntax) but type-checked strictly by `tsc` — so parameter types come from
 * default values instead of annotations, and no class fields are used.
 */

function mailpitBaseUrl() {
    return (process.env.MAILPIT_API_URL || 'http://localhost:8025/api/v1').replace(/\/+$/, '');
}

export class MailpitHelper {
    /**
     * Poll the Mailpit API until a message for the given recipient arrives.
     * The returned message is the full detail record (including `HTML`).
     */
    async waitForMail(to = '', timeoutMs = 15000) {
        const baseUrl = mailpitBaseUrl();
        const deadline = Date.now() + timeoutMs;
        let lastDetail = 'no message';

        while (Date.now() < deadline) {
            const response = await fetch(
                `${baseUrl}/messages?query=${encodeURIComponent(`to:"${to}"`)}&limit=100`,
            );

            if (!response.ok) {
                lastDetail = `Mailpit API returned ${response.status}`;
            } else {
                const data = await response.json();
                let found = null;
                for (const message of data.messages || []) {
                    for (const recipient of message.To || []) {
                        if (recipient.Address === to) {
                            found = message;
                            break;
                        }
                    }
                    if (found) break;
                }

                if (found) {
                    const detailResponse = await fetch(`${baseUrl}/message/${found.ID}`);
                    if (detailResponse.ok) {
                        return await detailResponse.json();
                    }
                    lastDetail = `detail lookup failed (${detailResponse.status})`;
                } else {
                    lastDetail = `no message for ${to}`;
                }
            }

            await new Promise((resolve) => setTimeout(resolve, 500));
        }

        throw new Error(`Mailpit: ${lastDetail} within ${timeoutMs}ms`);
    }

    /**
     * Wait for the mail and extract the activation path (e.g.
     * `/api/auth/activate/<token>`) from the delivered HTML body.
     */
    async extractActivationPath(to = '', timeoutMs = 15000) {
        const message = await this.waitForMail(to, timeoutMs);
        const match = message.HTML?.match(/\/api\/auth\/activate\/[A-Za-z0-9]+/);
        if (!match?.[0]) {
            throw new Error(`Mailpit: activation link not found in message for ${to}`);
        }
        return match[0];
    }
}

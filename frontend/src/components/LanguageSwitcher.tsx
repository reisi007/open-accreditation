import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';

export function LanguageSwitcher() {
    const { i18n } = useLingui();

    return (
        <select
            className="select select-sm select-ghost"
            aria-label={i18n._(t`Sprache`)}
            value={i18n.locale}
            onChange={(event) => {
                i18n.activate(event.target.value);
            }}
        >
            <option value="de">Deutsch</option>
            <option value="en">English</option>
        </select>
    );
}

import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';

export function LanguageSwitcher() {
    const { i18n } = useLingui();

    return (
        <select
            // `shrink-0` is load-bearing, not decoration. A `<select>` has no
            // truncation affordance — it cannot ellipsize its own value — so
            // under flex pressure it is the one control that produces a
            // half-word ("Deut…"). Measured in the admin header before this
            // class existed: natural 88.9 px, rendered 42 px, clipped by 47 px,
            // because the `<select>` was the only `navbar-end` child left with
            // `flex-shrink`. Its neighbours all have a graceful fallback (the
            // switcher truncates its name, the buttons are squares), so the
            // element that cannot degrade must be told not to.
            className="select select-sm select-ghost w-auto shrink-0"
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

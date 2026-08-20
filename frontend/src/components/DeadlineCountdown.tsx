import { msg, t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect, useState } from 'react';

interface DeadlineCountdownProps {
    deadline: string | null;
}

interface CountdownState {
    expired: boolean;
    days: number | null;
    hours: number | null;
}

function parseDateValue(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
}

function computeCountdown(deadline: string | null, now: Date): CountdownState {
    if (!deadline) {
        return { expired: false, days: null, hours: null };
    }

    const endOfDay = parseDateValue(deadline);
    endOfDay.setHours(23, 59, 59, 999);

    if (now.getTime() >= endOfDay.getTime()) {
        return { expired: true, days: null, hours: null };
    }

    const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const deadlineStart = new Date(endOfDay.getFullYear(), endOfDay.getMonth(), endOfDay.getDate());
    const days = Math.floor((deadlineStart.getTime() - todayStart.getTime()) / 86400000);

    if (days > 0) {
        return { expired: false, days, hours: null };
    }

    const hours = Math.max(1, Math.ceil((endOfDay.getTime() - now.getTime()) / 3600000));
    return { expired: false, days: null, hours };
}

export function DeadlineCountdown({ deadline }: DeadlineCountdownProps) {
    const { i18n } = useLingui();
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60000);
        return () => window.clearInterval(timer);
    }, []);

    const state = computeCountdown(deadline, now);

    if (state.expired) {
        return <span className="badge badge-sm badge-error">{i18n._(t`Frist abgelaufen`)}</span>;
    }

    if (state.days !== null) {
        const count = state.days;
        const badgeClass = count <= 7 ? 'badge-warning' : 'badge-info';
        return (
            <span className={`badge badge-sm ${badgeClass}`}>
                {i18n._({ ...msg`Noch {count, plural, one {Tag} other {Tage}}`, values: { count } })}
            </span>
        );
    }

    const count = state.hours ?? 0;
    return (
        <span className="badge badge-sm badge-warning">
            {i18n._({ ...msg`Noch {count, plural, one {Stunde} other {Stunden}}`, values: { count } })}
        </span>
    );
}

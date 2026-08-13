import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Link } from 'react-router-dom';
import { useAuth } from '../logic/useAuth';

interface ApplyButtonProps {
    accreditationId: number;
}

export function ApplyButton({ accreditationId }: ApplyButtonProps) {
    const { i18n } = useLingui();
    const { isAuthenticated } = useAuth();
    const target = `/apply/${accreditationId}`;

    if (isAuthenticated) {
        return (
            <Link to={target} className="btn btn-primary btn-sm">
                {i18n._(t`Beantragen`)}
            </Link>
        );
    }

    return (
        <Link to="/login" state={{ from: target }} className="btn btn-primary btn-sm">
            {i18n._(t`Beantragen`)}
        </Link>
    );
}

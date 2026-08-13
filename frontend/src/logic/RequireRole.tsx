import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './useAuth';

interface RequireRoleProps {
    role: string;
    children: ReactNode;
}

export function RequireRole({ role, children }: RequireRoleProps) {
    const { user, isLoading, isAuthenticated } = useAuth();
    const location = useLocation();

    if (isLoading) {
        return (
            <div className="flex min-h-dvh items-center justify-center">
                <span className="loading loading-spinner loading-lg"></span>
            </div>
        );
    }

    if (!isAuthenticated || !user) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    if (!user.roles.some((userRole) => userRole.slug === role)) {
        return <Navigate to="/" replace />;
    }

    return children;
}

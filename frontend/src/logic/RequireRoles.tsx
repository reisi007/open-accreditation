import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './useAuth';

interface RequireRolesProps {
    roles: string[];
    children: ReactNode;
}

export function RequireRoles({ roles, children }: RequireRolesProps) {
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

    if (!user.roles.some((userRole) => roles.includes(userRole.slug))) {
        return <Navigate to="/" replace />;
    }

    return children;
}

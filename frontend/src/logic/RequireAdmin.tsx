import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { isAdminUser } from './adminRoles';
import { useAuth } from './useAuth';

interface RequireAdminProps {
    children: ReactNode;
}

export function RequireAdmin({ children }: RequireAdminProps) {
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

    if (!isAdminUser(user)) {
        return <Navigate to="/" replace />;
    }

    return children;
}

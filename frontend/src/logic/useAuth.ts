import useSWR, { useSWRConfig } from 'swr';
import { getMe, login as apiLogin, logout as apiLogout } from '../api/client';
import type { User } from '../api/types';

export function useAuth() {
    const { mutate: configMutate } = useSWRConfig();
    const { data: user, error, isLoading, mutate } = useSWR<User>('/api/auth/me', () => getMe(), {
        shouldRetryOnError: false,
    });

    const login = async (email: string, password: string): Promise<void> => {
        await apiLogin(email, password);
        await configMutate('/api/auth/me');
    };

    const logout = async (): Promise<void> => {
        await apiLogout();
        await configMutate(() => true, undefined, { revalidate: false });
    };

    return {
        user,
        isLoading: isLoading || (!user && !error),
        isAuthenticated: Boolean(user),
        login,
        logout,
        mutate,
    };
}

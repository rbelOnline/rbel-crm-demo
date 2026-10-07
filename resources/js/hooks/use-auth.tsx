import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, csrf } from '@/lib/api';
import type { User } from '@/lib/types';

interface AuthState {
    user: User | null;
    loading: boolean;
    login: (email: string, password: string, remember: boolean) => Promise<void>;
    logout: () => Promise<void>;
    setUser: (u: User) => void;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);
    const queryClient = useQueryClient();

    // Restore an existing (or "remember me") session on load.
    useEffect(() => {
        api.get<{ data: User }>('/user')
            .then((r) => setUser(r.data.data))
            .catch(() => setUser(null))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        const onUnauthorized = () => {
            setUser(null);
            queryClient.clear();
        };
        window.addEventListener('auth:unauthorized', onUnauthorized);
        return () => window.removeEventListener('auth:unauthorized', onUnauthorized);
    }, [queryClient]);

    const login = useCallback(async (email: string, password: string, remember: boolean) => {
        await csrf();
        const r = await api.post<{ data: User }>('/login', { email, password, remember });
        setUser(r.data.data);
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post('/logout');
        } finally {
            setUser(null);
            queryClient.clear();
        }
    }, [queryClient]);

    return <AuthContext.Provider value={{ user, loading, login, logout, setUser }}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
    return ctx;
}

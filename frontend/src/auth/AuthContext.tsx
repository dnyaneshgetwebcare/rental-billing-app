import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, refreshToken, session } from '../api/client';

export type Role = 'admin' | 'accountant' | 'staff';

export interface CompanyRef {
  id: number;
  name: string;
  gstin: string | null;
  state_code: string;
  invoice_prefix: string;
  role: Role;
}

export interface User {
  id: number;
  name: string;
  email: string;
}

interface AuthState {
  ready: boolean;
  user: User | null;
  companies: CompanyRef[];
  company: CompanyRef | null;
  login(email: string, password: string): Promise<void>;
  loginWithGoogle(idToken: string): Promise<{ companies: CompanyRef[] }>;
  logout(): Promise<void>;
  selectCompany(id: number): void;
  reload(): Promise<void>;
  can(...roles: Role[]): boolean;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const [ready, setReady] = useState(false);
  const [user, setUser] = useState<User | null>(null);
  const [companies, setCompanies] = useState<CompanyRef[]>([]);
  const [companyId, setCompanyId] = useState<number | null>(session.companyId());

  const applyMe = useCallback((me: { user: User; companies: CompanyRef[] }) => {
    setUser(me.user);
    setCompanies(me.companies);
    const stored = session.companyId();
    const valid = me.companies.find((c) => c.id === stored);
    const next = valid ? valid.id : me.companies.length === 1 ? me.companies[0].id : null;
    session.setCompanyId(next);
    setCompanyId(next);
  }, []);

  const reload = useCallback(async () => {
    applyMe(await api.get('/me'));
  }, [applyMe]);

  const clear = useCallback(() => {
    session.setToken(null);
    setUser(null);
    setCompanies([]);
    qc.clear();
  }, [qc]);

  useEffect(() => {
    session.onLoggedOut(clear);
    (async () => {
      try {
        if (await refreshToken()) await reload();
      } catch {
        clear();
      } finally {
        setReady(true);
      }
    })();
  }, [clear, reload]);

  const value = useMemo<AuthState>(() => {
    const company = companies.find((c) => c.id === companyId) ?? null;
    return {
      ready,
      user,
      companies,
      company,
      async login(email, password) {
        const res = await api.post<{ access_token: string; user: User; companies: CompanyRef[] }>('/auth/login', { email, password });
        session.setToken(res.access_token);
        qc.clear();
        applyMe(res);
      },
      async loginWithGoogle(idToken) {
        const res = await api.post<{ access_token: string; user: User; companies: CompanyRef[] }>('/auth/google', { id_token: idToken });
        session.setToken(res.access_token);
        qc.clear();
        applyMe(res);
        return res;
      },
      async logout() {
        try {
          await api.post('/auth/logout');
        } finally {
          clear();
        }
      },
      selectCompany(id) {
        session.setCompanyId(id);
        setCompanyId(id);
        qc.clear();
      },
      reload,
      can(...roles) {
        return company !== null && (company.role === 'admin' || roles.includes(company.role));
      },
    };
  }, [ready, user, companies, companyId, applyMe, clear, reload, qc]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth outside AuthProvider');
  return ctx;
}

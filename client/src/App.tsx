import { useEffect, useState } from 'react';
import type { User } from '../../shared/types';
import { api, getToken, setUnauthorizedHandler } from './api';
import { LoginForm } from './components/LoginForm';
import { VendorTracker } from './components/VendorTracker';

type AuthState = { status: 'checking' } | { status: 'anonymous' } | { status: 'signed-in'; user: User };

export function App() {
  const [auth, setAuth] = useState<AuthState>(() =>
    getToken() ? { status: 'checking' } : { status: 'anonymous' },
  );

  useEffect(() => {
    setUnauthorizedHandler(() => setAuth({ status: 'anonymous' }));
    if (auth.status !== 'checking') return;
    api
      .me()
      .then((user) => setAuth({ status: 'signed-in', user }))
      .catch(() => setAuth({ status: 'anonymous' }));
    // Runs once on first load to restore the session from a stored token.
  }, []);

  if (auth.status === 'checking') return <p className="centered muted">Loading…</p>;

  if (auth.status === 'anonymous') {
    return <LoginForm onLoggedIn={(user) => setAuth({ status: 'signed-in', user })} />;
  }

  return (
    <VendorTracker
      user={auth.user}
      onLogout={async () => {
        await api.logout().catch(() => {});
        setAuth({ status: 'anonymous' });
      }}
    />
  );
}

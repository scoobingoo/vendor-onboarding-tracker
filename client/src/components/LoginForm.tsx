import { useState, type FormEvent } from 'react';
import type { User } from '../../../shared/types';
import { api, ApiRequestError } from '../api';

export function LoginForm({ onLoggedIn }: { onLoggedIn: (user: User) => void }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      onLoggedIn(await api.login(username, password));
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : 'Could not reach the server.');
      setSubmitting(false);
    }
  }

  return (
    <main className="login">
      <form className="card login-card" onSubmit={handleSubmit}>
        <h1>Vendor Onboarding</h1>
        <p className="muted">Sign in as an Ops Coordinator.</p>

        <label>
          Username
          <input
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            autoComplete="username"
            autoFocus
            required
          />
        </label>
        <label>
          Password
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </label>

        {error && <p className="error" role="alert">{error}</p>}

        <button type="submit" className="primary" disabled={submitting}>
          {submitting ? 'Signing in…' : 'Sign in'}
        </button>

        <p className="hint">
          Demo accounts: <code>linh</code>, <code>huy</code>, <code>mai</code>. Password: <code>password</code>
        </p>
      </form>
    </main>
  );
}

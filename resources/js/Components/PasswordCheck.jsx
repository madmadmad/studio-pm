import { useState } from 'react';
import Button from './Button';
import { api } from '../lib/api';

// Changing two-factor or passkeys asks for your password again if you
// haven't given it in the last few hours (Fortify's password.confirm).
// `ensure()` resolves at once when it's still fresh; otherwise `prompt`
// renders a password field in place, and `ensure()` resolves when it's
// right -- or rejects with `cancelled` set when they back out.
export function usePasswordCheck() {
    const [pending, setPending] = useState(null);

    async function ensure() {
        const { confirmed } = await api.get('/user/confirmed-password-status');
        if (confirmed) return;
        await new Promise((resolve, reject) => setPending({ resolve, reject }));
    }

    const prompt = pending && (
        <PasswordPrompt
            onConfirmed={() => {
                pending.resolve();
                setPending(null);
            }}
            onCancel={() => {
                const error = new Error('Cancelled');
                error.cancelled = true;
                pending.reject(error);
                setPending(null);
            }}
        />
    );

    return { ensure, prompt };
}

function PasswordPrompt({ onConfirmed, onCancel }) {
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [checking, setChecking] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setChecking(true);
        setError(null);
        try {
            await api.post('/user/confirm-password', { password });
            onConfirmed();
        } catch (err) {
            setError(err.errors?.password?.[0] || err.message);
            setChecking(false);
        }
    }

    return (
        <form onSubmit={submit} className="password-check form-stack">
            <div>
                <label className="label" htmlFor="password-check">Your password, to go on</label>
                <input id="password-check" type="password" autoFocus required value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" className="input" />
                {error && <div className="form-error">{error}</div>}
            </div>
            <div className="form-actions">
                <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                <Button type="submit" variant="confirm" disabled={checking}>Continue</Button>
            </div>
        </form>
    );
}

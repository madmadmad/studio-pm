import { useState } from 'react';
import { Passkeys as WebAuthn } from '@laravel/passkeys';
import { Key, Trash } from '@phosphor-icons/react';
import Button from './Button';
import { usePasswordCheck } from './PasswordCheck';
import { api } from '../lib/api';
import { formatDate } from '../lib/format';

// A best guess at what to call a new passkey: the device it's being made on.
function deviceName() {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua)) return 'iPad';
    if (/Android/.test(ua)) return 'Android phone';
    if (/Mac/.test(ua)) return 'Mac';
    if (/Windows/.test(ua)) return 'Windows PC';
    return 'Passkey';
}

// Your passkeys (Fortify's /user/passkeys): each signs you in with Touch ID,
// Face ID or your device's passcode in place of the password and code.
// `passkeys` is the list as the page has it; `onChange` after an add (with
// the new one) or a removal, to reload it.
export default function Passkeys({ passkeys, onChange }) {
    const { ensure, prompt } = usePasswordCheck();
    const [name, setName] = useState(deviceName);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const supported = WebAuthn.isSupported();

    async function add(e) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await ensure();
            const added = await WebAuthn.register({ name: name.trim() || deviceName() });
            onChange(added);
        } catch (err) {
            if (!err.cancelled && err.name !== 'UserCancelledError') setError(err.message);
        } finally {
            setBusy(false);
        }
    }

    async function remove(passkey) {
        if (!confirm(`Remove the passkey “${passkey.name}”? It won't sign you in any more.`)) return;
        setError(null);
        try {
            await ensure();
            await api.delete(`/user/passkeys/${passkey.id}`);
            onChange();
        } catch (err) {
            if (!err.cancelled) setError(err.message);
        }
    }

    if (prompt) return prompt;

    return (
        <div className="form-stack">
            {passkeys.length > 0 && (
                <ul className="passkeys">
                    {passkeys.map((passkey) => (
                        <li key={passkey.id} className="passkeys__item">
                            <Key size={18} className="passkeys__icon" />
                            <div className="passkeys__text">
                                <div className="passkeys__name">{passkey.name}</div>
                                <div className="passkeys__meta">
                                    Added {formatDate(passkey.created_at)}
                                    {passkey.last_used_at ? ` · last used ${formatDate(passkey.last_used_at)}` : ''}
                                </div>
                            </div>
                            <button type="button" onClick={() => remove(passkey)} title="Remove" className="icon-btn icon-btn--danger">
                                <Trash />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {supported ? (
                <form onSubmit={add} className="passkeys__add">
                    <input value={name} onChange={(e) => setName(e.target.value)} aria-label="Passkey name" className="input" />
                    <Button type="submit" variant="secondary" disabled={busy}>Add a passkey</Button>
                </form>
            ) : (
                <p className="form-hint">This browser can&rsquo;t make passkeys.</p>
            )}
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

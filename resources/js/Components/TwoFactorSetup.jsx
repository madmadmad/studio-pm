import { useEffect, useState } from 'react';
import Button from './Button';
import { usePasswordCheck } from './PasswordCheck';
import { api } from '../lib/api';
import { copyToClipboard } from '../lib/clipboard';

// Setting up an authenticator (Fortify's two-factor routes): a new secret,
// shown as a QR code and a setup key; a code from the app to confirm it;
// then the recovery codes, once, to keep somewhere safe. `onDone` once
// they've seen them.
export default function TwoFactorSetup({ onDone }) {
    const { ensure, prompt } = usePasswordCheck();
    const [step, setStep] = useState('starting');
    const [qr, setQr] = useState(null);
    const [setupKey, setSetupKey] = useState('');
    const [code, setCode] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [recoveryCodes, setRecoveryCodes] = useState([]);

    useEffect(() => {
        start();
    }, []);

    async function start() {
        setError(null);
        try {
            await ensure();
            // Keeps a secret already made and not yet confirmed (a reload
            // mid-setup), so a code already scanned still works.
            await api.post('/user/two-factor-authentication');
            const [{ svg }, { secretKey }] = await Promise.all([
                api.get('/user/two-factor-qr-code'),
                api.get('/user/two-factor-secret-key'),
            ]);
            setQr(svg);
            setSetupKey(secretKey);
            setStep('scan');
        } catch (err) {
            setStep('stopped');
            if (!err.cancelled) setError(err.message);
        }
    }

    async function confirm(e) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await api.post('/user/confirmed-two-factor-authentication', { code: code.replace(/\s/g, '') });
            setRecoveryCodes(await api.get('/user/two-factor-recovery-codes'));
            setStep('codes');
        } catch (err) {
            setError(err.errors?.code?.[0] || err.message);
        } finally {
            setBusy(false);
        }
    }

    if (prompt) return prompt;

    if (step === 'starting') return <p className="form-hint">Getting a code ready…</p>;

    if (step === 'stopped') {
        return (
            <div className="form-stack">
                {error && <div className="form-message form-message--error">{error}</div>}
                <div>
                    <Button variant="secondary" onClick={start}>Start setup</Button>
                </div>
            </div>
        );
    }

    if (step === 'codes') {
        return (
            <div className="form-stack">
                <p className="form-hint">
                    If you ever lose your authenticator, each of these recovery codes signs you in once. Keep them somewhere safe &mdash; a note in Passwords works.
                </p>
                <RecoveryCodes codes={recoveryCodes} />
                <div className="form-actions">
                    <Button variant="confirm" onClick={onDone}>I&rsquo;ve saved them</Button>
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={confirm} className="form-stack">
            <p className="form-hint">
                In the Passwords app on your Mac or iPhone, open the entry for this site (or make one), choose <strong>Set Up Verification Code</strong>,
                and scan this code or paste the setup key. Google Authenticator, 1Password and other authenticator apps work too.
            </p>
            {/* An SVG Fortify draws from the secret -- nothing anyone typed. */}
            <div className="two-factor__qr" dangerouslySetInnerHTML={{ __html: qr }} />
            <SetupKey value={setupKey} />
            <div>
                <label className="label" htmlFor="two-factor-code">The 6-digit code it shows</label>
                <input
                    id="two-factor-code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    autoFocus
                    required
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    className="input two-factor__code"
                />
                {error && <div className="form-error">{error}</div>}
            </div>
            <div className="form-actions">
                <Button type="submit" variant="confirm" disabled={busy}>Turn on</Button>
            </div>
        </form>
    );
}

function SetupKey({ value }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        await copyToClipboard(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div>
            <div className="label">Setup key</div>
            <div className="two-factor__key">
                <code>{value}</code>
                <button type="button" onClick={copy} className="text-action text-action--sm">{copied ? 'Copied' : 'Copy'}</button>
            </div>
        </div>
    );
}

export function RecoveryCodes({ codes }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        await copyToClipboard(codes.join('\n'));
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="two-factor__recovery">
            <ul className="two-factor__codes">
                {codes.map((c) => <li key={c}><code>{c}</code></li>)}
            </ul>
            <button type="button" onClick={copy} className="text-action text-action--sm">{copied ? 'Copied' : 'Copy all'}</button>
        </div>
    );
}

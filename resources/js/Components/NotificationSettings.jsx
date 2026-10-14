import { useEffect, useState } from 'react';
import Button from './Button';
import Toggle from './Toggle';
import { api } from '../lib/api';
import { currentSubscription, installed, pushSupported, turnOff, turnOn } from '../lib/push';

const KINDS = [
    ['push_chat', 'Chat: direct messages, and mentions in channels'],
    ['push_proposals', 'When a client accepts a proposal'],
    ['push_invoices', 'When an invoice is paid'],
];

// Profile → Account: notifications on this device (web push), and which
// kinds reach any device. On the Mac they're best from the installed app
// (Safari → File → Add to Dock), which also badges its Dock icon.
export default function NotificationSettings({ user, push }) {
    const [on, setOn] = useState(null); // null while checking this device
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [sent, setSent] = useState(false);
    const [kinds, setKinds] = useState(() => Object.fromEntries(KINDS.map(([key]) => [key, user[key] !== false])));

    useEffect(() => {
        currentSubscription().then((s) => setOn(Boolean(s))).catch(() => setOn(false));
    }, []);

    async function run(fn) {
        setBusy(true);
        setError('');
        setSent(false);
        try {
            await fn();
        } catch (err) {
            setError(err.message || 'Something went wrong.');
        } finally {
            setBusy(false);
        }
    }

    const enable = () => run(async () => {
        await turnOn(push.publicKey);
        setOn(true);
    });
    const disable = () => run(async () => {
        await turnOff();
        setOn(false);
    });
    const test = () => run(async () => {
        await api.post('/api/push-subscriptions/test');
        setSent(true);
    });

    async function changeKind(key, value) {
        setKinds({ ...kinds, [key]: value });
        try {
            await api.patch('/api/profile/alerts', { [key]: value });
        } catch (err) {
            setKinds((current) => ({ ...current, [key]: !value }));
            setError(err.message || 'Could not save that.');
        }
    }

    return (
        <div className="form-panel form-stack">
            <div className="section-label section-label--ruled">Notifications</div>

            {!pushSupported ? (
                <p className="form-hint">This browser can&rsquo;t show notifications. On a Mac, open the app in Safari and choose File &rarr; Add to Dock.</p>
            ) : !push.publicKey ? (
                <p className="form-hint">Notifications aren&rsquo;t set up on the server yet.</p>
            ) : (
                <>
                    <p className="form-hint">
                        {on
                            ? 'On for this device. They arrive even when the app is closed.'
                            : 'Get chat messages, accepted proposals and paid invoices as notifications on this device, even when the app is closed.'}
                        {!installed && ' For a Dock icon with an unread badge, choose File → Add to Dock in Safari and turn them on there.'}
                    </p>
                    <div className="form-actions form-actions--start">
                        {on ? (
                            <>
                                <Button variant="secondary" onClick={test} disabled={busy}>Send a test</Button>
                                <Button variant="secondary" onClick={disable} disabled={busy}>Turn off on this device</Button>
                            </>
                        ) : (
                            <Button variant="secondary" onClick={enable} disabled={busy || on === null}>Turn on for this device</Button>
                        )}
                    </div>
                    {sent && <div className="form-message form-message--success">Sent. It should appear in a moment.</div>}
                </>
            )}
            {error && <div className="form-message form-message--error">{error}</div>}

            <div className="form-stack form-stack__break">
                {KINDS.map(([key, label]) => (
                    <div key={key}>
                        <Toggle checked={kinds[key]} onChange={(value) => changeKind(key, value)} label={label} />
                    </div>
                ))}
            </div>
        </div>
    );
}

import { router } from '@inertiajs/react';
import { useState } from 'react';
import Button from './Button';
import Passkeys from './Passkeys';
import SettingsSection from './SettingsSection';
import { usePasswordCheck } from './PasswordCheck';
import { RecoveryCodes } from './TwoFactorSetup';
import { api } from '../lib/api';
import { formatDate } from '../lib/format';

// Profile's Security tab: two-factor, which every staff member has
// (RequireTwoFactor) -- so it can be moved to a new authenticator but not
// turned off -- and passkeys, which are optional.
export default function SecuritySettings({ security }) {
    const reload = () => router.reload({ only: ['security'] });

    return (
        <div className="settings-sections">
            <TwoFactorPanel security={security} />
            <SettingsSection
                title="Passkeys"
                description={<>A passkey signs you in with Touch ID, Face ID or your device passcode &mdash; no password or code. It&rsquo;s saved in Passwords and shared across your Apple devices.</>}
            >
                <div className="form-panel">
                    <Passkeys passkeys={security.passkeys} onChange={reload} />
                </div>
            </SettingsSection>
        </div>
    );
}

function TwoFactorPanel({ security }) {
    const { ensure, prompt } = usePasswordCheck();
    const [codes, setCodes] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function run(action) {
        setBusy(true);
        setError(null);
        try {
            await ensure();
            await action();
        } catch (err) {
            if (!err.cancelled) setError(err.message);
        } finally {
            setBusy(false);
        }
    }

    const showCodes = () => run(async () => setCodes(await api.get('/user/two-factor-recovery-codes')));

    const newCodes = () => {
        if (!confirm('Make new recovery codes? The ones you have now will stop working.')) return;
        run(async () => {
            await api.post('/user/two-factor-recovery-codes');
            setCodes(await api.get('/user/two-factor-recovery-codes'));
        });
    };

    // Turns the old one off, then straight to setting up the new one (which
    // every page insists on until it's done). Remembered devices ask for a
    // code again.
    const newAuthenticator = () => {
        if (!confirm('Set up a new authenticator? The codes from your current one will stop working, and so will your recovery codes.')) return;
        run(async () => {
            await api.delete('/user/two-factor-authentication');
            router.visit('/security/setup');
        });
    };

    return (
        <SettingsSection title="Two-factor sign-in" description="After your password, a code from your authenticator app, so a stolen password alone can't get in.">
        <div className="form-panel">
            {prompt || (
                <div className="form-stack">
                    <p className="form-hint">
                        On{security.twoFactorSince ? ` since ${formatDate(security.twoFactorSince)}` : ''}. After your password, you give a
                        code from your authenticator &mdash; or nothing, on a device you chose to remember for 30 days.
                    </p>
                    {codes && <RecoveryCodes codes={codes} />}
                    {error && <div className="form-message form-message--error">{error}</div>}
                    <div className="form-actions form-actions--start">
                        {codes
                            ? <Button variant="secondary" onClick={newCodes} disabled={busy}>Make new recovery codes</Button>
                            : <Button variant="secondary" onClick={showCodes} disabled={busy}>Show recovery codes</Button>}
                        <Button variant="secondary" onClick={newAuthenticator} disabled={busy}>Set up a new authenticator</Button>
                    </div>
                </div>
            )}
        </div>
        </SettingsSection>
    );
}

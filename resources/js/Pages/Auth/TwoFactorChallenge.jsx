import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import Button from '../../Components/Button';
import Toggle from '../../Components/Toggle';
import AuthLayout from '../../Layouts/AuthLayout';

// After the password (Fortify's two-factor challenge): a code from the
// authenticator, or one of the recovery codes. "Remember this device"
// skips this step here for 30 days (App\Support\TrustedDevice).
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
        remember_device: true,
    });

    function submit(e) {
        e.preventDefault();
        post('/two-factor-challenge', { onError: () => reset('code', 'recovery_code') });
    }

    function switchMode() {
        reset('code', 'recovery_code');
        setRecovery(!recovery);
    }

    return (
        <AuthLayout logo>
            <form onSubmit={submit} className="card auth-shell__card">
                <p className="auth-shell__text">
                    {recovery
                        ? 'Enter one of the recovery codes you saved when you set up two-factor. Each works once.'
                        : 'Enter the 6-digit code from your authenticator — in the Passwords app, it’s on this site’s entry.'}
                </p>
                {recovery ? (
                    <div>
                        <label className="label" htmlFor="recovery-code">Recovery code</label>
                        <input
                            id="recovery-code"
                            autoFocus
                            autoComplete="off"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value.trim())}
                            className="input"
                        />
                        {errors.recovery_code && <div className="form-error">{errors.recovery_code}</div>}
                    </div>
                ) : (
                    <div>
                        <label className="label" htmlFor="code">Code</label>
                        <input
                            id="code"
                            autoFocus
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value.replace(/\s/g, ''))}
                            className="input two-factor__code"
                        />
                        {errors.code && <div className="form-error">{errors.code}</div>}
                    </div>
                )}
                <Toggle
                    checked={data.remember_device}
                    onChange={(remember) => setData('remember_device', remember)}
                    label="Remember this device for 30 days"
                    className="toggle--muted"
                />
                <Button type="submit" disabled={processing} className="btn--lg auth-shell__submit">
                    Sign in
                </Button>
                <button type="button" onClick={switchMode} className="text-action text-action--sm auth-shell__back">
                    {recovery ? 'Use a code from your authenticator' : 'Lost your authenticator? Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Button from '../../Components/Button';
import Passkeys from '../../Components/Passkeys';
import TwoFactorSetup from '../../Components/TwoFactorSetup';
import AuthLayout from '../../Layouts/AuthLayout';

// Where staff are sent until two-factor is set up (RequireTwoFactor):
// an authenticator first, then a passkey if they like.
export default function SecuritySetup() {
    const [step, setStep] = useState('authenticator');
    const [added, setAdded] = useState([]);

    return (
        <AuthLayout logo subtitle="Two-factor sign-in">
            <Head title="Set up two-factor" />
            <div className="card auth-shell__card">
                {step === 'authenticator' ? (
                    <>
                        <p className="auth-shell__text">
                            To keep the studio&rsquo;s books and clients safe, signing in takes a code from an authenticator app as well as your
                            password. On a device you trust you can skip it for 30 days at a time.
                        </p>
                        <TwoFactorSetup onDone={() => setStep('passkey')} />
                    </>
                ) : (
                    <>
                        <p className="auth-shell__text">
                            Two-factor is on. You can also add a passkey: it signs you in with Touch ID or Face ID alone, no password or code.
                        </p>
                        {added.map((passkey) => (
                            <div key={passkey.id} className="form-message form-message--success">Added the passkey &ldquo;{passkey.name}&rdquo;</div>
                        ))}
                        <Passkeys passkeys={[]} onChange={(passkey) => setAdded((list) => [...list, passkey])} />
                        <Button variant="confirm" onClick={() => router.visit('/')} className="btn--lg auth-shell__submit">
                            {added.length ? 'Done' : 'Skip for now'}
                        </Button>
                    </>
                )}
                <Link href="/logout" method="post" as="button" className="text-action text-action--sm auth-shell__back">
                    Sign out
                </Link>
            </div>
        </AuthLayout>
    );
}

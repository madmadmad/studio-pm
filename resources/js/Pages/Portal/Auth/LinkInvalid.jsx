import { Link } from '@inertiajs/react';
import AuthLayout from '../../../Layouts/AuthLayout';

const MESSAGES = {
    used: 'This sign-in link has already been used. Request a new one to sign in.',
    expired: 'This sign-in link has expired. Request a new one to sign in.',
    invalid: "This sign-in link isn't valid. Request a new one to sign in.",
};

export default function LinkInvalid({ reason }) {
    return (
        <AuthLayout title="Client Hub">
            <div className="card auth-shell__card auth-shell__card--message">
                <p className="auth-shell__text">{MESSAGES[reason] ?? MESSAGES.invalid}</p>
                <Link href="/portal/login" className="btn btn--primary btn--lg">
                    Request a new link
                </Link>
            </div>
        </AuthLayout>
    );
}

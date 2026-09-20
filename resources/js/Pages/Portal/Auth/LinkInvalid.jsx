import { Link } from '@inertiajs/react';

const MESSAGES = {
    used: 'This sign-in link has already been used. Request a new one to sign in.',
    expired: 'This sign-in link has expired. Request a new one to sign in.',
    invalid: "This sign-in link isn't valid. Request a new one to sign in.",
};

export default function LinkInvalid({ reason }) {
    return (
        <div className="min-h-screen flex items-center justify-center bg-paper text-ink px-4">
            <div className="w-full max-w-sm text-center">
                <div className="text-xl font-semibold mb-2">Client Hub</div>
                <div className="bg-white rounded-lg border border-border p-6 flex flex-col gap-4">
                    <p className="text-sm text-sage">{MESSAGES[reason] ?? MESSAGES.invalid}</p>
                    <Link href="/portal/login" className="bg-ink text-white text-sm font-medium px-3 py-2 rounded">
                        Request a new link
                    </Link>
                </div>
            </div>
        </div>
    );
}

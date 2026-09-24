import { Link, useForm } from '@inertiajs/react';
import Button from '../../Components/Button';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthLayout title="Studio PM" subtitle="Reset your password">
            <form onSubmit={submit} className="card auth-shell__card">
                <p className="text-sm text-shadow-grey">
                    Enter your email and we&rsquo;ll send you a link to reset your password.
                </p>

                {status && <div className="text-sm text-fern">{status}</div>}

                <div>
                    <label className="field-label">Email</label>
                    <input
                        type="email"
                        autoFocus
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="field"
                    />
                    {errors.email && <div className="text-xs text-watermelon mt-1">{errors.email}</div>}
                </div>

                <Button type="submit" disabled={processing} className="btn-lg mt-2">
                    Email password reset link
                </Button>

                <Link href="/login" className="text-sm text-shadow-grey hover:text-gunmetal text-center mt-1">
                    Back to sign in
                </Link>
            </form>
        </AuthLayout>
    );
}

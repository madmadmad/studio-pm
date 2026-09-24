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
                <p className="auth-shell__text">
                    Enter your email and we&rsquo;ll send you a link to reset your password.
                </p>

                {status && <div className="form-message form-message--success">{status}</div>}

                <div>
                    <label className="label">Email</label>
                    <input
                        type="email"
                        autoFocus
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="input"
                    />
                    {errors.email && <div className="form-error">{errors.email}</div>}
                </div>

                <Button type="submit" disabled={processing} className="btn--lg auth-shell__submit">
                    Email password reset link
                </Button>

                <Link href="/login" className="text-action text-action--sm auth-shell__back">
                    Back to sign in
                </Link>
            </form>
        </AuthLayout>
    );
}

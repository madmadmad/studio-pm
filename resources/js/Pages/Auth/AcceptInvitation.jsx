import { useForm } from '@inertiajs/react';
import Button from '../../Components/Button';
import AuthLayout from '../../Layouts/AuthLayout';

export default function AcceptInvitation({ token, email, valid }) {
    const { data, setData, post, processing, errors } = useForm({
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/invite/${token}`);
    }

    if (!valid) {
        return (
            <AuthLayout title="Studio PM">
                <div className="card auth-shell__card auth-shell__card--message">
                    <p className="auth-shell__text">
                        This invite link is invalid or has expired. Ask a manager to resend your invite.
                    </p>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Studio PM" subtitle="Welcome &mdash; set your password to get started">
            <form onSubmit={submit} className="card auth-shell__card">
                <div>
                    <label className="label">Email</label>
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="input"
                        readOnly
                    />
                </div>

                <div>
                    <label className="label">Password</label>
                    <input
                        type="password"
                        autoFocus
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="input"
                    />
                    {errors.password && <div className="form-error">{errors.password}</div>}
                </div>

                <div>
                    <label className="label">Confirm password</label>
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        className="input"
                    />
                </div>

                <Button type="submit" disabled={processing} className="btn--lg auth-shell__submit">
                    Set password and sign in
                </Button>
            </form>
        </AuthLayout>
    );
}

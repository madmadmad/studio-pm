import { useForm } from '@inertiajs/react';
import Button from '../../Components/Button';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ResetPassword({ email, token }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post('/reset-password');
    }

    return (
        <AuthLayout title="Studio PM" subtitle="Set a new password">
            <form onSubmit={submit} className="card auth-shell__card">
                <div>
                    <label className="label">Email</label>
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="input"
                    />
                    {errors.email && <div className="form-error">{errors.email}</div>}
                </div>

                <div>
                    <label className="label">New password</label>
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

                <Button type="submit" disabled={processing} className="btn--lg mt-2">
                    Reset password
                </Button>
            </form>
        </AuthLayout>
    );
}

import { Head, useForm } from '@inertiajs/react';
import Button from '../../Components/Button';
import AuthLayout from '../../Layouts/AuthLayout';

// Before a change to how you sign in, if you haven't given your password
// in the last few hours (Fortify's password.confirm). Profile and setup
// ask in place instead (PasswordCheck); this is for anything else.
export default function ConfirmPassword() {
    const { data, setData, post, processing, errors } = useForm({ password: '' });

    function submit(e) {
        e.preventDefault();
        post('/user/confirm-password');
    }

    return (
        <AuthLayout logo>
            <Head title="Confirm password" />
            <form onSubmit={submit} className="card auth-shell__card">
                <p className="auth-shell__text">Please enter your password to go on.</p>
                <div>
                    <label className="label" htmlFor="password">Password</label>
                    <input
                        id="password"
                        type="password"
                        autoFocus
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="input"
                    />
                    {errors.password && <div className="form-error">{errors.password}</div>}
                </div>
                <Button type="submit" disabled={processing} className="btn--lg auth-shell__submit">
                    Continue
                </Button>
            </form>
        </AuthLayout>
    );
}

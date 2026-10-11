import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Button from '../../Components/Button';
import Toggle from '../../Components/Toggle';
import AuthLayout from '../../Layouts/AuthLayout';

// Staff sign in with their email and password. The studio's accountant has
// no password: they ask for a sign-in link by email instead
// (AccountantSignInController), from the second view here.
export default function Login({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });
    const link = useForm({ email: '' });
    // A message back from asking for a link (or a used/expired one) opens
    // that view again.
    const [byLink, setByLink] = useState(Boolean(status));

    function submit(e) {
        e.preventDefault();
        post('/login');
    }

    function requestLink(e) {
        e.preventDefault();
        link.post('/login/link', { preserveScroll: true, onSuccess: () => link.reset('email') });
    }

    if (byLink) {
        return (
            <AuthLayout logo>
                <form onSubmit={requestLink} className="card auth-shell__card">
                    <p className="auth-shell__text">
                        Enter your email and we&rsquo;ll send you a link to sign in. It works once, for 20 minutes.
                    </p>
                    {status && <div className="form-message form-message--success">{status}</div>}
                    <div>
                        <label className="label" htmlFor="link-email">Email</label>
                        <input
                            id="link-email"
                            type="email"
                            autoFocus
                            value={link.data.email}
                            onChange={(e) => link.setData('email', e.target.value)}
                            className="input"
                        />
                        {link.errors.email && <div className="form-error">{link.errors.email}</div>}
                    </div>
                    <Button type="submit" disabled={link.processing} className="btn--lg auth-shell__submit">
                        Email me a sign-in link
                    </Button>
                    <button type="button" onClick={() => setByLink(false)} className="text-action text-action--sm auth-shell__back">
                        Sign in with a password instead
                    </button>
                </form>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout logo>
            <form onSubmit={submit} className="card auth-shell__card">
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

                <div>
                    <label className="label">Password</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="input"
                    />
                </div>

                <div className="auth-shell__row">
                    <Toggle
                        checked={data.remember}
                        onChange={(remember) => setData('remember', remember)}
                        label="Remember me"
                        className="toggle--muted"
                    />
                    <Link href="/forgot-password" className="text-action text-action--sm">
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" disabled={processing} className="btn--lg auth-shell__submit">
                    Sign in
                </Button>
                <button type="button" onClick={() => setByLink(true)} className="text-action text-action--sm auth-shell__back">
                    Accountant? Email me a sign-in link
                </button>
            </form>
        </AuthLayout>
    );
}

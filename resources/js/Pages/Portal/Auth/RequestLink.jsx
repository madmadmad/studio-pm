import { useForm, usePage } from '@inertiajs/react';
import Button from '../../../Components/Button';
import AuthLayout from '../../../Layouts/AuthLayout';

export default function RequestLink() {
    const { props } = usePage();
    const status = props.status;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/portal/login');
    }

    return (
        <AuthLayout title="Client Hub" subtitle="Sign in to follow your project">
            <form onSubmit={submit} className="card auth-shell__card">
                {status && <div className="form-message form-message--success">{status}</div>}
                <p className="auth-shell__text">
                    Enter your email and we&rsquo;ll send you a link to sign in &mdash; no password needed.
                </p>
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
                    Email me a sign-in link
                </Button>
            </form>
        </AuthLayout>
    );
}

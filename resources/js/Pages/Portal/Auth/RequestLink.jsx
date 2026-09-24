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
                {status && <div className="text-sm text-fern">{status}</div>}
                <p className="text-sm text-shadow-grey">
                    Enter your email and we&rsquo;ll send you a link to sign in &mdash; no password needed.
                </p>
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
                    Email me a sign-in link
                </Button>
            </form>
        </AuthLayout>
    );
}

import { useForm, usePage } from '@inertiajs/react';
import Button from '../../../Components/Button';

export default function RequestLink() {
    const { props } = usePage();
    const status = props.status;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/portal/login');
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-porcelain text-gunmetal px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <div className="text-xl font-semibold">Client Hub</div>
                    <div className="text-sm text-shadow-grey">Sign in to follow your project</div>
                </div>

                <form onSubmit={submit} className="card p-6 flex flex-col gap-3">
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
                        {errors.email && <div className="text-xs text-fuchsia mt-1">{errors.email}</div>}
                    </div>
                    <Button type="submit" disabled={processing} className="btn-lg mt-2">
                        Email me a sign-in link
                    </Button>
                </form>
            </div>
        </div>
    );
}

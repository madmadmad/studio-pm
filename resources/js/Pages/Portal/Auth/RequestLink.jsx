import { useForm, usePage } from '@inertiajs/react';

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

                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-6 flex flex-col gap-3">
                    {status && <div className="text-sm text-fern">{status}</div>}
                    <p className="text-sm text-shadow-grey">
                        Enter your email and we&rsquo;ll send you a link to sign in &mdash; no password needed.
                    </p>
                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">Email</label>
                        <input
                            type="email"
                            autoFocus
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full"
                        />
                        {errors.email && <div className="text-xs text-fuchsia mt-1">{errors.email}</div>}
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-gunmetal text-white text-sm font-medium px-3 py-2 rounded mt-2 disabled:opacity-50"
                    >
                        Email me a sign-in link
                    </button>
                </form>
            </div>
        </div>
    );
}

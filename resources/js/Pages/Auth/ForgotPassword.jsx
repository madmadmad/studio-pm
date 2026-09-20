import { Link, useForm } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-paper text-ink px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <div className="text-xl font-semibold">Studio PM</div>
                    <div className="text-sm text-sage">Reset your password</div>
                </div>

                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-6 flex flex-col gap-3">
                    <p className="text-sm text-sage">
                        Enter your email and we&rsquo;ll send you a link to reset your password.
                    </p>

                    {status && <div className="text-sm text-pine">{status}</div>}

                    <div>
                        <label className="block text-xs font-medium text-sage mb-1">Email</label>
                        <input
                            type="email"
                            autoFocus
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full"
                        />
                        {errors.email && <div className="text-xs text-brick mt-1">{errors.email}</div>}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-ink text-white text-sm font-medium px-3 py-2 rounded mt-2 disabled:opacity-50"
                    >
                        Email password reset link
                    </button>

                    <Link href="/login" className="text-sm text-sage hover:text-ink text-center mt-1">
                        Back to sign in
                    </Link>
                </form>
            </div>
        </div>
    );
}

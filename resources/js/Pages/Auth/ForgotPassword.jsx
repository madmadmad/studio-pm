import { Link, useForm } from '@inertiajs/react';
import Button from '../../Components/Button';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-porcelain text-gunmetal px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <div className="text-xl font-semibold">Studio PM</div>
                    <div className="text-sm text-shadow-grey">Reset your password</div>
                </div>

                <form onSubmit={submit} className="card p-6 flex flex-col gap-3">
                    <p className="text-sm text-shadow-grey">
                        Enter your email and we&rsquo;ll send you a link to reset your password.
                    </p>

                    {status && <div className="text-sm text-fern">{status}</div>}

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
                        Email password reset link
                    </Button>

                    <Link href="/login" className="text-sm text-shadow-grey hover:text-gunmetal text-center mt-1">
                        Back to sign in
                    </Link>
                </form>
            </div>
        </div>
    );
}

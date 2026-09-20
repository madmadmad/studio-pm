import { Link, useForm } from '@inertiajs/react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        post('/login');
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-porcelain text-gunmetal px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <div className="text-xl font-semibold">Studio PM</div>
                    <div className="text-sm text-shadow-grey">Sign in to your workspace</div>
                </div>

                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-6 flex flex-col gap-3">
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

                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">Password</label>
                        <input
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full"
                        />
                    </div>

                    <div className="flex items-center justify-between">
                        <label className="flex items-center gap-2 text-sm text-shadow-grey">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                            />
                            Remember me
                        </label>
                        <Link href="/forgot-password" className="text-sm text-shadow-grey hover:text-gunmetal">
                            Forgot password?
                        </Link>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-gunmetal text-white text-sm font-medium px-3 py-2 rounded mt-2 disabled:opacity-50"
                    >
                        Sign in
                    </button>
                </form>
            </div>
        </div>
    );
}

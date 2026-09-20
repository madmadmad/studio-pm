import { useForm } from '@inertiajs/react';

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
        <div className="min-h-screen flex items-center justify-center bg-porcelain text-gunmetal px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <div className="text-xl font-semibold">Studio PM</div>
                    <div className="text-sm text-shadow-grey">Set a new password</div>
                </div>

                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-6 flex flex-col gap-3">
                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full"
                        />
                        {errors.email && <div className="text-xs text-fuchsia mt-1">{errors.email}</div>}
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">New password</label>
                        <input
                            type="password"
                            autoFocus
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full"
                        />
                        {errors.password && <div className="text-xs text-fuchsia mt-1">{errors.password}</div>}
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">Confirm password</label>
                        <input
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full"
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="bg-gunmetal text-white text-sm font-medium px-3 py-2 rounded mt-2 disabled:opacity-50"
                    >
                        Reset password
                    </button>
                </form>
            </div>
        </div>
    );
}

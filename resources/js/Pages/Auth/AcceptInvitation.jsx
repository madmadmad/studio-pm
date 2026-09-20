import { useForm } from '@inertiajs/react';

export default function AcceptInvitation({ token, email, valid }) {
    const { data, setData, post, processing, errors } = useForm({
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/invite/${token}`);
    }

    if (!valid) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-porcelain text-gunmetal px-4">
                <div className="w-full max-w-sm text-center">
                    <div className="text-xl font-semibold mb-2">Studio PM</div>
                    <div className="bg-white rounded-lg border border-border p-6">
                        <p className="text-sm text-shadow-grey">
                            This invite link is invalid or has expired. Ask a manager to resend your invite.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-porcelain text-gunmetal px-4">
            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <div className="text-xl font-semibold">Studio PM</div>
                    <div className="text-sm text-shadow-grey">Welcome &mdash; set your password to get started</div>
                </div>

                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-6 flex flex-col gap-3">
                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="border border-border rounded px-3 py-2 text-sm w-full bg-porcelain"
                            readOnly
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-shadow-grey mb-1">Password</label>
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
                        Set password and sign in
                    </button>
                </form>
            </div>
        </div>
    );
}

import { useForm } from '@inertiajs/react';
import Button from '../../Components/Button';

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

                <form onSubmit={submit} className="card p-6 flex flex-col gap-3">
                    <div>
                        <label className="field-label">Email</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="field"
                        />
                        {errors.email && <div className="text-xs text-fuchsia mt-1">{errors.email}</div>}
                    </div>

                    <div>
                        <label className="field-label">New password</label>
                        <input
                            type="password"
                            autoFocus
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="field"
                        />
                        {errors.password && <div className="text-xs text-fuchsia mt-1">{errors.password}</div>}
                    </div>

                    <div>
                        <label className="field-label">Confirm password</label>
                        <input
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="field"
                        />
                    </div>

                    <Button type="submit" disabled={processing} className="btn-lg mt-2">
                        Reset password
                    </Button>
                </form>
            </div>
        </div>
    );
}

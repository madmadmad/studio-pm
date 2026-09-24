import { Link, useForm } from '@inertiajs/react';
import Button from '../../Components/Button';
import AuthLayout from '../../Layouts/AuthLayout';

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
        <AuthLayout title="Studio PM" subtitle="Sign in to your workspace">
            <form onSubmit={submit} className="card auth-shell__card">
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

                <div>
                    <label className="field-label">Password</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="field"
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

                <Button type="submit" disabled={processing} className="btn-lg mt-2">
                    Sign in
                </Button>
            </form>
        </AuthLayout>
    );
}

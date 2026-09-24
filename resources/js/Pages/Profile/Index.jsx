import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Avatar from '../../Components/Avatar';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

export default function ProfileIndex({ profileUser }) {
    const [user, setUser] = useState(profileUser);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const inputRef = useRef(null);

    async function uploadAvatar(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;

        setSaving(true);
        setError('');
        try {
            const form = new FormData();
            form.append('avatar', file);
            const updated = await api.postForm('/api/profile/avatar', form);
            setUser(updated);
            router.reload({ only: ['auth'] }); // the sidebar's avatar is a separate shared prop
        } catch (err) {
            setError(err.message || 'Could not upload that photo.');
        } finally {
            setSaving(false);
        }
    }

    async function removeAvatar() {
        setSaving(true);
        try {
            const updated = await api.delete('/api/profile/avatar');
            setUser(updated);
            router.reload({ only: ['auth'] });
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title="My profile" />
            <PageHeader
                title="My profile"
                subtitle="Your photo shows up next to your messages and throughout the app."
            />

            <div className="card card-padded max-w-md">
                <div className="flex items-center gap-4 mb-4">
                    <Avatar name={user.name} avatarUrl={user.avatar_url} id={user.id} size={72} />
                    <div>
                        <div className="font-semibold">{user.name}</div>
                        <div className="text-sm text-shadow-grey">{user.email}</div>
                    </div>
                </div>

                {error && <div className="text-sm text-watermelon mb-3">{error}</div>}

                <div className="flex gap-2">
                    <Button variant="secondary" onClick={() => inputRef.current.click()} disabled={saving}>
                        {user.avatar_url ? 'Change photo' : 'Upload photo'}
                    </Button>
                    {user.avatar_url && (
                        <Button variant="danger" onClick={removeAvatar} disabled={saving}>Remove photo</Button>
                    )}
                </div>
                <input ref={inputRef} type="file" accept="image/*" className="hidden" onChange={uploadAvatar} />
            </div>
        </AppLayout>
    );
}

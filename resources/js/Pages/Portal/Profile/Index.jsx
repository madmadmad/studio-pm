import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import PortalLayout from '../../../Layouts/PortalLayout';
import Avatar from '../../../Components/Avatar';
import Button from '../../../Components/Button';
import { api } from '../../../lib/api';

export default function PortalProfileIndex({ profileContact }) {
    const [contact, setContact] = useState(profileContact);
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
            const updated = await api.postForm('/api/portal/profile/avatar', form);
            setContact(updated);
            router.reload({ only: ['auth'] });
        } catch (err) {
            setError(err.message || 'Could not upload that photo.');
        } finally {
            setSaving(false);
        }
    }

    async function removeAvatar() {
        setSaving(true);
        try {
            const updated = await api.delete('/api/portal/profile/avatar');
            setContact(updated);
            router.reload({ only: ['auth'] });
        } finally {
            setSaving(false);
        }
    }

    return (
        <PortalLayout>
            <Head title="My profile" />
            <h1 className="font-display text-2xl font-semibold mb-1">My profile</h1>
            <p className="text-sm text-shadow-grey mb-6">Your photo shows up next to your messages.</p>

            <div className="card card-padded max-w-md">
                <div className="flex items-center gap-4 mb-4">
                    <Avatar name={contact.name} avatarUrl={contact.avatar_url} id={contact.id} size={72} />
                    <div>
                        <div className="font-semibold">{contact.name}</div>
                        <div className="text-sm text-shadow-grey">{contact.email}</div>
                    </div>
                </div>

                {error && <div className="text-sm text-watermelon mb-3">{error}</div>}

                <div className="flex gap-2">
                    <Button variant="secondary" onClick={() => inputRef.current.click()} disabled={saving}>
                        {contact.avatar_url ? 'Change photo' : 'Upload photo'}
                    </Button>
                    {contact.avatar_url && (
                        <Button variant="danger" onClick={removeAvatar} disabled={saving}>Remove photo</Button>
                    )}
                </div>
                <input ref={inputRef} type="file" accept="image/*" className="hidden" onChange={uploadAvatar} />
            </div>
        </PortalLayout>
    );
}

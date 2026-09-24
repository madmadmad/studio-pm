import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import PortalLayout from '../../../Layouts/PortalLayout';
import Avatar from '../../../Components/Avatar';
import Button from '../../../Components/Button';
import { api } from '../../../lib/api';
import PageHeader from '../../../Components/PageHeader';

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
            <PageHeader
                title="My profile"
                subtitle="Your photo shows up next to your messages."
            />

            <div className="card card--padded card--narrow profile-card">
                <div className="profile-card__identity">
                    <Avatar name={contact.name} avatarUrl={contact.avatar_url} id={contact.id} size={72} />
                    <div>
                        <div className="profile-card__name">{contact.name}</div>
                        <div className="profile-card__email">{contact.email}</div>
                    </div>
                </div>

                {error && <div className="form-message form-message--error profile-card__error">{error}</div>}

                <div className="profile-card__actions">
                    <Button variant="secondary" onClick={() => inputRef.current.click()} disabled={saving}>
                        {contact.avatar_url ? 'Change photo' : 'Upload photo'}
                    </Button>
                    {contact.avatar_url && (
                        <Button variant="danger" onClick={removeAvatar} disabled={saving}>Remove photo</Button>
                    )}
                </div>
                <input ref={inputRef} type="file" accept="image/*" hidden onChange={uploadAvatar} />
            </div>
        </PortalLayout>
    );
}

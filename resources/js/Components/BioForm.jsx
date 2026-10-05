import { useRef, useState } from 'react';
import BioPhoto from './BioPhoto';
import Button from './Button';
import RichTextEditor from './RichTextEditor';
import { api } from '../lib/api';
import { toRichText } from '../lib/richText';
import { shrinkImage } from '../lib/shrinkImage';

// Someone's bio photo, position and bio -- what a proposal's team section
// shows. The bio photo is its own headshot, not their avatar; it saves as
// soon as it's picked (to `photoEndpoint`), the rest on Save (`endpoint`).
// Their own profile, or a super admin editing them from the Team page.
export default function BioForm({ user, endpoint, photoEndpoint, onSaved, className = 'form-panel form-stack' }) {
    const [photoUrl, setPhotoUrl] = useState(user.bio_photo_url ?? null);
    const [photoBusy, setPhotoBusy] = useState(false);
    const fileRef = useRef(null);
    const [form, setForm] = useState({ job_title: user.job_title ?? '', bio: toRichText(user.bio ?? '') });
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');

    async function photo(task) {
        setPhotoBusy(true);
        setError('');
        try {
            const updated = await task();
            setPhotoUrl(updated.bio_photo_url ?? null);
            onSaved?.(updated);
        } catch (err) {
            setError(err.message || 'Could not update the bio photo.');
        } finally {
            setPhotoBusy(false);
        }
    }

    function upload(e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        photo(async () => {
            const fd = new FormData();
            fd.append('photo', await shrinkImage(file));
            return api.postForm(photoEndpoint, fd);
        });
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setSaved(false);
        setError('');
        try {
            const updated = await api.patch(endpoint, { job_title: form.job_title.trim() || null, bio: form.bio });
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
            onSaved?.(updated);
        } catch (err) {
            setError(err.message || 'Could not save the bio.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className={className}>
            <div className="section-label section-label--ruled">Bio</div>
            <p className="form-hint">Shown when this person is in a proposal&rsquo;s team section. The bio photo is separate from the avatar used around the app &mdash; a headshot for clients.</p>
            <div className="bio-form__photo">
                <BioPhoto name={user.name} url={photoUrl} size="sm" />
                <Button type="button" variant="secondary" onClick={() => fileRef.current.click()} disabled={photoBusy}>
                    {photoUrl ? 'Change bio photo' : 'Upload bio photo'}
                </Button>
                {photoUrl && (
                    <Button type="button" variant="danger" onClick={() => photo(() => api.delete(photoEndpoint))} disabled={photoBusy}>Remove</Button>
                )}
                <input ref={fileRef} type="file" accept="image/*" hidden onChange={upload} />
            </div>
            <div>
                <label className="label" htmlFor={`job-title-${user.id}`}>Position</label>
                <input
                    id={`job-title-${user.id}`}
                    value={form.job_title}
                    onChange={(e) => setForm({ ...form, job_title: e.target.value })}
                    placeholder="e.g. Creative Director"
                    className="input"
                />
            </div>
            <div>
                <label className="label">Bio</label>
                <RichTextEditor value={form.bio} onChange={(bio) => setForm({ ...form, bio })} />
            </div>
            {error && <div className="form-message form-message--error">{error}</div>}
            <div className="form-actions">
                {saved && <span className="form-message form-message--success">Saved</span>}
                <Button type="submit" variant="confirm" disabled={saving}>Save</Button>
            </div>
        </form>
    );
}

import { useState } from 'react';
import Button from '../Button';
import Drawer from '../Drawer';
import { api } from '../../lib/api';

// The shell every entry form shares: a title, its fields, any error the
// server sends back (an unbalanced entry, a locked date), and the Post
// button. `payload()` builds what's posted to `endpoint`; `canPost` keeps
// the button off until the form is complete.
export default function EntryFormDrawer({ title, hint, endpoint, payload, canPost = true, size, onPosted, onClose, children }) {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            onPosted(await api.post(endpoint, payload()));
            onClose();
        } catch (err) {
            setError(err.message || 'Could not post this entry.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose} size={size}>
            <h2 className="drawer__title">{title}</h2>
            {hint && <p className="form-hint drawer__section">{hint}</p>}
            <form onSubmit={submit}>
                {children}
                {error && <div className="form-message form-message--error drawer__section">{error}</div>}
                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving || !canPost}>Post entry</Button>
                </div>
            </form>
        </Drawer>
    );
}

// A labeled field in an entry form, in the drawers' style.
export function Field({ label, children, className = '' }) {
    return (
        <div className={className}>
            <div className="section-label section-label--tight">{label}</div>
            {children}
        </div>
    );
}

// "12.34" → 1234, for live totals. The server does the real conversion.
export function cents(value) {
    return Math.round((parseFloat(value) || 0) * 100);
}

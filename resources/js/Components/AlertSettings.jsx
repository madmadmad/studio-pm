import { useState } from 'react';
import Toggle from './Toggle';
import { api } from '../lib/api';

// Profile → Account: which alerts you get in the bell menu. For now, the
// client opening an invoice or proposal you sent.
export default function AlertSettings({ user }) {
    const [on, setOn] = useState(user.notify_on_document_open !== false);
    const [error, setError] = useState('');

    async function change(value) {
        setOn(value);
        setError('');
        try {
            await api.patch('/api/profile/alerts', { notify_on_document_open: value });
        } catch (err) {
            setOn(!value);
            setError(err.message || 'Could not save that.');
        }
    }

    return (
        <div className="form-panel form-stack">
            <div className="section-label section-label--ruled">Alerts</div>
            <Toggle checked={on} onChange={change} label="When a client first opens an invoice or proposal I sent" />
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

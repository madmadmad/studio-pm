import { useState } from 'react';
import AutoResizeTextarea from '../AutoResizeTextarea';
import Button from '../Button';
import Drawer from '../Drawer';
import Toggle from '../Toggle';
import { api } from '../../lib/api';

// Add an account under a group heading (no `account`), or edit one: code,
// name, notes, its heading, and whether it's active. Accounts are never
// deleted. The ones the app posts to on its own (system_key) stay active,
// and a heading stays a heading.
export default function AccountDrawer({ account, accounts, onSaved, onClose }) {
    const headings = accounts.filter((a) => a.parent_id === null && (!account || a.type === account.type));
    const isHeading = account && account.parent_id === null;
    const [form, setForm] = useState(account
        ? { code: account.code, name: account.name, description: account.description ?? '', parent_id: account.parent_id, is_active: account.is_active }
        : { code: '', name: '', description: '', parent_id: headings[0]?.id ?? null });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    function field(name) {
        return { value: form[name] ?? '', onChange: (e) => setForm({ ...form, [name]: e.target.value }) };
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setError('');
        try {
            const payload = { ...form, description: form.description || null };
            if (isHeading) delete payload.parent_id;
            onSaved(account ? await api.patch(`/api/accounts/${account.id}`, payload) : await api.post('/api/accounts', payload));
            onClose();
        } catch (err) {
            setError(err.message || 'Could not save this account.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">{account ? 'Edit account' : 'New account'}</h2>
            <form onSubmit={save}>
                <div className="form-grid drawer__section">
                    <div>
                        <div className="section-label section-label--tight">Code</div>
                        <input required placeholder="e.g. 6185" {...field('code')} className="input input--xs u-tabular-nums" />
                    </div>
                    <div>
                        <div className="section-label section-label--tight">Name</div>
                        <input required autoFocus={!account} {...field('name')} className="input input--xs" />
                    </div>
                    {!isHeading && (
                        <div className="form-grid__full">
                            <div className="section-label section-label--tight">Under</div>
                            <select value={form.parent_id ?? ''} onChange={(e) => setForm({ ...form, parent_id: Number(e.target.value) })} className="input input--xs">
                                {headings.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
                            </select>
                            <div className="form-hint form-hint--attached">
                                {account ? 'Another heading of the same type.' : 'The heading decides the type: an asset, an expense…'}
                            </div>
                        </div>
                    )}
                </div>
                <div className="drawer__section">
                    <div className="section-label">Notes</div>
                    <AutoResizeTextarea {...field('description')} placeholder="What goes here, and anything the CPA should know…" className="input" />
                </div>
                {account && !isHeading && (
                    <div className="drawer__section">
                        <Toggle checked={form.is_active} disabled={Boolean(account.system_key)} onChange={(is_active) => setForm({ ...form, is_active })} label="Active" />
                        <div className="form-hint form-hint--attached">
                            {account.system_key
                                ? 'The app posts to this account on its own, so it stays active.'
                                : 'An inactive account keeps its history but can’t be posted to.'}
                        </div>
                    </div>
                )}

                {error && <div className="form-message form-message--error drawer__section">{error}</div>}

                <div className="form-actions">
                    <Button type="submit" variant="confirm" disabled={saving}>{account ? 'Save changes' : 'Add account'}</Button>
                </div>
            </form>
        </Drawer>
    );
}

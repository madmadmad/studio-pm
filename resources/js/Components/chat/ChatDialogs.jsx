import { useEffect, useState } from 'react';
import { Check, Hash, X } from '@phosphor-icons/react';
import Avatar from '../Avatar';
import Button from '../Button';
import PresenceDot from './PresenceDot';
import { api } from '../../lib/api';

function Dialog({ title, onClose, children, footer }) {
    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="modal">
            <div className="modal__backdrop" onClick={onClose} />
            <div role="dialog" aria-modal="true" aria-label={title} className="modal__panel">
                <div className="modal__header">
                    <h2 className="modal__title">{title}</h2>
                    <button type="button" onClick={onClose} className="icon-btn icon-btn--secondary" aria-label="Close"><X /></button>
                </div>
                <div className="modal__body">{children}</div>
                {footer && <div className="modal__footer">{footer}</div>}
            </div>
        </div>
    );
}

// Every channel in the studio, to open or join -- and a new one.
export function BrowseChannelsDialog({ onClose, onOpened }) {
    const [channels, setChannels] = useState(null);
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        api.get('/api/chat/channels').then(setChannels).catch((e) => setError(e.message));
    }, []);

    async function open(channel) {
        setBusy(true);
        try {
            const summary = channel.is_member ? null : await api.post(`/api/chat/channels/${channel.id}/join`);
            onOpened(channel.id, summary);
        } catch (e) {
            setError(e.message);
            setBusy(false);
        }
    }

    async function create(e) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const summary = await api.post('/api/chat/channels', { name, description: description || null });
            onOpened(summary.id, summary);
        } catch (err) {
            setError(err.errors?.name?.[0] ?? err.message);
            setBusy(false);
        }
    }

    if (creating) {
        return (
            <Dialog title="New channel" onClose={onClose}>
                <form onSubmit={create} className="form-stack">
                    <div>
                        <label className="label" htmlFor="channel-name">Name</label>
                        <input id="channel-name" className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. design-crit" maxLength={80} autoFocus required />
                    </div>
                    <div>
                        <label className="label" htmlFor="channel-description">What it's for <span className="chat-dialog__optional">(optional)</span></label>
                        <input id="channel-description" className="input" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} />
                    </div>
                    {error && <p className="form-error" role="alert">{error}</p>}
                    <div className="form-actions">
                        <Button type="button" variant="secondary" onClick={() => setCreating(false)}>Back</Button>
                        <Button type="submit" disabled={busy || !name.trim()}>Create channel</Button>
                    </div>
                </form>
            </Dialog>
        );
    }

    return (
        <Dialog
            title="Channels"
            onClose={onClose}
            footer={<Button variant="secondary" onClick={() => setCreating(true)}>New channel</Button>}
        >
            {error && <p className="form-error" role="alert">{error}</p>}
            {channels === null ? (
                <p className="chat-dialog__status">Loading…</p>
            ) : channels.length === 0 ? (
                <p className="chat-dialog__status">No channels yet. Start the first one.</p>
            ) : (
                <ul className="chat-dialog__list">
                    {channels.map((channel) => (
                        <li key={channel.id}>
                            <button type="button" className="list-row chat-dialog__row" onClick={() => open(channel)} disabled={busy}>
                                <span>
                                    <span className="list-row__title"><Hash size={14} /> {channel.name}</span>
                                    {channel.description && <span className="list-row__meta chat-dialog__meta">{channel.description}</span>}
                                </span>
                                <span className="list-row__aside list-row__meta">
                                    {channel.member_count} {channel.member_count === 1 ? 'member' : 'members'}
                                    <span className="chat-dialog__action">{channel.is_member ? 'Open' : 'Join'}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Dialog>
    );
}

// Pick one or more teammates; opens the conversation you already have
// with exactly them, or starts it.
export function NewMessageDialog({ staff, me, online, onClose, onOpened }) {
    const [picked, setPicked] = useState([]);
    const [filter, setFilter] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const people = staff
        .filter((s) => s.id !== me.id && s.name.toLowerCase().includes(filter.trim().toLowerCase()))
        .sort((a, b) => (online.has(b.id) - online.has(a.id)) || a.name.localeCompare(b.name));

    function toggle(id) {
        setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    }

    async function start() {
        setBusy(true);
        try {
            const summary = await api.post('/api/chat/direct', { user_ids: picked });
            onOpened(summary.id, summary);
        } catch (e) {
            setError(e.message);
            setBusy(false);
        }
    }

    return (
        <Dialog
            title="New message"
            onClose={onClose}
            footer={(
                <>
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button onClick={start} disabled={busy || picked.length === 0}>{picked.length > 1 ? 'Message group' : 'Message'}</Button>
                </>
            )}
        >
            <input className="input chat-dialog__filter" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Find a teammate" aria-label="Find a teammate" autoFocus />
            {error && <p className="form-error" role="alert">{error}</p>}
            <ul className="chat-dialog__list">
                {people.map((person) => {
                    const on = picked.includes(person.id);
                    return (
                        <li key={person.id}>
                            <button type="button" className={`list-row chat-dialog__row${on ? ' chat-dialog__row--picked' : ''}`} aria-pressed={on} onClick={() => toggle(person.id)}>
                                <span className="chat-dialog__person">
                                    <PresenceDot online={online.has(person.id)}><Avatar name={person.name} avatarUrl={person.avatar_url} id={person.id} size={28} /></PresenceDot>
                                    <span>
                                        <span className="list-row__title">{person.name}</span>
                                        {person.job_title && <span className="list-row__meta chat-dialog__meta">{person.job_title}</span>}
                                    </span>
                                </span>
                                {on && <Check className="chat-dialog__check" />}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </Dialog>
    );
}

// A channel's name and description, for anyone in it. #general keeps its
// name (the server holds it), so the field's locked there.
export function EditChannelDialog({ channel, onClose, onSaved }) {
    const [name, setName] = useState(channel.name);
    const [description, setDescription] = useState(channel.description ?? '');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const isGeneral = channel.slug === 'general';

    async function save(e) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            onSaved(await api.patch(`/api/chat/channels/${channel.id}`, { name, description: description || null }));
        } catch (err) {
            setError(err.errors?.name?.[0] ?? err.errors?.description?.[0] ?? err.message);
            setBusy(false);
        }
    }

    return (
        <Dialog title="Edit channel" onClose={onClose}>
            <form onSubmit={save} className="form-stack">
                <div>
                    <label className="label" htmlFor="edit-channel-name">Name</label>
                    <input id="edit-channel-name" className="input" value={name} onChange={(e) => setName(e.target.value)} maxLength={80} disabled={isGeneral} autoFocus={!isGeneral} required />
                    {isGeneral && <p className="form-hint">#general is where everyone starts, so its name stays.</p>}
                </div>
                <div>
                    <label className="label" htmlFor="edit-channel-description">What it's for <span className="chat-dialog__optional">(optional)</span></label>
                    <input id="edit-channel-description" className="input" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} autoFocus={isGeneral} />
                </div>
                {error && <p className="form-error" role="alert">{error}</p>}
                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" disabled={busy || !name.trim()}>Save</Button>
                </div>
            </form>
        </Dialog>
    );
}

import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ArrowCounterClockwise, EnvelopeSimple, Trash, UserMinus } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Drawer from '../../Components/Drawer';
import Toggle from '../../Components/Toggle';
import RowActions from '../../Components/RowActions';
import Avatar from '../../Components/Avatar';
import BioForm from '../../Components/BioForm';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import TabToolbar from '../../Components/TabToolbar';
import { shrinkImage } from '../../lib/shrinkImage';

function emptyForm() {
    return { name: '', email: '', role: 'team_member' };
}

function StatusBadge({ user }) {
    if (user.deactivated_at) {
        return <Badge tone="danger" label="Deactivated" />;
    }
    if (user.has_pending_invite) {
        return <Badge tone="accent" label="Invite pending" />;
    }
    return <Badge tone="success" label="Active" />;
}

// A team member's access in a word or two: their assigned projects, plus
// how many permissions they've been given.
function accessSummary(user, options) {
    const granted = (user.permissions || []).filter((p) => options[p]);
    if (granted.length === 0) return 'Assigned projects';
    if (granted.length === 1) return options[granted[0]].label;
    return `${granted.length} permissions`;
}

// What a team member can do beyond their assigned projects -- a switch
// per permission (config/permissions.php), saved as it's flipped. Settings
// and the team stay with super admins.
function AccessDrawer({ user, options, onSaved, onClose }) {
    const [granted, setGranted] = useState(user.permissions || []);
    const [error, setError] = useState('');

    async function toggle(permission, on) {
        const next = on ? [...granted, permission] : granted.filter((p) => p !== permission);
        setGranted(next);
        setError('');
        try {
            onSaved(await api.patch(`/api/users/${user.id}`, { permissions: next }));
        } catch (err) {
            setGranted(granted);
            setError(err.message || 'Could not change their access.');
        }
    }

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">{user.name}&rsquo;s access</h2>
            <p className="form-hint drawer__section">
                Every team member works on the projects they&rsquo;re assigned to &mdash; tasks, files, notes, schedule, messages, their own time &mdash; and sees those projects&rsquo; approved proposals. Switch on anything more they need. Settings and the team stay with super admins.
            </p>
            <div className="users__permissions">
                {Object.entries(options).map(([key, option]) => (
                    <div key={key}>
                        <Toggle checked={granted.includes(key)} onChange={(on) => toggle(key, on)} label={option.label} />
                        <div className="form-hint form-hint--attached">{option.description}</div>
                    </div>
                ))}
            </div>
            {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
        </Drawer>
    );
}

// Someone's avatar (used around the app) and their bio -- bio photo,
// position and bio, for proposals' team sections -- edited from the Team
// page. Photo changes save on pick.
function ProfileDrawer({ user, onSaved, onClose }) {
    const [current, setCurrent] = useState(user);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const fileRef = useRef(null);

    async function photo(task) {
        setBusy(true);
        setError('');
        try {
            const updated = await task();
            setCurrent(updated);
            onSaved(updated);
        } catch (err) {
            setError(err.message || 'Could not update the photo.');
        } finally {
            setBusy(false);
        }
    }

    function upload(e) {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;
        photo(async () => {
            const fd = new FormData();
            fd.append('avatar', await shrinkImage(file));
            return api.postForm(`/api/users/${user.id}/avatar`, fd);
        });
    }

    return (
        <Drawer size="wide" onClose={onClose}>
            <h2 className="drawer__title">{current.name}</h2>
            <div className="form-panel">
                <div className="section-label section-label--ruled">Avatar</div>
                <p className="form-hint">Shown next to their messages and around the app.</p>
                <div className="users__photo">
                    <Avatar name={current.name} avatarUrl={current.avatar_url} id={current.id} size={72} />
                    <Button variant="secondary" onClick={() => fileRef.current.click()} disabled={busy}>
                        {current.avatar_url ? 'Change avatar' : 'Upload avatar'}
                    </Button>
                    {current.avatar_url && (
                        <Button variant="danger" onClick={() => photo(() => api.delete(`/api/users/${user.id}/avatar`))} disabled={busy}>Remove</Button>
                    )}
                    <input ref={fileRef} type="file" accept="image/*" hidden onChange={upload} />
                </div>
                {error && <div className="form-message form-message--error form-message--spaced">{error}</div>}
            </div>
            <BioForm user={current} endpoint={`/api/users/${user.id}`} photoEndpoint={`/api/users/${user.id}/bio-photo`} onSaved={(updated) => { setCurrent(updated); onSaved(updated); }} />
        </Drawer>
    );
}

export default function UsersIndex({ users: usersProp, permissionOptions = {} }) {
    const { props } = usePage();
    const currentUserId = props.auth?.user?.id;
    const [users, setUsers] = useState(usersProp);
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [busyId, setBusyId] = useState(null);
    // The team member whose access is open in the drawer.
    const [accessUser, setAccessUser] = useState(null);
    // Whose photo and bio are open.
    const [profileUser, setProfileUser] = useState(null);

    useEffect(() => {
        setUsers(usersProp);
    }, [usersProp]);

    // The row opens their photo and bio, like every other list; its own
    // controls (role, access, the icon buttons) don't -- but the open caret
    // (.row-action) is there to, so its click goes through.
    function openRow(e, user) {
        if (e.target.closest('button:not(.row-action), select, input, a')) return;
        setProfileUser(user);
    }

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        try {
            const created = await api.post('/api/users', form);
            setUsers((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name)));
            setForm(emptyForm());
            setShowForm(false);
        } catch (err) {
            alert(err.message || 'Could not send this invite.');
        } finally {
            setSaving(false);
        }
    }

    async function changeRole(user, role) {
        if (role === user.role) return;
        setBusyId(user.id);
        try {
            const updated = await api.patch(`/api/users/${user.id}`, { role });
            setUsers((current) => current.map((u) => (u.id === user.id ? updated : u)));
        } catch (err) {
            alert(err.message || "Could not change this user's role.");
        } finally {
            setBusyId(null);
        }
    }

    async function deactivate(user) {
        if (!confirm(`Deactivate ${user.name}? They'll be signed out immediately and won't be able to log back in.`)) return;
        setBusyId(user.id);
        try {
            await api.delete(`/api/users/${user.id}`);
            setUsers((current) => current.map((u) => (u.id === user.id ? { ...u, deactivated_at: new Date().toISOString() } : u)));
        } catch (err) {
            alert(err.message || 'Could not deactivate this user.');
        } finally {
            setBusyId(null);
        }
    }

    async function reactivate(user) {
        setBusyId(user.id);
        try {
            const updated = await api.post(`/api/users/${user.id}/reactivate`);
            setUsers((current) => current.map((u) => (u.id === user.id ? updated : u)));
        } catch (err) {
            alert(err.message || 'Could not reactivate this user.');
        } finally {
            setBusyId(null);
        }
    }

    async function deleteUser(user) {
        if (!confirm(`Permanently delete ${user.name}? This can't be undone.`)) return;
        setBusyId(user.id);
        try {
            await api.delete(`/api/users/${user.id}/permanent`);
            setUsers((current) => current.filter((u) => u.id !== user.id));
        } catch (err) {
            alert(err.message || 'Could not delete this user.');
        } finally {
            setBusyId(null);
        }
    }

    async function resendInvite(user) {
        setBusyId(user.id);
        try {
            const updated = await api.post(`/api/users/${user.id}/resend-invite`);
            setUsers((current) => current.map((u) => (u.id === user.id ? updated : u)));
        } catch (err) {
            alert(err.message || 'Could not resend this invite.');
        } finally {
            setBusyId(null);
        }
    }

    return (
        <AppLayout>
            <Head title="Team" />
            <PageHeader title="Team" />

            {showForm && (
                <form onSubmit={submit} className="card card--padded form-grid page-section">
                    <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input" />
                    <input required type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="input" />
                    <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="input form-grid__full">
                        <option value="team_member">Team member</option>
                        <option value="super_admin">Super admin</option>
                    </select>
                    <p className="form-hint form-grid__full">They'll get an email with a link to set their own password. A team member starts with just their assigned projects; give them more from Access once they're added.</p>
                    <div className="form-actions form-grid__full">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Send invite</Button>
                    </div>
                </form>
            )}

            {/* The add action, directly above the list it adds to. */}
            <TabToolbar addLabel="Invite staff" onAdd={() => setShowForm(true)} />
            <div className="card card--flush">
                {users.length === 0 ? (
                    <EmptyState text="No staff yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Access</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id} onClick={(e) => openRow(e, user)} className="table__row--link">
                                    <td className="table__cell--strong">
                                        {user.name}
                                        {user.job_title && <div className="table__meta">{user.job_title}</div>}
                                    </td>
                                    <td className="table__cell--muted">{user.email}</td>
                                    <td>
                                        <select
                                            value={user.role}
                                            // Nobody changes their own role.
                                            disabled={busyId === user.id || !!user.deactivated_at || user.id === currentUserId}
                                            onChange={(e) => changeRole(user, e.target.value)}
                                            className="input input--xs input--inline"
                                        >
                                            <option value="team_member">Team member</option>
                                            <option value="super_admin">Super admin</option>
                                        </select>
                                    </td>
                                    <td>
                                        {user.role === 'super_admin' ? (
                                            <span className="users__access">Everything</span>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => setAccessUser(user)}
                                                disabled={!!user.deactivated_at}
                                                className="users__access users__access--link"
                                            >
                                                {accessSummary(user, permissionOptions)}
                                            </button>
                                        )}
                                    </td>
                                    <td>
                                        <StatusBadge user={user} />
                                    </td>
                                    <td className="table__cell--end">
                                        <div className="table__actions">
                                            {user.has_pending_invite && !user.deactivated_at && (
                                                <button onClick={() => resendInvite(user)} disabled={busyId === user.id} title="Resend invite" className="icon-btn icon-btn--secondary">
                                                    <EnvelopeSimple />
                                                </button>
                                            )}
                                            {user.deactivated_at ? (
                                                <button onClick={() => reactivate(user)} disabled={busyId === user.id} title="Reactivate" className="icon-btn icon-btn--restore">
                                                    <ArrowCounterClockwise size={16} />
                                                </button>
                                            ) : (
                                                <button onClick={() => deactivate(user)} disabled={busyId === user.id} title="Deactivate" className="icon-btn icon-btn--danger">
                                                    <UserMinus />
                                                </button>
                                            )}
                                            {user.id !== currentUserId && (
                                                <button onClick={() => deleteUser(user)} disabled={busyId === user.id} title="Delete permanently" className="icon-btn icon-btn--danger">
                                                    <Trash />
                                                </button>
                                            )}
                                            <RowActions openLabel={`Open ${user.name}`} />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {profileUser && (
                <ProfileDrawer
                    user={profileUser}
                    onSaved={(updated) => setUsers((current) => current.map((u) => (u.id === updated.id ? updated : u)))}
                    onClose={() => setProfileUser(null)}
                />
            )}

            {accessUser && (
                <AccessDrawer
                    user={accessUser}
                    options={permissionOptions}
                    onSaved={(updated) => setUsers((current) => current.map((u) => (u.id === updated.id ? updated : u)))}
                    onClose={() => setAccessUser(null)}
                />
            )}
        </AppLayout>
    );
}

import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowCounterClockwise, EnvelopeSimple, Trash, UserMinus } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Badge from '../../Components/Badge';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import { formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';

function emptyForm() {
    return { name: '', email: '', role: 'team_member' };
}

function StatusBadge({ user }) {
    if (user.deactivated_at) {
        return <Badge tone="watermelon" label="Deactivated" />;
    }
    if (user.has_pending_invite) {
        return <Badge tone="watermelon" label="Invite pending" />;
    }
    return <Badge tone="fern" label="Active" />;
}

export default function UsersIndex({ users: usersProp }) {
    const { props } = usePage();
    const currentUserId = props.auth?.user?.id;
    const [users, setUsers] = useState(usersProp);
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState(emptyForm());
    const [saving, setSaving] = useState(false);
    const [busyId, setBusyId] = useState(null);

    useEffect(() => {
        setUsers(usersProp);
    }, [usersProp]);

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
            <PageHeader
                title="Team"
                actions={<Button onClick={() => setShowForm(true)}>Invite staff</Button>}
                subtitle="Managers see and edit everything. Team Members only see projects they're assigned to."
            />

            {showForm && (
                <form onSubmit={submit} className="card card--padded mb-6 grid grid-cols-2 gap-3">
                    <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="input" />
                    <input required type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="input" />
                    <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="input col-span-2">
                        <option value="team_member">Team Member</option>
                        <option value="manager">Manager</option>
                    </select>
                    <p className="text-xs text-shadow-grey col-span-2">They'll get an email with a link to set their own password.</p>
                    <div className="flex gap-2 col-span-2 justify-end">
                        <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                        <Button type="submit" variant="confirm" disabled={saving}>Send invite</Button>
                    </div>
                </form>
            )}

            <div className="card overflow-hidden">
                {users.length === 0 ? (
                    <EmptyState text="No staff yet." />
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id}>
                                    <td className="font-medium">{user.name}</td>
                                    <td className="text-shadow-grey">{user.email}</td>
                                    <td>
                                        <select
                                            value={user.role}
                                            disabled={busyId === user.id || !!user.deactivated_at}
                                            onChange={(e) => changeRole(user, e.target.value)}
                                            className="input input--xs w-auto disabled:opacity-50"
                                        >
                                            <option value="team_member">Team Member</option>
                                            <option value="manager">Manager</option>
                                        </select>
                                    </td>
                                    <td>
                                        <StatusBadge user={user} />
                                        {user.invited_at && !user.deactivated_at && (
                                            <div className="text-xs text-shadow-grey mt-1">Invited {formatDate(user.invited_at)}</div>
                                        )}
                                    </td>
                                    <td className="text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            {user.has_pending_invite && !user.deactivated_at && (
                                                <button onClick={() => resendInvite(user)} disabled={busyId === user.id} title="Resend invite" className="icon-btn icon-btn--secondary">
                                                    <EnvelopeSimple />
                                                </button>
                                            )}
                                            {user.deactivated_at ? (
                                                <button onClick={() => reactivate(user)} disabled={busyId === user.id} title="Reactivate" className="text-shadow-grey hover:text-fern disabled:opacity-50">
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
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppLayout>
    );
}

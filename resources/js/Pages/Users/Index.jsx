import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowCounterClockwise, EnvelopeSimple, UserMinus } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import EmptyState from '../../Components/EmptyState';
import { formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function emptyForm() {
    return { name: '', email: '', role: 'team_member' };
}

function StatusBadge({ user }) {
    if (user.deactivated_at) {
        return <span className="text-xs px-2 py-0.5 rounded-full bg-brick/10 text-brick font-medium">Deactivated</span>;
    }
    if (user.has_pending_invite) {
        return <span className="text-xs px-2 py-0.5 rounded-full bg-brass/10 text-brass font-medium">Invite pending</span>;
    }
    return <span className="text-xs px-2 py-0.5 rounded-full bg-pine/10 text-pine font-medium">Active</span>;
}

export default function UsersIndex({ users: usersProp }) {
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
            <div className="flex items-center justify-between mb-1">
                <h1 className="font-display text-2xl font-semibold">Team</h1>
                <button onClick={() => setShowForm(true)} className="bg-ink text-white text-sm font-medium px-3 py-1.5 rounded">
                    Invite staff
                </button>
            </div>
            <p className="text-sm text-sage mb-6">Managers see and edit everything. Team Members only see projects they're assigned to.</p>

            {showForm && (
                <form onSubmit={submit} className="bg-white rounded-lg border border-border p-4 mb-6 grid grid-cols-2 gap-3">
                    <input required placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <input required type="email" placeholder="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="border border-border rounded px-3 py-2 text-sm" />
                    <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="border border-border rounded px-3 py-2 text-sm col-span-2">
                        <option value="team_member">Team Member</option>
                        <option value="manager">Manager</option>
                    </select>
                    <p className="text-xs text-sage col-span-2">They'll get an email with a link to set their own password.</p>
                    <div className="flex gap-2 col-span-2 justify-end">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 rounded text-sage">Cancel</button>
                        <button type="submit" disabled={saving} className="bg-pine text-white text-sm font-medium px-3 py-1.5 rounded disabled:opacity-50">Send invite</button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-lg border border-border overflow-hidden">
                {users.length === 0 ? (
                    <EmptyState text="No staff yet." />
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-border text-sage">
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Email</th>
                                <th className="px-4 py-2 font-medium">Role</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id} className="border-b border-border last:border-b-0">
                                    <td className="px-4 py-3 font-medium">{user.name}</td>
                                    <td className="px-4 py-3 text-sage">{user.email}</td>
                                    <td className="px-4 py-3">
                                        <select
                                            value={user.role}
                                            disabled={busyId === user.id || !!user.deactivated_at}
                                            onChange={(e) => changeRole(user, e.target.value)}
                                            className="border border-border rounded px-2 py-1 text-sm disabled:opacity-50"
                                        >
                                            <option value="team_member">Team Member</option>
                                            <option value="manager">Manager</option>
                                        </select>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge user={user} />
                                        {user.invited_at && !user.deactivated_at && (
                                            <div className="text-xs text-sage mt-1">Invited {formatDate(user.invited_at)}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-3">
                                            {user.has_pending_invite && !user.deactivated_at && (
                                                <button onClick={() => resendInvite(user)} disabled={busyId === user.id} title="Resend invite" className="text-sage hover:text-ink disabled:opacity-50">
                                                    <EnvelopeSimple size={16} />
                                                </button>
                                            )}
                                            {user.deactivated_at ? (
                                                <button onClick={() => reactivate(user)} disabled={busyId === user.id} title="Reactivate" className="text-sage hover:text-pine disabled:opacity-50">
                                                    <ArrowCounterClockwise size={16} />
                                                </button>
                                            ) : (
                                                <button onClick={() => deactivate(user)} disabled={busyId === user.id} title="Deactivate" className="text-sage hover:text-brick disabled:opacity-50">
                                                    <UserMinus size={16} />
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

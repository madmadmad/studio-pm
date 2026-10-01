import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Avatar from '../../Components/Avatar';
import Button from '../../Components/Button';
import { api } from '../../lib/api';
import PageHeader from '../../Components/PageHeader';
import TabBar from '../../Components/TabBar';
import Timesheet from '../../Components/Timesheet';
import ProjectBoard from '../../Components/ProjectBoard';
import EmptyState from '../../Components/EmptyState';
import { useFavorites } from '../../Components/StarButton';
import { useRememberedTab } from '../../lib/useRememberedTab';

const TABS = ['Timesheet', 'Projects', 'Account'];

// Their starred projects as a board, as on the Projects page: drag a card
// to change its status, or unstar it to take it off here.
function StarredProjects({ projects }) {
    const favorites = useFavorites(projects);
    const starred = projects.filter((p) => favorites.isStarred(p));

    if (starred.length === 0) {
        return (
            <div className="card card--flush">
                <EmptyState text="No starred projects yet." />
                <p className="form-hint profile-page__hint">
                    Star the ones you&rsquo;re working on from the <Link href="/projects" className="link link--inline">Projects</Link> page.
                </p>
            </div>
        );
    }

    return <ProjectBoard projects={starred} favorites={favorites} onChange={() => router.reload({ only: ['starredProjects'] })} />;
}

// One field with its label and any error under it.
function Field({ label, error, hint, children }) {
    return (
        <div>
            <label className="label">{label}</label>
            {children}
            {hint && !error && <p className="form-hint form-hint--attached">{hint}</p>}
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

// Name and email (Fortify's PUT /user/profile-information).
function DetailsPanel({ user, onSaved }) {
    const [form, setForm] = useState({ name: user.name, email: user.email });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        setSaved(false);
        try {
            await api.put('/user/profile-information', form);
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
            onSaved(form);
        } catch (err) {
            setErrors(flatErrors(err));
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="form-panel form-stack">
            <div className="section-label section-label--ruled">Details</div>
            <Field label="Name" error={errors.name}>
                <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="name" className="input" />
            </Field>
            <Field label="Email" error={errors.email} hint="You sign in with this, and it's where reset links go.">
                <input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} autoComplete="email" className="input" />
            </Field>
            <div className="form-actions">
                {saved && <span className="form-message form-message--success">Saved</span>}
                <Button type="submit" variant="confirm" disabled={saving}>Save</Button>
            </div>
        </form>
    );
}

// Changing your password (Fortify's PUT /user/password): the current one,
// then the new one twice.
function PasswordPanel({ hint }) {
    const empty = { current_password: '', password: '', password_confirmation: '' };
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        setSaved(false);
        try {
            await api.put('/user/password', form);
            setForm(empty);
            setSaved(true);
            setTimeout(() => setSaved(false), 2500);
        } catch (err) {
            setErrors(flatErrors(err));
        } finally {
            setSaving(false);
        }
    }

    const set = (field) => (e) => setForm({ ...form, [field]: e.target.value });

    return (
        <form onSubmit={submit} className="form-panel form-stack">
            <div className="section-label section-label--ruled">Password</div>
            <Field label="Current password" error={errors.current_password}>
                <input required type="password" value={form.current_password} onChange={set('current_password')} autoComplete="current-password" className="input" />
            </Field>
            <Field label="New password" error={errors.password} hint={hint}>
                <input required type="password" value={form.password} onChange={set('password')} autoComplete="new-password" className="input" />
            </Field>
            <Field label="Confirm new password" error={errors.password_confirmation}>
                <input required type="password" value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" className="input" />
            </Field>
            <div className="form-actions">
                {saved && <span className="form-message form-message--success">Password changed</span>}
                <Button type="submit" variant="confirm" disabled={saving}>Change password</Button>
            </div>
        </form>
    );
}

// A validation failure's errors as { field: first message }; anything
// else lands under `form`.
function flatErrors(err) {
    if (!err.errors) return { form: err.message || 'Something went wrong.' };
    return Object.fromEntries(Object.entries(err.errors).map(([field, messages]) => [field, [].concat(messages)[0]]));
}

// Your own page: your week's time (Timesheet), the projects you've
// starred (Projects), and your photo, details and password (Account).
export default function ProfileIndex({ profileUser, passwordHint, timesheet, timeProjects, timeCompanies, timeServices, starredProjects }) {
    const [tab, setTab] = useRememberedTab('profile-page-tab', TABS);
    const [user, setUser] = useState(profileUser);
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
            const updated = await api.postForm('/api/profile/avatar', form);
            setUser(updated);
            router.reload({ only: ['auth'] }); // the sidebar's avatar is a separate shared prop
        } catch (err) {
            setError(err.message || 'Could not upload that photo.');
        } finally {
            setSaving(false);
        }
    }

    async function removeAvatar() {
        setSaving(true);
        try {
            const updated = await api.delete('/api/profile/avatar');
            setUser(updated);
            router.reload({ only: ['auth'] });
        } finally {
            setSaving(false);
        }
    }

    return (
        <AppLayout>
            <Head title="My profile" />
            {/* Who this is: their photo and name, in place of a page title. */}
            <PageHeader
                title={(
                    <span className="page-header__person">
                        <Avatar name={user.name} avatarUrl={user.avatar_url} id={user.id} size={48} />
                        {user.name}
                    </span>
                )}
            />
            <TabBar tabs={TABS} tab={tab} setTab={setTab} size="lg" />

            {tab === 'Timesheet' && (
                <Timesheet
                    timesheet={timesheet}
                    projects={timeProjects}
                    companies={timeCompanies}
                    services={timeServices}
                    currentUser={user}
                />
            )}

            {tab === 'Projects' && <StarredProjects projects={starredProjects} />}

            {tab === 'Account' && (
                <div className="profile-page">
                    <div className="form-panel profile-card">
                        <div className="section-label section-label--ruled">Photo</div>
                        <p className="form-hint">Your photo shows up next to your messages and throughout the app.</p>
                        <div className="profile-card__identity">
                            <Avatar name={user.name} avatarUrl={user.avatar_url} id={user.id} size={72} />
                            <div>
                                <div className="profile-card__name">{user.name}</div>
                                <div className="profile-card__email">{user.email}</div>
                            </div>
                        </div>

                        {error && <div className="form-message form-message--error profile-card__error">{error}</div>}

                        <div className="profile-card__actions">
                            <Button variant="secondary" onClick={() => inputRef.current.click()} disabled={saving}>
                                {user.avatar_url ? 'Change photo' : 'Upload photo'}
                            </Button>
                            {user.avatar_url && (
                                <Button variant="danger" onClick={removeAvatar} disabled={saving}>Remove photo</Button>
                            )}
                        </div>
                        <input ref={inputRef} type="file" accept="image/*" hidden onChange={uploadAvatar} />
                    </div>

                    <DetailsPanel
                        user={user}
                        onSaved={(details) => {
                            setUser((current) => ({ ...current, ...details }));
                            router.reload({ only: ['auth'] }); // the sidebar shows the name and email too
                        }}
                    />
                    <PasswordPanel hint={passwordHint} />
                </div>
            )}
        </AppLayout>
    );
}

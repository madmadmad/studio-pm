import { useEffect, useState } from 'react';
import Button from './Button';
import Toggle from './Toggle';
import SettingsSection from './SettingsSection';
import { api } from '../lib/api';

// What came back from Google's sign-in (GoogleCalendarController::callback),
// said once above the section.
const RETURN_MESSAGES = {
    connected: { tone: 'success', text: 'Connected. Choose which calendars to show below.' },
    cancelled: { tone: 'error', text: 'Google Calendar wasn’t connected: the sign-in was cancelled.' },
    failed: { tone: 'error', text: 'Google Calendar couldn’t be connected. Try again, and use your madmadmad.com account.' },
};

// Profile → Account: connecting your own Google Calendar, and choosing which
// of your Google calendars show in the app. Hidden until the app has its
// Google sign-in client (config/services.php).
export default function GoogleCalendarSettings({ returned }) {
    const [state, setState] = useState(null); // GET /api/google-calendar
    const [chosen, setChosen] = useState([]);
    const [defaultId, setDefaultId] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const notice = RETURN_MESSAGES[returned];

    useEffect(() => {
        api.get('/api/google-calendar')
            .then((data) => { setState(data); setChosen(data.chosen ?? []); setDefaultId(data.default ?? null); })
            .catch(() => setState({ configured: false }));
    }, []);

    if (!state?.configured) return null;

    async function toggle(id, on) {
        const next = on ? [...chosen, id] : chosen.filter((c) => c !== id);
        const before = chosen;
        setChosen(next);
        setError('');
        try {
            const saved = await api.put('/api/google-calendar/calendars', { ids: next });
            setDefaultId(saved.default ?? null);
        } catch (err) {
            setChosen(before);
            setError(err.message || 'Couldn’t save that.');
        }
    }

    async function changeDefault(id) {
        const before = defaultId;
        setDefaultId(id);
        setError('');
        try {
            await api.put('/api/google-calendar/default', { id });
        } catch (err) {
            setDefaultId(before);
            setError(err.message || 'Couldn’t save that.');
        }
    }

    async function disconnect() {
        setBusy(true);
        setError('');
        try {
            await api.delete('/api/google-calendar');
            setState({ configured: true, connected: false });
            setChosen([]);
        } catch (err) {
            setError(err.message || 'Couldn’t disconnect.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <SettingsSection
            title="Google Calendar"
            description="Your calendars in the app, alongside schedules and tasks. You add and edit events here as yourself, using your own Google access."
        >
            <div className="form-panel form-stack">
                {notice && <div className={`form-message form-message--${notice.tone}`}>{notice.text}</div>}
                {state.lost && <div className="form-message form-message--error">Google Calendar access was removed in your Google account. Connect it again to keep seeing your calendars.</div>}

                {!state.connected ? (
                    <div className="google-calendar__connect">
                        <p className="form-hint">Sign in with your madmadmad.com Google account.</p>
                        {/* A full page load: it leaves for Google's sign-in. */}
                        <a href="/google/calendar/connect" className="btn btn--confirm">Connect Google Calendar</a>
                    </div>
                ) : (
                    <>
                        <div className="google-calendar__account">
                            <div>
                                <div className="label">Connected as</div>
                                <div className="google-calendar__email">{state.email}</div>
                            </div>
                            <Button variant="secondary" onClick={disconnect} disabled={busy}>Disconnect</Button>
                        </div>

                        <div>
                            <div className="label">Calendars to show</div>
                            {state.available === null ? (
                                <p className="form-hint">Your Google calendars couldn’t be loaded just now. Reload to try again.</p>
                            ) : state.available.length === 0 ? (
                                <p className="form-hint">No calendars on your Google account.</p>
                            ) : (
                                <ul className="google-calendar__list">
                                    {state.available.map((cal) => (
                                        <li key={cal.id}>
                                            <Toggle
                                                checked={chosen.includes(cal.id)}
                                                onChange={(on) => toggle(cal.id, on)}
                                                label={(
                                                    <span className="google-calendar__name">
                                                        <span className="google-calendar__swatch" style={{ backgroundColor: cal.color || undefined }} aria-hidden="true" />
                                                        {cal.name}
                                                        {!cal.can_edit && <span className="google-calendar__note">view only</span>}
                                                    </span>
                                                )}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        {state.available && (() => {
                            const writable = state.available.filter((c) => c.can_edit && chosen.includes(c.id));
                            return writable.length > 0 && (
                                <div>
                                    <label className="label" htmlFor="google-calendar-default">New events go on</label>
                                    <select id="google-calendar-default" value={defaultId ?? ''} onChange={(e) => changeDefault(e.target.value)} className="input">
                                        {writable.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                    </select>
                                    <p className="form-hint form-hint--attached">Where the Agenda&rsquo;s New event starts. You can pick another calendar on each event.</p>
                                </div>
                            );
                        })()}
                    </>
                )}
                {error && <div className="form-error">{error}</div>}
            </div>
        </SettingsSection>
    );
}

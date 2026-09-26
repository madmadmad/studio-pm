import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import EmptyState from '../../Components/EmptyState';
import Badge from '../../Components/Badge';
import { formatCurrency, formatDate } from '../../lib/format';
import { getTray, addToTray, setTray } from '../../lib/tray';
import PageHeader from '../../Components/PageHeader';
import TimeEntryDrawer, { NewTimeEntryDrawer } from '../../Components/TimeEntryDrawer';
import RowActions from '../../Components/RowActions';

export default function TimeIndex({ timeEntries, companies, projects }) {
    const currentUser = usePage().props.auth?.user;
    const [logging, setLogging] = useState(false);
    const [openId, setOpenId] = useState(null);
    const [tray, setTrayState] = useState(getTray());

    const unbilledHours = timeEntries.filter((e) => !e.billed).reduce((s, e) => s + parseFloat(e.hours), 0);
    const traySubtotal = tray.reduce((s, t) => s + t.amount, 0);
    const queuedIds = new Set(tray.map((t) => t.time_entry_id));
    const openEntry = timeEntries.find((e) => e.id === openId) || null;
    const openEntryTasks = projects.find((p) => p.id === openEntry?.project_id)?.tasks || [];

    function reload() {
        router.reload({ only: ['timeEntries'] });
    }

    // The row opens the entry's drawer, except clicks on its own controls
    // (the client link, Bill this, the Queued badge). The open caret is a
    // button too, but exists to open the row, so it's let through.
    function openRow(e, entry) {
        if (e.target.closest('a, button:not(.row-action)')) return;
        setOpenId(entry.id);
    }

    function billEntry(entry) {
        // No client-level rate to calculate from -- rates live on Services
        // now. Starts at $0 and gets filled in on the invoice form.
        const company = companies.find((c) => c.id === entry.company_id);
        const next = addToTray({
            time_entry_id: entry.id,
            company_id: entry.company_id,
            description: `${entry.company?.name ?? company?.name ?? 'Client'} — ${entry.note || 'Time'}`,
            amount: 0,
        });
        setTrayState(next);
    }

    function removeFromTray(timeEntryId) {
        const next = tray.filter((t) => t.time_entry_id !== timeEntryId);
        setTray(next);
        setTrayState(next);
    }

    function createInvoiceFromTray() {
        router.visit('/invoices?from_tray=1');
    }

    return (
        <AppLayout>
            <Head title="Time" />
            <PageHeader
                title="Time"
                actions={<Button onClick={() => setLogging(true)}>Log time</Button>}
                subtitle={
                    <>
                        {unbilledHours}h unbilled across {companies.length} clients.
                    </>
                }
            />

            <div className="card card--flush page-section">
                {timeEntries.length === 0 ? (
                    <EmptyState text="No time logged yet. Track hours against a client to start building an invoice." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="time-list__date">Date</div>
                            <div className="time-list__client">Client / Project</div>
                            <div className="time-list__hours time-list__hours--narrow">Hours</div>
                            <div className="time-list__note">Task / Note</div>
                            <div className="time-list__status">Billing</div>
                            <div />
                        </div>
                        {timeEntries.map((entry) => (
                            <div key={entry.id} onClick={(e) => openRow(e, entry)} className="grid-row grid-row--action grid-row--link">
                                <div className="time-list__date">{formatDate(entry.date)}</div>
                                <div className="time-list__client">
                                    <Link href={`/clients/${entry.company_id}`} className="link u-truncate">
                                        {entry.company?.name ?? '—'}
                                    </Link>
                                    <span className="time-list__project u-truncate">{entry.project?.name ?? 'No project'}</span>
                                </div>
                                <div className="time-list__hours time-list__hours--narrow">{entry.hours}h</div>
                                <div className="time-list__note">
                                    <span className="time-list__text">{entry.task?.title || entry.note || '—'}</span>
                                </div>
                                <div className="time-list__status">
                                    {entry.billed ? (
                                        <Badge tone="success" label="Billed" />
                                    ) : queuedIds.has(entry.id) ? (
                                        <button onClick={() => removeFromTray(entry.id)} title="Remove from the invoice tray">
                                            <Badge tone="accent" label="Queued" />
                                        </button>
                                    ) : (
                                        <Button variant="link-accent" onClick={() => billEntry(entry)}>Bill this</Button>
                                    )}
                                </div>
                                <RowActions openLabel="Open entry" />
                            </div>
                        ))}
                    </>
                )}
            </div>

            {openEntry && (
                <TimeEntryDrawer
                    key={openEntry.id}
                    entry={openEntry}
                    tasks={openEntryTasks}
                    showContext
                    // Same rule as the API: a manager, or whoever logged it.
                    canEdit={currentUser?.role === 'manager' || openEntry.user_id === currentUser?.id}
                    onClose={() => setOpenId(null)}
                    onChange={reload}
                />
            )}

            {logging && (
                <NewTimeEntryDrawer
                    companies={companies}
                    projects={projects}
                    onCreated={reload}
                    onClose={() => setLogging(false)}
                />
            )}

            {tray.length > 0 && (
                <div className="billing-tray">
                    <div className="billing-tray__summary">
                        {tray.length} {tray.length === 1 ? 'entry' : 'entries'} ready to bill &mdash;{' '}
                        <span className="u-tabular-nums">{formatCurrency(traySubtotal)}</span>
                    </div>
                    <Button variant="accent" onClick={createInvoiceFromTray}>Create invoice</Button>
                </div>
            )}
        </AppLayout>
    );
}

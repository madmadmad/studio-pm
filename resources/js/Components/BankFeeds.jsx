import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { X } from '@phosphor-icons/react';
import Button from './Button';
import { api } from '../lib/api';
import { formatRelativeTime } from '../lib/format';

const LINK_SCRIPT = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';

// Plaid Link's script, loaded the first time a bank is connected.
function loadPlaidLink() {
    if (window.Plaid) return Promise.resolve(window.Plaid);
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = LINK_SCRIPT;
        script.onload = () => resolve(window.Plaid);
        script.onerror = () => reject(new Error('Could not load Plaid.'));
        document.head.appendChild(script);
    });
}

function summary({ added, updated, removed }) {
    const parts = [`${added} new expense${added === 1 ? '' : 's'}`];
    if (updated) parts.push(`${updated} updated`);
    if (removed) parts.push(`${removed} removed`);
    return parts.join(', ');
}

// Settings' bank feeds: each connected bank with when it last synced,
// Connect bank (Plaid Link) and Sync now. Synced charges land in the
// Expenses list as unbilled expenses.
export default function BankFeeds({ items: initialItems, configured }) {
    const [items, setItems] = useState(initialItems);
    const [busy, setBusy] = useState(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    async function run(kind, task) {
        setBusy(kind);
        setError('');
        setMessage('');
        try {
            await task();
        } catch (err) {
            setError(err.message || 'Something went wrong with the bank feed.');
        } finally {
            setBusy(null);
        }
    }

    function connect() {
        run('connect', async () => {
            const [{ link_token: token }, Plaid] = await Promise.all([api.post('/api/plaid/link-token'), loadPlaidLink()]);
            await new Promise((resolve, reject) => {
                const handler = Plaid.create({
                    token,
                    onSuccess: async (publicToken, metadata) => {
                        try {
                            const { item, result } = await api.post('/api/plaid/items', {
                                public_token: publicToken,
                                institution_name: metadata?.institution?.name ?? null,
                            });
                            setItems((current) => [...current.filter((i) => i.id !== item.id), item]);
                            setMessage(`Connected ${item.institution_name ?? 'the bank'}: ${summary(result)}.`);
                            resolve();
                        } catch (err) {
                            reject(err);
                        }
                    },
                    onExit: (err) => (err ? reject(new Error(err.display_message || err.error_message || 'Plaid closed with an error.')) : resolve()),
                });
                handler.open();
            });
        });
    }

    function syncNow() {
        run('sync', async () => {
            const { items: fresh, result } = await api.post('/api/plaid/sync');
            setItems(fresh);
            setMessage(`Synced: ${summary(result)}.`);
        });
    }

    function disconnect(item) {
        if (!confirm(`Disconnect ${item.institution_name ?? 'this bank'}? Its new charges stop syncing; expenses already imported stay.`)) return;
        run(`remove-${item.id}`, async () => {
            await api.delete(`/api/plaid/items/${item.id}`);
            setItems((current) => current.filter((i) => i.id !== item.id));
        });
    }

    return (
        <div className="card card--padded form-stack settings__card">
            <div className="section-label">Bank feeds</div>
            <p className="form-hint">
                Charges from connected banks sync every morning into <Link href="/expenses">Expenses</Link> as unbilled expenses, with the bank as their source. Deposits, refunds and card payments are left out. Deleting an imported expense dismisses it: it won&rsquo;t sync back.
            </p>
            {!configured ? (
                <p className="form-hint">Add PLAID_CLIENT_ID and PLAID_SECRET to .env to connect a bank.</p>
            ) : (
                <>
                    {items.length > 0 && (
                        <div className="settings__categories">
                            {items.map((item) => (
                                <div key={item.id} className="settings__category">
                                    <span>
                                        {item.institution_name ?? 'Bank'}{' '}
                                        <span className="settings__category-note">
                                            {item.last_error ? `sync failed (${item.last_error})` : `synced ${formatRelativeTime(item.last_synced_at)}`}
                                        </span>
                                    </span>
                                    <button type="button" onClick={() => disconnect(item)} disabled={busy !== null} title="Disconnect" aria-label={`Disconnect ${item.institution_name ?? 'bank'}`} className="icon-btn icon-btn--danger">
                                        <X />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="form-actions">
                        {message && <span className="form-message form-message--success">{message}</span>}
                        <Button variant="secondary" onClick={connect} disabled={busy !== null}>{busy === 'connect' ? 'Connecting…' : 'Connect bank'}</Button>
                        {items.length > 0 && (
                            <Button variant="confirm" onClick={syncNow} disabled={busy !== null}>{busy === 'sync' ? 'Syncing…' : 'Sync now'}</Button>
                        )}
                    </div>
                </>
            )}
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

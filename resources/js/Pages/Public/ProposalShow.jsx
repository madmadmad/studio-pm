import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function FeeSummary({ proposal }) {
    const total = proposal.items.reduce((s, item) => s + parseFloat(item.quantity) * parseFloat(item.rate), 0);

    return (
        <div className="bg-white rounded-lg border border-border mb-6 overflow-hidden">
            <div className="px-6 pt-6 pb-4">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-semibold">Fee Summary</h2>
                </div>
            </div>
            <div className="px-6 pb-2 flex items-center justify-between border-b border-border pb-4">
                <div className="font-semibold">Estimate</div>
                <div className="font-mono text-lg font-semibold">{formatCurrency(total)}</div>
            </div>

            <div className="px-6 py-3 grid grid-cols-12 text-xs font-medium text-sage border-b border-border">
                <div className="col-span-6">Items</div>
                <div className="col-span-2 text-right">Qty</div>
                <div className="col-span-2 text-right">Price</div>
                <div className="col-span-2 text-right">Total</div>
            </div>

            {proposal.items.map((item, idx) => (
                <div
                    key={item.id}
                    className={`px-6 py-4 grid grid-cols-12 text-sm ${idx < proposal.items.length - 1 ? 'border-b border-border' : ''}`}
                >
                    <div className="col-span-6 font-medium">{item.description}</div>
                    <div className="col-span-2 text-right font-mono">{parseFloat(item.quantity)}</div>
                    <div className="col-span-2 text-right font-mono">
                        {formatCurrency(item.rate)}{item.service?.unit === 'hourly' ? ' / hour' : ''}
                    </div>
                    <div className="col-span-2 text-right font-mono">
                        {formatCurrency(parseFloat(item.quantity) * parseFloat(item.rate))}
                    </div>
                </div>
            ))}

            <div className="px-6 py-4 flex items-center justify-between bg-paper">
                <div className="font-semibold">Total</div>
                <div className="font-mono text-lg font-semibold">{formatCurrency(total)}</div>
            </div>
        </div>
    );
}

export default function ProposalShow({ proposal, token }) {
    const [status, setStatus] = useState(proposal.status);
    const [accepting, setAccepting] = useState(false);

    async function accept() {
        setAccepting(true);
        try {
            await api.post(`/api/proposals/${token}/accept`);
            setStatus('accepted');
        } finally {
            setAccepting(false);
        }
    }

    return (
        <div className="min-h-screen bg-paper text-ink px-4 py-12">
            <Head title={proposal.title} />
            <div className="max-w-2xl mx-auto">
                <div className="text-xs text-sage mb-1">{proposal.company.name}</div>
                <h1 className="text-2xl font-semibold mb-1">{proposal.title}</h1>
                {proposal.items.length === 0 && proposal.estimate_amount && (
                    <div className="font-mono text-sage mb-6">Estimate: {formatCurrency(proposal.estimate_amount)}</div>
                )}

                <div
                    className="bg-white rounded-lg border border-border p-6 proposal-body mb-6"
                    dangerouslySetInnerHTML={{ __html: proposal.body }}
                />

                {proposal.items.length > 0 && <FeeSummary proposal={proposal} />}

                {status === 'accepted' ? (
                    <div className="rounded-lg p-4 bg-pine-soft text-pine text-sm font-medium">
                        Accepted{proposal.accepted_at ? ` on ${formatDate(proposal.accepted_at)}` : ''}. Thank you!
                    </div>
                ) : (
                    <button
                        onClick={accept}
                        disabled={accepting}
                        className="bg-ink text-white text-sm font-medium px-4 py-2 rounded disabled:opacity-50"
                    >
                        {accepting ? 'Accepting…' : 'Accept proposal'}
                    </button>
                )}
            </div>
        </div>
    );
}

import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function FeeSummary({ proposal }) {
    const total = proposal.items.reduce((s, item) => s + parseFloat(item.quantity) * parseFloat(item.rate), 0);

    return (
        <div className="mb-6">
            <div className="pb-4 flex items-center justify-between">
                <div className="font-display text-lg font-semibold">Estimate</div>
                <div className="tabular-nums text-lg font-semibold">{formatCurrency(total)}</div>
            </div>

            <div className="py-3 grid grid-cols-12 text-xs font-medium text-sage border-b border-border">
                <div className="col-span-8">Items</div>
                <div className="col-span-4 text-right">Total</div>
            </div>

            {proposal.items.map((item) => (
                <div key={item.id} className="py-4 grid grid-cols-12 text-sm">
                    <div className="col-span-8">
                        <div className="font-medium">{item.description}</div>
                        {item.details && item.details !== item.description && (
                            <div className="text-xs text-sage mt-2 whitespace-pre-wrap">{item.details}</div>
                        )}
                    </div>
                    <div className="col-span-4 text-right tabular-nums">
                        {formatCurrency(parseFloat(item.quantity) * parseFloat(item.rate))}
                    </div>
                </div>
            ))}

            <div className="pt-4 flex items-center justify-between border-t border-border">
                <div className="font-display text-lg font-semibold">Total</div>
                <div className="tabular-nums text-lg font-semibold">{formatCurrency(total)}</div>
            </div>
        </div>
    );
}

export default function ProposalShow({ proposal, token, studio }) {
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
        <div className="min-h-screen bg-white text-ink px-4 py-12">
            <Head title={proposal.title} />
            <div className="max-w-2xl mx-auto">
                <img src="/images/studio-lockup.svg" alt="Studio" className="w-[200px] h-auto mb-8" />

                <div className="grid grid-cols-2 gap-4 pb-8 mb-8 border-b border-border text-sm">
                    <div className="text-sage">
                        <div className="font-medium text-ink">{studio.name}</div>
                        {studio.address && <div className="whitespace-pre-line">{studio.address}</div>}
                        {studio.email && <div>{studio.email}</div>}
                        {studio.phone && <div>{studio.phone}</div>}
                        {studio.website && <div>{studio.website}</div>}
                    </div>
                    <div className="border-l border-border pl-5">
                        <div className="text-xs font-semibold text-sage mb-2">Client</div>
                        <div className="font-medium text-ink">{proposal.company.name}</div>
                        {proposal.project && <div className="text-sage">{proposal.project.name}</div>}
                    </div>
                </div>

                <h1 className="font-display text-2xl font-semibold mb-1">{proposal.title}</h1>
                {proposal.items.length === 0 && proposal.estimate_amount && (
                    <div className="tabular-nums text-sage mb-6">Estimate: {formatCurrency(proposal.estimate_amount)}</div>
                )}

                <div
                    className="proposal-body pb-6 mb-6 border-b border-border"
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

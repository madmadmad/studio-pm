import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

function FeeSummary({ proposal }) {
    const total = proposal.items.reduce((s, item) => s + parseFloat(item.quantity) * parseFloat(item.rate), 0);

    return (
        <div className="mb-6">
            <div className="font-display text-lg font-semibold pb-4">Estimate</div>

            <div className="py-3 grid grid-cols-12 text-xs font-medium text-shadow-grey border-b border-border">
                <div className="col-span-8">Items</div>
                <div className="col-span-4 text-right">Total</div>
            </div>

            {proposal.items.map((item) => (
                <div key={item.id} className="py-4 grid grid-cols-12 text-sm">
                    <div className="col-span-8">
                        <div className="font-medium">{item.description}</div>
                        {item.details && item.details !== item.description && (
                            <div className="text-xs text-shadow-grey mt-2 whitespace-pre-wrap">{item.details}</div>
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
        <div className="min-h-screen bg-porcelain text-gunmetal px-4 py-10">
            <Head title={proposal.title} />
            <div className="max-w-[800px] mx-auto p-[60px] rounded-[6px] bg-white border border-border">
                <img src="/images/studio-lockup.svg" alt="Studio" className="w-[180px] h-auto mb-8" />

                <div className="grid grid-cols-2 gap-4 pb-8 mb-8 border-b border-border text-sm">
                    <div className="text-shadow-grey">
                        <div className="font-medium text-gunmetal">{studio.name}</div>
                        {studio.address && <div className="whitespace-pre-line">{studio.address}</div>}
                        {studio.email && <div>{studio.email}</div>}
                        {studio.phone && <div>{studio.phone}</div>}
                        {studio.website && <div>{studio.website}</div>}
                    </div>
                    <div className="border-l border-border pl-5">
                        <div className="text-xs font-semibold text-shadow-grey mb-2">Client</div>
                        <div className="font-medium text-gunmetal">{proposal.company.name}</div>
                        {proposal.project && <div className="text-shadow-grey">{proposal.project.name}</div>}
                    </div>
                </div>

                <h1 className="font-display text-2xl font-semibold pb-5">
                    <span className="font-sans font-normal text-shadow-grey">Proposal: </span>
                    {proposal.title}
                </h1>
                {proposal.items.length === 0 && proposal.estimate_amount && (
                    <div className="tabular-nums text-shadow-grey mb-6">Estimate: {formatCurrency(proposal.estimate_amount)}</div>
                )}

                <div
                    className="prose pb-6 mb-6 border-b border-border"
                    dangerouslySetInnerHTML={{ __html: proposal.body }}
                />

                {proposal.items.length > 0 && <FeeSummary proposal={proposal} />}

                {status === 'accepted' ? (
                    <div className="rounded-lg p-4 bg-fern-soft text-fern text-sm font-medium">
                        Accepted{proposal.accepted_at ? ` on ${formatDate(proposal.accepted_at)}` : ''}. Thank you!
                    </div>
                ) : (
                    <button
                        onClick={accept}
                        disabled={accepting}
                        className="bg-watermelon text-white text-sm font-medium px-4 py-2 rounded hover:bg-watermelon/90 transition-colors disabled:opacity-50"
                    >
                        {accepting ? 'Accepting…' : 'Accept Proposal'}
                    </button>
                )}
            </div>
        </div>
    );
}

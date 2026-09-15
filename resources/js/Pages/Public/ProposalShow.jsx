import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';

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
                {proposal.estimate_amount && (
                    <div className="font-mono text-sage mb-6">Estimate: {formatCurrency(proposal.estimate_amount)}</div>
                )}

                <div
                    className="bg-white rounded-lg border border-border p-6 proposal-body mb-6"
                    dangerouslySetInnerHTML={{ __html: proposal.body }}
                />

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

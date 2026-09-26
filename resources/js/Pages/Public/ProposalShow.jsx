import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { DownloadSimple } from '@phosphor-icons/react';
import DocumentFrom from '../../Components/DocumentFrom';
import { formatCurrency, formatDate } from '../../lib/format';
import { api } from '../../lib/api';
import { isBlankRichText, toPlainText, toRichText } from '../../lib/richText';
import RichTextView from '../../Components/RichTextView';

function FeeSummary({ proposal }) {
    const total = proposal.items.reduce((s, item) => s + parseFloat(item.quantity) * parseFloat(item.rate), 0);

    return (
        <div className="document__items">
            <div className="document__section-title">Estimate</div>

            <div className="document__items-head">
                <div>Items</div>
                <div className="document__item-amount">Total</div>
            </div>

            {proposal.items.map((item) => (
                <div key={item.id} className="document__item">
                    <div>
                        <div className="document__item-name">{item.description}</div>
                        {!isBlankRichText(item.details) && toPlainText(item.details) !== item.description && (
                            <div className="document__item-details document__item-details--rich">
                                <RichTextView value={toRichText(item.details)} />
                            </div>
                        )}
                    </div>
                    <div className="document__item-amount document__amount">
                        {formatCurrency(parseFloat(item.quantity) * parseFloat(item.rate))}
                    </div>
                </div>
            ))}

            <div className="document__total-row document__total-row--ruled">
                <div className="document__total-label">Total</div>
                <div className="document__total-value">{formatCurrency(total)}</div>
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
        <div className="document-page">
            <Head title={proposal.title} />
            <div className="document">
                <a
                    href={`/p/${token}/pdf`}
                    title="Download PDF"
                    aria-label="Download PDF"
                    className="icon-btn icon-btn--secondary icon-btn--lg document__download"
                >
                    <DownloadSimple />
                </a>
                <img src="/images/studio-lockup.svg" alt="Studio" className="document__logo" />

                <h1 className="document__title document__title--spaced">
                    <span className="document__title-prefix">Proposal</span>
                    {proposal.title}
                </h1>

                {/* Same header as the public invoice, minus its dates row. */}
                <div className="document__details">
                    <div className="document__details-row">
                        <DocumentFrom studio={studio} />
                        <div>
                            <div className="section-label document__details-label">Client</div>
                            <div className="document__party-name">{proposal.company.name}</div>
                            {proposal.project && <div className="document__muted">{proposal.project.name}</div>}
                        </div>
                    </div>
                </div>

                {proposal.items.length === 0 && proposal.estimate_amount && (
                    <div className="document__estimate">Estimate: {formatCurrency(proposal.estimate_amount)}</div>
                )}

                <div
                    className="prose document__body"
                    dangerouslySetInnerHTML={{ __html: proposal.body }}
                />

                {proposal.items.length > 0 && <FeeSummary proposal={proposal} />}

                {status === 'accepted' ? (
                    <div className="document__notice">
                        Accepted{proposal.accepted_at ? ` on ${formatDate(proposal.accepted_at)}` : ''}. Thank you!
                    </div>
                ) : (
                    <button
                        onClick={accept}
                        disabled={accepting}
                        className="btn btn--lg btn--accent"
                    >
                        {accepting ? 'Accepting…' : 'Accept Proposal'}
                    </button>
                )}
            </div>
        </div>
    );
}

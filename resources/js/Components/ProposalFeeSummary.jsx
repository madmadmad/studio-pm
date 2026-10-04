import { formatCurrency } from '../lib/format';
import { isBlankRichText, toPlainText, toRichText } from '../lib/richText';
import RichTextView from './RichTextView';

// A proposal's line items and total, as the client sees them -- the
// public proposal page, and the read-only view staff get on a project.
export default function ProposalFeeSummary({ proposal }) {
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

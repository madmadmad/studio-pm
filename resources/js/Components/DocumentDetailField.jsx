// One labelled value in a public document's header (an invoice's number
// and dates, a proposal's date and how long it's good for).
export default function DocumentDetailField({ label, children }) {
    return (
        <div>
            <div className="section-label section-label--ruled">{label}</div>
            <div className="document__details-value">{children}</div>
        </div>
    );
}

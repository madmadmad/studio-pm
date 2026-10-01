// `negative` flags a value that's gone the wrong way (over budget).
// `tone` fills the card with a brand color and reverses its text to white
// (any intent: 'primary' for a headline figure, 'danger' for one that's
// gone over, 'muted' for the chart-grey beside a red one, ...) -- or, as 'neutral', just lifts it a shade lighter, text
// unchanged (so `negative` still shows).
export default function MetricCard({ label, value, negative = false, tone = null }) {
    return (
        <div className={`card card--padded metric-card${tone ? ` metric-card--${tone}` : ''}`}>
            <div className="metric-card__label">{label}</div>
            <div className={`metric-card__value${negative && (!tone || tone === 'neutral') ? ' metric-card__value--negative' : ''}`}>{value}</div>
        </div>
    );
}

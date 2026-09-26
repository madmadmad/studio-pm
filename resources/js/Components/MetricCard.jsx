// `negative` flags a value that's gone the wrong way (over budget).
// `tone` fills the card with a brand color and reverses its text to white
// ('fern' for a headline figure; 'watermelon' for one that's gone over).
export default function MetricCard({ label, value, negative = false, tone = null }) {
    return (
        <div className={`card card--padded metric-card${tone ? ` metric-card--${tone}` : ''}`}>
            <div className="metric-card__label">{label}</div>
            <div className={`metric-card__value${negative && !tone ? ' metric-card__value--negative' : ''}`}>{value}</div>
        </div>
    );
}

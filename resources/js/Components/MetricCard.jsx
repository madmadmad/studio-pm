// `negative` flags a value that's gone the wrong way (over budget).
export default function MetricCard({ label, value, negative = false }) {
    return (
        <div className="card card--padded metric-card">
            <div className="metric-card__label">{label}</div>
            <div className={`metric-card__value${negative ? ' metric-card__value--negative' : ''}`}>{value}</div>
        </div>
    );
}

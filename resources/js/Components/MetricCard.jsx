export default function MetricCard({ label, value }) {
    return (
        <div className="card card--padded metric-card">
            <div className="metric-card__label">{label}</div>
            <div className="metric-card__value">{value}</div>
        </div>
    );
}

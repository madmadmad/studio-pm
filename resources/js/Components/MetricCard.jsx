export default function MetricCard({ label, value }) {
    return (
        <div className="card card-padded">
            <div className="text-xs mb-1 text-shadow-grey">{label}</div>
            <div className="tabular-nums text-2xl font-medium">{value}</div>
        </div>
    );
}

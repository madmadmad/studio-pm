export default function MetricCard({ label, value }) {
    return (
        <div className="bg-white rounded-lg border border-border p-4">
            <div className="text-xs mb-1 text-sage">{label}</div>
            <div className="tabular-nums text-2xl font-medium">{value}</div>
        </div>
    );
}

import Button from './Button';

// Under a list that comes a few months at a time (Invoices, Expenses): the
// button that brings in the next ones. Styled by .load-more.
export default function LoadMore({ label, loading, onClick }) {
    return (
        <div className="load-more">
            <Button type="button" variant="secondary" onClick={onClick} disabled={loading}>
                {loading ? 'Loading…' : label}
            </Button>
        </div>
    );
}

// The studio's "From" block in a client-facing document header (public
// invoice and proposal pages), so both show the studio the same way.
export default function DocumentFrom({ studio }) {
    return (
        <div>
            <div className="section-label document__details-label">From</div>
            <div className="document__party-name">{studio.name}</div>
            {studio.address && <div className="document__muted document__address">{studio.address}</div>}
            {studio.email && <div className="document__muted">{studio.email}</div>}
            {studio.phone && <div className="document__muted">{studio.phone}</div>}
        </div>
    );
}

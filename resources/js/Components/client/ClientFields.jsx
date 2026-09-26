// The labeled columns under a client's name: Address and Phone, then any
// extra fields the page adds as children (staff see payment terms and
// reminders; the Client Hub doesn't). Shared by the staff client page and
// the Client Hub home.

export function Field({ label, children }) {
    return (
        <div>
            <div className="section-label section-label--ruled">{label}</div>
            {children ?? <div className="field-grid__empty">—</div>}
        </div>
    );
}

export default function ClientFields({ company, children }) {
    const cityStateZip = [company.city, [company.state, company.postal_code].filter(Boolean).join(' ')]
        .filter(Boolean)
        .join(', ');

    return (
        <div className="field-grid page-section page-section--loose">
            <Field label="Address">
                {(company.address_line1 || cityStateZip) ? (
                    <div className="field-grid__value">
                        {company.address_line1 && <div>{company.address_line1}</div>}
                        {cityStateZip && <div>{cityStateZip}</div>}
                    </div>
                ) : null}
            </Field>
            <Field label="Phone">
                {company.phone ? <div className="field-grid__value">{company.phone}</div> : null}
            </Field>
            {children}
        </div>
    );
}

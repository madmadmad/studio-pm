import Avatar from '../Avatar';
import Badge from '../Badge';

// A client's contacts as a grid of cards: avatar, name and role, email and
// phone, then role badges. `menuFor(contact)` (staff only) returns the
// card's settings menu; `showPortalAccess` adds the Portal access badge.
// Shared by the staff client page and the Client Hub home.
export default function ContactCards({ contacts, menuFor, showPortalAccess = false }) {
    return (
        <div className="contact-grid">
            {contacts.map((contact) => {
                const portal = showPortalAccess && contact.has_portal_access;
                return (
                    <div key={contact.id} className="card card--padded contact-card">
                        <div className="contact-card__header">
                            <Avatar name={contact.name} avatarUrl={contact.avatar_url} id={contact.id} size={40} />
                            <div className="contact-card__identity">
                                <div className="contact-card__name">{contact.name}</div>
                                {contact.role && <div className="contact-card__role">{contact.role}</div>}
                            </div>
                            {menuFor?.(contact)}
                        </div>
                        {(contact.email || contact.phone) && (
                            <div className="contact-card__details">
                                {contact.email && (
                                    <div>
                                        <a href={`mailto:${contact.email}`} className="link link--muted">{contact.email}</a>
                                    </div>
                                )}
                                {contact.phone && <div>{contact.phone}</div>}
                            </div>
                        )}
                        {(contact.is_primary || contact.is_billing || portal) && (
                            <div className="contact-card__badges">
                                {contact.is_primary && <Badge tone="primary" label="Primary" />}
                                {contact.is_billing && <Badge tone="accent" label="Billing" />}
                                {portal && <Badge tone="success" label="Portal access" />}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

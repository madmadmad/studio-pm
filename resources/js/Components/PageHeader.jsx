import BackLink from './BackLink';

// `subtitle` is one node, or an array of nodes for several stacked lines.
// `back` is { href, label } for the link above the title.
export default function PageHeader({ title, subtitle, actions, back }) {
    const subtitleLines = subtitle == null ? [] : Array.isArray(subtitle) ? subtitle : [subtitle];

    return (
        <header className="page-header">
            {back && <BackLink href={back.href} label={back.label} />}
            <div className="page-header__row">
                <h1 className="page-header__title">{title}</h1>
                {actions && <div className="page-header__actions">{actions}</div>}
            </div>
            {subtitleLines.map((line, i) => (
                <p key={i} className="page-header__subtitle">{line}</p>
            ))}
        </header>
    );
}

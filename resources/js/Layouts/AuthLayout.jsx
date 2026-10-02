// Centered single-card screen for the signed-out flows. The page supplies
// the card itself (usually its <form>) with `card auth-shell__card`.
// `logo` heads it with the studio lockup in place of a `title` (staff
// screens) -- the reversed one in dark mode, the dark one in light.
export default function AuthLayout({ logo = false, title, subtitle, children }) {
    return (
        <div className="auth-shell">
            <div className="auth-shell__inner">
                <div className={`auth-shell__header${logo ? ' auth-shell__header--brand' : ''}`}>
                    {logo ? (
                        <h1 className="auth-shell__brand">
                            <img src="/images/studio-lockup-rev.svg" alt="Madhouse Studio" className="auth-shell__logo auth-shell__logo--on-dark" />
                            <img src="/images/studio-lockup.svg" alt="" aria-hidden="true" className="auth-shell__logo auth-shell__logo--on-light" />
                        </h1>
                    ) : (
                        <div className="auth-shell__title">{title}</div>
                    )}
                    {subtitle && <div className="auth-shell__subtitle">{subtitle}</div>}
                </div>
                {children}
            </div>
        </div>
    );
}

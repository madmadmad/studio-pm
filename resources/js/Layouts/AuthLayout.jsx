import { usePage } from '@inertiajs/react';

// Centered single-card screen for the signed-out flows. The page supplies
// the card itself (usually its <form>) with `card auth-shell__card`.
// `logo` heads it with the studio's logo (Settings) in place of a `title`
// (staff screens) -- its dark-background version in dark mode.
export default function AuthLayout({ logo = false, title, subtitle, children }) {
    const { branding } = usePage().props;
    return (
        <div className="auth-shell">
            <div className="auth-shell__inner">
                <div className={`auth-shell__header${logo ? ' auth-shell__header--brand' : ''}`}>
                    {logo ? (
                        <h1 className="auth-shell__brand">
                            <img src={branding.logo_dark} alt={branding.name} className="auth-shell__logo auth-shell__logo--on-dark" />
                            <img src={branding.logo} alt="" aria-hidden="true" className="auth-shell__logo auth-shell__logo--on-light" />
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

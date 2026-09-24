// Centered single-card screen for the signed-out flows. The page supplies
// the card itself (usually its <form>) with `card auth-shell__card`.
export default function AuthLayout({ title, subtitle, children }) {
    return (
        <div className="auth-shell">
            <div className="auth-shell__inner">
                <div className="auth-shell__header">
                    <div className="auth-shell__title">{title}</div>
                    {subtitle && <div className="auth-shell__subtitle">{subtitle}</div>}
                </div>
                {children}
            </div>
        </div>
    );
}

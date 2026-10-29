// A titled section: its heading -- with what it's for, and any action
// beside it (Add, Edit) -- above its panel. In drawers and forms they
// stack; on a settings-style page (.settings-sections: Settings, My
// profile) each is a row, the heading on the left and the card on the
// right, so rows line up whatever their height.
export default function SettingsSection({ title, description, action, children }) {
    return (
        <section className="settings-section">
            <div className="settings-section__intro">
                <div className="settings-section__heading">
                    <h2 className="panel-title">{title}</h2>
                    {action}
                </div>
                {description && <div className="settings-section__description">{description}</div>}
            </div>
            <div className="settings-section__body">{children}</div>
        </section>
    );
}

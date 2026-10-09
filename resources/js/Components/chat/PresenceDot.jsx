// Online (a green dot) or away (a hollow grey ring), from Chat's presence
// channel. Given an avatar as children, sits on its corner; alone, inline.
export default function PresenceDot({ online, children }) {
    const label = online ? 'Online' : 'Away';

    return (
        <span className={`presence${children ? '' : ' presence--inline'}`}>
            {children}
            <span className={`presence__dot${online ? ' presence__dot--online' : ''}`} title={label} aria-label={label} role="img" />
        </span>
    );
}

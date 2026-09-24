export default function Card({ padded = true, className = '', children, ...props }) {
    return (
        <div className={`card${padded ? ' card--padded' : ''} ${className}`.trim()} {...props}>
            {children}
        </div>
    );
}

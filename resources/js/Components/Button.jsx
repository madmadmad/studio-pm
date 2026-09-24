const VARIANTS = {
    primary: 'btn btn--primary',
    confirm: 'btn btn--confirm',
    accent: 'btn btn--accent',
    secondary: 'btn btn--secondary',
    outline: 'btn btn--outline',
    danger: 'btn btn--danger',
    link: 'link-btn',
    'link-accent': 'link-btn link-btn--accent',
};

export default function Button({ variant = 'primary', className = '', ...props }) {
    const variantClass = VARIANTS[variant] || VARIANTS.primary;

    return <button className={`${variantClass} ${className}`.trim()} {...props} />;
}

const VARIANTS = {
    primary: 'btn-primary',
    confirm: 'btn-confirm',
    accent: 'btn-accent',
    secondary: 'btn-secondary',
    outline: 'btn-outline',
    danger: 'btn-danger',
    link: 'btn-link',
    'link-accent': 'btn-link-accent',
    'link-danger': 'btn-link-danger',
};

const LINK_VARIANTS = ['link', 'link-accent', 'link-danger'];

export default function Button({ variant = 'primary', className = '', ...props }) {
    const variantClass = VARIANTS[variant] || VARIANTS.primary;
    const isLink = LINK_VARIANTS.includes(variant);

    return <button className={`${isLink ? '' : 'btn '}${variantClass} ${className}`.trim()} {...props} />;
}

import { photoSrcSet } from '../lib/photoSrcSet';

const PALETTE = [
    'var(--color-decorative-1)',
    'var(--color-decorative-2)',
    'var(--color-decorative-3)',
    'var(--color-accent-soft)',
    'var(--color-primary-soft)',
    'var(--color-surface-muted)',
];

// A simple deterministic hash so the same author always lands on the same
// palette color everywhere the avatar shows up, without a lookup table.
function hashToIndex(id) {
    const str = String(id ?? '');
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = (hash * 31 + str.charCodeAt(i)) | 0;
    }
    return Math.abs(hash) % PALETTE.length;
}

function initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    const letters = (parts[0]?.[0] || '') + (parts[1]?.[0] || '');
    return (letters || name[0] || '?').toUpperCase();
}

// `responsive` hands sizing to CSS (.avatar--responsive: smaller on
// phones) instead of the fixed inline `size`.
export default function Avatar({ name, avatarUrl, id, size = 40, responsive = false }) {
    const dimensions = responsive ? {} : { width: `${size}px`, height: `${size}px` };
    const sizeClass = responsive ? ' avatar--responsive' : '';

    if (avatarUrl) {
        return (
            <img
                src={avatarUrl}
                srcSet={photoSrcSet(avatarUrl, 96, 400)}
                // .avatar--responsive tops out at --space-10 (40px).
                sizes={responsive ? '40px' : `${size}px`}
                loading="lazy"
                decoding="async"
                alt={name || ''}
                className={`avatar avatar--photo${sizeClass}`}
                style={dimensions}
            />
        );
    }

    return (
        <div
            title={name}
            className={`avatar avatar--initials${sizeClass}`}
            style={{ ...dimensions, backgroundColor: PALETTE[hashToIndex(id)], ...(responsive ? {} : { fontSize: size * 0.4 }) }}
        >
            {initials(name)}
        </div>
    );
}

import { photoSrcSet } from '../lib/photoSrcSet';

// A bio photo in its portrait frame (4:5, the standard radius) -- or, with
// none yet, the person's initials in the same frame. `size` is 'lg' for a
// proposal's team section (180px wide), 'sm' for the editor previews.
export default function BioPhoto({ name, url, size = 'lg' }) {
    const initials = (name || '').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');

    return (
        <div className={`bio-photo bio-photo--${size}`}>
            {url ? <img src={url} srcSet={photoSrcSet(url, 400, 800)} sizes={size === 'lg' ? '180px' : '4rem'} loading="lazy" decoding="async" alt="" /> : <span className="bio-photo__initials">{initials}</span>}
        </div>
    );
}

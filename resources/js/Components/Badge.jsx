// Intents, as in the color tokens (base/_tokens.scss).
const TONES = ['neutral', 'primary', 'secondary', 'accent', 'success', 'danger', 'warning', 'info'];

export default function Badge({ tone = 'neutral', label }) {
    return (
        <span className={`badge badge--${TONES.includes(tone) ? tone : 'neutral'}`}>
            {label}
        </span>
    );
}

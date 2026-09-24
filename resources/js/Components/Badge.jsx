const TONES = ['neutral', 'watermelon', 'fern'];

export default function Badge({ tone = 'neutral', label }) {
    return (
        <span className={`badge badge--${TONES.includes(tone) ? tone : 'neutral'}`}>
            {label}
        </span>
    );
}

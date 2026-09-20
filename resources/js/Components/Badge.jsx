const TONES = {
    neutral: 'bg-[#E7E7E9] text-shadow-grey',
    watermelon: 'bg-watermelon-soft text-watermelon',
    fern: 'bg-fern-soft text-fern',
    fuchsia: 'bg-fuchsia-soft text-fuchsia',
};

export default function Badge({ tone = 'neutral', label }) {
    return (
        <span className={`text-xs font-medium px-2 py-0.5 rounded ${TONES[tone] || TONES.neutral}`}>
            {label}
        </span>
    );
}

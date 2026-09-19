const TONES = {
    neutral: 'bg-[#E7E7E9] text-sage',
    brass: 'bg-brass-soft text-brass',
    pine: 'bg-pine-soft text-pine',
    brick: 'bg-brick-soft text-brick',
};

export default function Badge({ tone = 'neutral', label }) {
    return (
        <span className={`text-xs font-medium px-2 py-0.5 rounded ${TONES[tone] || TONES.neutral}`}>
            {label}
        </span>
    );
}

import { Link } from '@inertiajs/react';
import { ArrowLeft } from '@phosphor-icons/react';

export default function BackLink({ href, label }) {
    return (
        <div className="back-link">
            <Link href={href} className="back-link__anchor">
                <ArrowLeft size={14} /> {label}
            </Link>
        </div>
    );
}

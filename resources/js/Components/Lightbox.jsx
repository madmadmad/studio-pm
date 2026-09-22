import { useEffect } from 'react';
import { CaretLeft, CaretRight, DownloadSimple, X } from '@phosphor-icons/react';

export default function Lightbox({ images, index, onClose, onNavigate }) {
    const current = images[index];

    useEffect(() => {
        function onKeyDown(e) {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowLeft' && index > 0) onNavigate(index - 1);
            if (e.key === 'ArrowRight' && index < images.length - 1) onNavigate(index + 1);
        }
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [index, images.length, onClose, onNavigate]);

    if (!current) return null;

    return (
        <div className="fixed inset-0 z-50 bg-gunmetal/80 drawer-overlay flex items-center justify-center p-6" onClick={onClose}>
            <button onClick={onClose} className="absolute top-4 right-4 text-white hover:opacity-70">
                <X size={28} />
            </button>

            <a
                href={current.downloadUrl}
                download={current.name}
                onClick={(e) => e.stopPropagation()}
                className="absolute top-4 right-16 text-white hover:opacity-70"
                title="Download"
            >
                <DownloadSimple size={28} />
            </a>

            {index > 0 && (
                <button
                    onClick={(e) => { e.stopPropagation(); onNavigate(index - 1); }}
                    className="absolute left-4 top-1/2 -translate-y-1/2 text-white hover:opacity-70"
                >
                    <CaretLeft size={32} />
                </button>
            )}

            {index < images.length - 1 && (
                <button
                    onClick={(e) => { e.stopPropagation(); onNavigate(index + 1); }}
                    className="absolute right-4 top-1/2 -translate-y-1/2 text-white hover:opacity-70"
                >
                    <CaretRight size={32} />
                </button>
            )}

            <img
                src={current.src}
                alt={current.name}
                onClick={(e) => e.stopPropagation()}
                className="max-w-full max-h-full rounded object-contain"
            />
        </div>
    );
}

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
        <div className="lightbox" onClick={onClose}>
            <button onClick={onClose} className="lightbox__control lightbox__control--close">
                <X size={28} />
            </button>

            <a
                href={current.downloadUrl}
                download={current.name}
                onClick={(e) => e.stopPropagation()}
                className="lightbox__control lightbox__control--download"
                title="Download"
            >
                <DownloadSimple size={28} />
            </a>

            {index > 0 && (
                <button
                    onClick={(e) => { e.stopPropagation(); onNavigate(index - 1); }}
                    className="lightbox__control lightbox__control--prev"
                >
                    <CaretLeft size={32} />
                </button>
            )}

            {index < images.length - 1 && (
                <button
                    onClick={(e) => { e.stopPropagation(); onNavigate(index + 1); }}
                    className="lightbox__control lightbox__control--next"
                >
                    <CaretRight size={32} />
                </button>
            )}

            <img
                src={current.src}
                alt={current.name}
                onClick={(e) => e.stopPropagation()}
                className="lightbox__image"
            />
        </div>
    );
}

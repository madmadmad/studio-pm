import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ColorPicker from './ColorPicker';
import { api } from '../lib/api';
import { applyBrand } from '../lib/brand';

// A person's own color for the app (Profile), beside their Appearance.
// Saved a moment after the picker settles -- dragging across the system
// picker fires a change for every step -- and the whole app recolors as
// soon as it is, with text in the color adjusted for contrast.
export default function BrandColorSetting({ current }) {
    const [color, setColor] = useState(current ?? null);
    const [error, setError] = useState('');
    const timer = useRef(null);

    function change(value) {
        setColor(value);
        setError('');
        clearTimeout(timer.current);
        timer.current = setTimeout(async () => {
            try {
                const saved = await api.patch('/api/profile/brand-color', { brand_color: value });
                applyBrand(saved.palette);
                router.reload({ only: ['auth', 'brand'] });
            } catch (err) {
                setError(err.message || 'Could not save that.');
            }
        }, 400);
    }

    return (
        <div className="form-panel">
            <div className="section-label section-label--ruled">Color</div>
            <ColorPicker value={color} onChange={change} />
            <p className="form-hint form-hint--attached">Buttons, highlights and links take this color. Text in it is lightened or darkened where it needs to stay readable.</p>
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

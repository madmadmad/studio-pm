import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Desktop, Moon, Sun } from '@phosphor-icons/react';
import { api } from '../lib/api';

export const APPEARANCE_OPTIONS = [
    { value: 'dark', label: 'Dark', icon: <Moon /> },
    { value: 'light', label: 'Light', icon: <Sun /> },
    { value: 'system', label: 'System', icon: <Desktop /> },
];

// Choosing an Appearance: the page switches at once -- <html data-theme>
// drives the tokens (base/_tokens.scss) -- and the choice is saved to
// `endpoint` (staff or client) for every page after. Shared by the Profile
// pages' switch and the sidebar's account menu (AccountMenu).
export function useAppearance(current, endpoint, onError) {
    const [theme, setTheme] = useState(current || 'dark');

    async function choose(value) {
        const previous = theme;
        setTheme(value);
        onError?.('');
        document.documentElement.dataset.theme = value;
        try {
            await api.patch(endpoint, { theme: value });
            router.reload({ only: ['auth'] });
        } catch (err) {
            setTheme(previous);
            document.documentElement.dataset.theme = previous;
            onError?.(err.message || 'Could not save that.');
        }
    }

    return { theme, choose };
}

// The Dark / Light / System switch, side by side.
export function AppearanceToggle({ current, endpoint, onError }) {
    const { theme, choose } = useAppearance(current, endpoint, onError);

    return (
        <div role="radiogroup" aria-label="Appearance" className="view-toggle appearance-setting">
            {APPEARANCE_OPTIONS.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={theme === option.value}
                    onClick={() => choose(option.value)}
                    className={`view-toggle__option${theme === option.value ? ' view-toggle__option--active' : ''}`}
                >
                    {option.icon} {option.label}
                </button>
            ))}
        </div>
    );
}

// A person's Appearance (staff and clients alike) as a Profile panel:
// Dark (the default), Light, or System to follow the computer.
export default function AppearanceSetting({ current, endpoint }) {
    const [error, setError] = useState('');

    return (
        <div className="form-panel">
            <div className="section-label section-label--ruled">Appearance</div>
            <AppearanceToggle current={current} endpoint={endpoint} onError={setError} />
            <p className="form-hint form-hint--attached">System follows your computer&rsquo;s light or dark setting.</p>
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}

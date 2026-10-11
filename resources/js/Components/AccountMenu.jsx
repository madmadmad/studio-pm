import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CaretUpDown, Check, SignOut, UserCircle } from '@phosphor-icons/react';
import Avatar from './Avatar';
import { APPEARANCE_OPTIONS, useAppearance } from './AppearanceSetting';

// The signed-in person at the foot of the sidebar (AppShell), as a menu:
// their Profile, Appearance (Dark / Light / System, saved as on the
// Profile page), and Log out. Opens upward, since it sits at the bottom of
// the window; closes on a click outside or Escape, and on leaving for
// Profile.
export default function AccountMenu({ user, profileHref, logoutHref, logoutLabel, appearanceEndpoint }) {
    const [open, setOpen] = useState(false);
    const [error, setError] = useState('');
    const ref = useRef(null);
    const trigger = useRef(null);
    const appearance = useAppearance(user.theme, appearanceEndpoint, setError);

    useEffect(() => {
        if (!open) return undefined;
        function onPointerDown(e) {
            if (!ref.current?.contains(e.target)) setOpen(false);
        }
        function onKeyDown(e) {
            if (e.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        }
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    function logout() {
        setOpen(false);
        router.post(logoutHref);
    }

    return (
        <div ref={ref} className="account-menu">
            {open && (
                <div className="popover account-menu__panel" role="menu" aria-label="Account">
                    <Link href={profileHref} role="menuitem" onClick={() => setOpen(false)} className="account-menu__item">
                        <UserCircle /> Profile
                    </Link>
                    {/* Appearance as rows, to fit the sidebar's width. */}
                    <div role="group" aria-labelledby="account-appearance" className="account-menu__group">
                        <span className="account-menu__label" id="account-appearance">Appearance</span>
                        {APPEARANCE_OPTIONS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="menuitemradio"
                                aria-checked={appearance.theme === option.value}
                                onClick={() => appearance.choose(option.value)}
                                className="account-menu__item"
                            >
                                {option.icon} {option.label}
                                {appearance.theme === option.value && <Check className="account-menu__check" />}
                            </button>
                        ))}
                        {error && <div className="form-error account-menu__error">{error}</div>}
                    </div>
                    <button type="button" role="menuitem" onClick={logout} className="account-menu__item account-menu__item--ruled">
                        <SignOut /> {logoutLabel}
                    </button>
                </div>
            )}
            <button
                ref={trigger}
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="menu"
                aria-expanded={open}
                className="account-menu__trigger"
            >
                <Avatar name={user.name} avatarUrl={user.avatar_url} id={user.id} size={28} />
                <span className="account-menu__details">
                    <span className="account-menu__name">{user.name}</span>
                    <span className="account-menu__email">{user.email}</span>
                </span>
                <CaretUpDown className="account-menu__caret" />
            </button>
        </div>
    );
}

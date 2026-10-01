import { router, usePage } from '@inertiajs/react';
import AppShell from './AppShell';

// The Client Hub frame: the same sidebar as the staff app, with the
// client's sections. Invoices only appear for billing and primary
// contacts (auth.user.can_view_invoices, shared by HandleInertiaRequests).
export default function PortalLayout({ children }) {
    const { auth, portalPreview } = usePage().props;
    const contact = auth?.user;

    const navItems = [
        { href: '/portal', label: 'Projects', exact: true, also: ['/portal/projects'] },
        { href: '/portal/proposals', label: 'Proposals' },
        ...(contact?.can_view_invoices ? [{ href: '/portal/invoices', label: 'Invoices' }] : []),
        { href: '/portal/contacts', label: 'Contacts' },
    ];

    return (
        <AppShell navItems={navItems} profileHref="/portal/profile" logoutHref="/portal/logout" logoutLabel="Sign out">
            {/* A manager's read-only look (PortalPreviewController). */}
            {portalPreview && (
                <div role="status" className="preview-banner">
                    <span>
                        <strong>Staff preview</strong> &middot; You&rsquo;re seeing {portalPreview.company}&rsquo;s portal as {portalPreview.contact}. Nothing can be changed here.
                    </span>
                    <button type="button" onClick={() => router.post('/portal/preview/exit')} className="btn btn--secondary">
                        Exit preview
                    </button>
                </div>
            )}
            {children}
        </AppShell>
    );
}

import { ArrowSquareOut, DropboxLogo, FigmaLogo, GoogleDriveLogo, LinkSimple } from '@phosphor-icons/react';

// Services a shared link is recognised as, by host: their own glyph and
// name. Anything else is a plain link, named by its site.
const SERVICES = [
    { match: /(^|\.)dropbox\.com$/, name: 'Dropbox', Icon: DropboxLogo },
    { match: /^(drive|docs)\.google\.com$/, name: 'Google Drive', Icon: GoogleDriveLogo },
    { match: /(^|\.)figma\.com$/, name: 'Figma', Icon: FigmaLogo },
];

// What a link card shows: the file or folder name from the URL's path
// when there is one (Dropbox and WeTransfer put it there), else the site.
export function describeLink(url) {
    let parsed;
    try {
        parsed = new URL(url);
    } catch {
        return { title: url, source: 'Link', Icon: LinkSimple };
    }
    const host = parsed.hostname.replace(/^www\./, '');
    const service = SERVICES.find((s) => s.match.test(host));
    const lastSegment = decodeURIComponent(parsed.pathname.split('/').filter(Boolean).pop() || '');
    // A file-ish name (has an extension, or Dropbox's folder names), not an id.
    const named = lastSegment && (/\.[a-z0-9]{2,5}$/i.test(lastSegment) || (service?.name === 'Dropbox' && !/^[a-z0-9]{10,}$/i.test(lastSegment)));
    return {
        title: named ? lastSegment : service?.name ?? host,
        source: service?.name ?? host,
        Icon: service?.Icon ?? LinkSimple,
    };
}

// A shared link in a message, as a card like a file attachment
// (AttachmentChip): the service's glyph, the name, where it lives, and an
// open-in-new-tab icon.
export default function LinkChip({ url }) {
    const { title, source, Icon } = describeLink(url);

    return (
        <a href={url} target="_blank" rel="noopener noreferrer" className="attachment-chip" title={url}>
            <Icon size={20} className="attachment-chip__icon" />
            <span className="attachment-chip__name">{title}</span>
            {source !== title && <span className="attachment-chip__size">{source}</span>}
            <ArrowSquareOut size={16} className="attachment-chip__icon" />
        </a>
    );
}

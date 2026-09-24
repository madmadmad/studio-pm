import { DownloadSimple, File, FileCsv, FileDoc, FilePdf, FileTxt, FileXls, FileZip } from '@phosphor-icons/react';
import { formatFileSize } from '../lib/format';

const ICONS = {
    pdf: FilePdf,
    doc: FileDoc,
    docx: FileDoc,
    xls: FileXls,
    xlsx: FileXls,
    csv: FileCsv,
    txt: FileTxt,
    zip: FileZip,
};

function iconFor(filename) {
    const extension = filename.split('.').pop()?.toLowerCase();
    return ICONS[extension] || File;
}

export default function AttachmentChip({ attachment, downloadUrl }) {
    const Icon = iconFor(attachment.original_name);

    return (
        <a href={downloadUrl} download={attachment.original_name} className="attachment-chip" title={attachment.original_name}>
            <Icon size={20} className="attachment-chip__icon" />
            <span className="attachment-chip__name">{attachment.original_name}</span>
            <span className="attachment-chip__size">{formatFileSize(attachment.size)}</span>
            <DownloadSimple size={16} className="attachment-chip__icon" />
        </a>
    );
}

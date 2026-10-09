import { useEffect, useRef, useState } from 'react';
import { Paperclip, PaperPlaneRight, X } from '@phosphor-icons/react';
import EmojiPicker from '../EmojiPicker';
import { iconFor } from '../AttachmentChip';
import MentionTextarea from './MentionTextarea';
import { bodyForSaving } from '../../lib/chatText';
import { formatFileSize } from '../../lib/format';

// Writing a message: text with @mentions and emoji, and files -- from the
// paperclip, pasted, or dropped on the conversation (`droppedFiles`).
// Enter sends; Shift+Enter is a new line. A file over the size limit is
// turned away here, before it uploads; the server checks everything again.
export default function ChatComposer({ placeholder, members, limits, onSend, onTyping, droppedFiles, focusKey }) {
    const [text, setText] = useState('');
    const [mentions, setMentions] = useState([]);
    const [files, setFiles] = useState([]); // { key, file, previewUrl }
    const [error, setError] = useState('');
    const field = useRef(null);

    useEffect(() => {
        field.current?.focus();
    }, [focusKey]);

    useEffect(() => {
        if (droppedFiles?.length) addFiles(droppedFiles);
    }, [droppedFiles]);

    // Previews are object URLs: let them go with their tiles.
    useEffect(() => () => files.forEach((f) => f.previewUrl && URL.revokeObjectURL(f.previewUrl)), []);

    function addFiles(list) {
        const incoming = Array.from(list);
        const tooBig = incoming.filter((file) => file.size > limits.maxFileSizeKb * 1024);
        const accepted = incoming.filter((file) => !tooBig.includes(file));
        setError(tooBig.length ? `${tooBig.map((f) => f.name).join(', ')} ${tooBig.length === 1 ? 'is' : 'are'} over the ${formatFileSize(limits.maxFileSizeKb * 1024)} limit.` : '');

        setFiles((current) => [
            ...current,
            ...accepted.map((file) => ({
                key: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`,
                file,
                previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            })),
        ].slice(0, limits.maxFiles));
        field.current?.focus();
    }

    function removeFile(key) {
        setFiles((current) => {
            const gone = current.find((f) => f.key === key);
            if (gone?.previewUrl) URL.revokeObjectURL(gone.previewUrl);
            return current.filter((f) => f.key !== key);
        });
    }

    function onPaste(e) {
        const pasted = Array.from(e.clipboardData?.files ?? []);
        if (pasted.length) {
            e.preventDefault();
            addFiles(pasted);
        }
    }

    function changeText(value) {
        setText(value);
        if (value.trim()) onTyping?.();
    }

    function submit() {
        const body = bodyForSaving(text.trim(), mentions);
        if (!body && files.length === 0) return;

        onSend({ body, files: files.map((f) => f.file) });
        files.forEach((f) => f.previewUrl && URL.revokeObjectURL(f.previewUrl));
        setText('');
        setMentions([]);
        setFiles([]);
        setError('');
    }

    return (
        <form className="chat-composer" onSubmit={(e) => { e.preventDefault(); submit(); }}>
            {files.length > 0 && (
                <div className="pending-attachments">
                    {files.map(({ key, file, previewUrl }) => {
                        const Icon = iconFor(file.name);
                        return (
                            <div key={key} className="pending-attachments__item">
                                {previewUrl ? (
                                    <img src={previewUrl} alt={file.name} className="pending-attachments__thumb" />
                                ) : (
                                    <div className="pending-attachments__file" title={file.name}>
                                        <Icon size={20} className="pending-attachments__icon" />
                                        <span className="pending-attachments__name">{file.name}</span>
                                    </div>
                                )}
                                <button type="button" onClick={() => removeFile(key)} className="pending-attachments__remove" aria-label={`Remove ${file.name}`}>
                                    <X size={12} weight="bold" />
                                </button>
                            </div>
                        );
                    })}
                </div>
            )}
            {error && <p className="composer__error" role="alert">{error}</p>}
            <div className="chat-composer__box">
                <MentionTextarea
                    ref={field}
                    value={text}
                    onChange={changeText}
                    onMention={(m) => setMentions((current) => (current.some((c) => c.id === m.id) ? current : [...current, m]))}
                    members={members}
                    onSubmit={submit}
                    onPaste={onPaste}
                    placeholder={placeholder}
                    maxLength={limits.maxBodyLength}
                    className="chat-composer__input"
                />
                <div className="chat-composer__tools">
                    <label className="icon-btn icon-btn--secondary composer__attach" title="Attach files">
                        <Paperclip size={20} />
                        <input type="file" multiple hidden onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
                    </label>
                    <EmojiPicker onPick={(emoji) => field.current?.insert(emoji)} placement="up" align="right" />
                    <button type="submit" className="icon-btn icon-btn--confirm" title="Send (Enter)" aria-label="Send" disabled={!text.trim() && files.length === 0}>
                        <PaperPlaneRight size={20} />
                    </button>
                </div>
            </div>
        </form>
    );
}

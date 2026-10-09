import { memo, useState } from 'react';
import { ArrowClockwise, PencilSimple, Trash } from '@phosphor-icons/react';
import Avatar from '../Avatar';
import AttachmentChip from '../AttachmentChip';
import EmojiPicker, { COMPOSER_EMOJI } from '../EmojiPicker';
import MentionTextarea from './MentionTextarea';
import { bodyForEditing, bodyForSaving, parseMessage } from '../../lib/chatText';
import { formatDateTime, formatFileSize } from '../../lib/format';

function timeOf(value) {
    return new Date(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

// The text with mentions (yours in the primary color) and links as elements -- every
// other character a plain string, which React escapes. `suffix` follows
// the last word ("(edited)").
export function MessageText({ body, staffById, meId, suffix }) {
    return (
        <div className="message__text">
            {parseMessage(body, staffById).map((part, i) => {
                if (part.type === 'mention') {
                    return (
                        <span key={i} className={`chat-message__mention${part.id === meId ? ' chat-message__mention--me' : ''}`}>
                            @{part.name}
                        </span>
                    );
                }
                if (part.type === 'link') {
                    return (
                        <span key={i}>
                            <a href={part.url} target="_blank" rel="noopener noreferrer" className="message__link">{part.url}</a>
                            {part.trailing}
                        </span>
                    );
                }
                return <span key={i}>{part.text}</span>;
            })}
            {suffix}
        </div>
    );
}

function Attachments({ message, onOpenImages, onImageLoad }) {
    const images = message.attachments.filter((a) => a.is_image);
    const files = message.attachments.filter((a) => !a.is_image);
    const lightbox = images.map((img) => ({ src: img.display_url, downloadUrl: img.url, name: img.name }));
    // Until its thumbnail's made (a moment, on the queue), the lightbox copy.
    const src = (img) => img.thumbnail_url ?? img.display_url;

    return (
        <>
            {images.length === 1 && (
                <button type="button" onClick={() => onOpenImages(lightbox, 0)} className="message__image">
                    <img src={src(images[0])} alt={images[0].name} width={images[0].width ?? undefined} height={images[0].height ?? undefined} onLoad={onImageLoad} decoding="async" className="message__image-img" />
                </button>
            )}
            {images.length > 1 && (
                <div className="message__gallery">
                    {images.map((img, i) => (
                        <button key={img.id} type="button" onClick={() => onOpenImages(lightbox, i)} className="message__gallery-item">
                            <img src={src(img)} alt={img.name} onLoad={onImageLoad} decoding="async" className="message__gallery-img" />
                        </button>
                    ))}
                </div>
            )}
            {files.length > 0 && (
                <div className="message__files">
                    {files.map((file) => (
                        <AttachmentChip key={file.id} attachment={{ original_name: file.name, size: file.size }} downloadUrl={file.url} />
                    ))}
                </div>
            )}
        </>
    );
}

function Reactions({ message, meId, onReact }) {
    return (
        <div className="message__reactions">
            {message.reactions.map((reaction) => {
                const mine = reaction.users.some((u) => u.id === meId);
                return (
                    <button
                        key={reaction.emoji}
                        type="button"
                        onClick={() => onReact(message, reaction.emoji)}
                        title={reaction.users.map((u) => (u.id === meId ? 'You' : u.name)).join(', ')}
                        aria-pressed={mine}
                        aria-label={`${reaction.emoji} ${reaction.count}: ${reaction.users.map((u) => u.name).join(', ')}`}
                        className={`message__reaction${mine ? ' message__reaction--mine' : ''}`}
                    >
                        <span>{reaction.emoji}</span>
                        <span className="message__reaction-count">{reaction.count}</span>
                    </button>
                );
            })}
        </div>
    );
}

// Editing in place: the same field as the composer, Enter to save and
// Escape to cancel.
function EditForm({ message, staffById, members, limits, onSave, onCancel }) {
    const [{ text, mentions }, setDraft] = useState(() => bodyForEditing(message.body, staffById));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    async function save() {
        const body = bodyForSaving(text.trim(), mentions);
        if (!body && message.attachments.length === 0) {
            setError('A message needs words or a file. Delete it instead?');
            return;
        }
        setSaving(true);
        try {
            await onSave(message, body);
        } catch (e) {
            setError(e.message || 'Could not save.');
            setSaving(false);
        }
    }

    return (
        <div className="message__edit">
            <MentionTextarea
                value={text}
                onChange={(value) => setDraft((d) => ({ ...d, text: value }))}
                onMention={(m) => setDraft((d) => ({ ...d, mentions: [...d.mentions.filter((x) => x.id !== m.id), m] }))}
                members={members}
                onSubmit={save}
                onCancel={onCancel}
                maxLength={limits.maxBodyLength}
                autoFocus
            />
            {error && <p className="composer__error" role="alert">{error}</p>}
            <p className="chat-message__edit-hint">
                Enter to <button type="button" className="link-btn" onClick={save} disabled={saving}>save</button>, Escape to <button type="button" className="link-btn" onClick={onCancel}>cancel</button>
            </p>
        </div>
    );
}

// One message in the conversation. `continued` is a follow-on from the same
// person moments after their last, drawn without the avatar and name.
function ChatMessage({ message, continued, me, staffById, members, limits, onReact, onEdit, onDelete, onRetry, onDiscard, onOpenImages, onImageLoad }) {
    const [editing, setEditing] = useState(false);
    const mine = message.user?.id === me.id;
    const mentionsMe = !message.deleted && message.mention_ids.includes(me.id);
    const live = !message.pending && !message.deleted;

    const classes = ['message', 'chat-message'];
    if (continued) classes.push('chat-message--continued');
    if (mentionsMe) classes.push('chat-message--mentions-me');
    if (message.pending) classes.push('chat-message--pending');
    if (message.failed) classes.push('chat-message--failed');

    return (
        <div className={classes.join(' ')} data-message-id={message.id}>
            <div className="message__rail chat-message__rail">
                {continued
                    ? <span className="chat-message__gutter-time" title={formatDateTime(message.created_at)}>{timeOf(message.created_at)}</span>
                    : <Avatar name={message.user?.name} avatarUrl={message.user?.avatar_url} id={message.user?.id} size={36} />}
            </div>
            <div className="message__main">
                {!continued && (
                    <div className="message__header">
                        <span className="message__sender">{message.user?.name ?? 'Former teammate'}</span>
                        <time className="message__time" dateTime={message.created_at} title={formatDateTime(message.created_at)}>{timeOf(message.created_at)}</time>
                    </div>
                )}

                {live && !editing && (
                    <div className="chat-message__actions" role="toolbar" aria-label="Message actions">
                        <EmojiPicker emojis={COMPOSER_EMOJI} onPick={(emoji) => onReact(message, emoji)} label="Add reaction" size={18} align="right" />
                        {mine && (
                            <>
                                <button type="button" className="icon-btn icon-btn--edit" title="Edit" aria-label="Edit message" onClick={() => setEditing(true)}>
                                    <PencilSimple />
                                </button>
                                <button type="button" className="icon-btn icon-btn--danger" title="Delete" aria-label="Delete message" onClick={() => onDelete(message)}>
                                    <Trash />
                                </button>
                            </>
                        )}
                    </div>
                )}

                {message.deleted ? (
                    <div className="message__body message__body--deleted">This message was deleted.</div>
                ) : editing ? (
                    <EditForm
                        message={message}
                        staffById={staffById}
                        members={members}
                        limits={limits}
                        onSave={async (m, body) => { await onEdit(m, body); setEditing(false); }}
                        onCancel={() => setEditing(false)}
                    />
                ) : (
                    <div className="message__body">
                        {message.body && (
                            <MessageText
                                body={message.body}
                                staffById={staffById}
                                meId={me.id}
                                suffix={message.edited_at && <span className="chat-message__edited" title={`Edited ${formatDateTime(message.edited_at)}`}>(edited)</span>}
                            />
                        )}
                        {message.pending ? (
                            message.attachments.length > 0 && (
                                <ul className="chat-message__uploads">
                                    {message.attachments.map((a) => <li key={a.id}>{a.name} · {formatFileSize(a.size)}</li>)}
                                </ul>
                            )
                        ) : (
                            <Attachments message={message} onOpenImages={onOpenImages} onImageLoad={onImageLoad} />
                        )}
                    </div>
                )}

                {message.pending && !message.failed && message.progress != null && (
                    <div className="progress chat-message__progress" role="progressbar" aria-label="Uploading" aria-valuemin={0} aria-valuemax={100} aria-valuenow={message.progress}>
                        <div className="progress__bar" style={{ width: `${message.progress}%` }} />
                    </div>
                )}
                {message.failed && (
                    <p className="chat-message__failure" role="alert">
                        Not sent{message.error ? ` — ${message.error}` : ''}.{' '}
                        <button type="button" className="link-btn" onClick={() => onRetry(message.client_id)}>
                            <ArrowClockwise size={14} /> Try again
                        </button>{' '}
                        <button type="button" className="link-btn" onClick={() => onDiscard(message.client_id)}>Discard</button>
                    </p>
                )}

                {live && message.reactions.length > 0 && <Reactions message={message} meId={me.id} onReact={onReact} />}
            </div>
        </div>
    );
}

export default memo(ChatMessage);

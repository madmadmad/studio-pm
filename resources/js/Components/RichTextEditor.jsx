import { useEditor, useEditorState, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect, useRef, useState } from 'react';
import {
    CaretDown,
    ListBullets,
    ListNumbers,
    Paragraph,
    TextB,
    TextH,
    TextHFour,
    TextHThree,
    TextHTwo,
    TextItalic,
    TextStrikethrough,
    TextUnderline,
} from '@phosphor-icons/react';

const ICON_SIZE = 18;

const HEADINGS = [
    { level: 2, label: 'Heading 2', Icon: TextHTwo },
    { level: 3, label: 'Heading 3', Icon: TextHThree },
    { level: 4, label: 'Heading 4', Icon: TextHFour },
];

const LISTS = [
    { type: 'bulletList', label: 'Bullet list', Icon: ListBullets, toggle: (chain) => chain.toggleBulletList() },
    { type: 'orderedList', label: 'Numbered list', Icon: ListNumbers, toggle: (chain) => chain.toggleOrderedList() },
];

function ToolbarButton({ active, onClick, label, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={label}
            aria-label={label}
            aria-pressed={active}
            className={`rich-text-editor__tool${active ? ' rich-text-editor__tool--active' : ''}`}
        >
            {children}
        </button>
    );
}

// A toolbar button that opens a small menu of options (headings, lists).
// The trigger shows the active option's icon, or `Icon` when none is.
function ToolbarMenu({ label, Icon, active, options }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        function close(e) {
            if (e.type === 'keydown' ? e.key === 'Escape' : !ref.current?.contains(e.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    const current = options.find((o) => o.active);
    const TriggerIcon = current?.Icon ?? Icon;

    return (
        <div className="rich-text-editor__menu" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                title={label}
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={open}
                className={`rich-text-editor__tool rich-text-editor__tool--menu${active ? ' rich-text-editor__tool--active' : ''}`}
            >
                <TriggerIcon size={ICON_SIZE} />
                <CaretDown size={10} weight="bold" />
            </button>
            {open && (
                <div className="popover rich-text-editor__options" role="menu">
                    {options.map(({ label: optionLabel, Icon: OptionIcon, active: optionActive, onSelect }) => (
                        <button
                            key={optionLabel}
                            type="button"
                            role="menuitem"
                            onClick={() => {
                                onSelect();
                                setOpen(false);
                            }}
                            className={`rich-text-editor__option${optionActive ? ' rich-text-editor__option--active' : ''}`}
                        >
                            <OptionIcon size={ICON_SIZE} />
                            {optionLabel}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

// `compact` is for short fields (e.g. proposal line items): a shorter
// input and no heading tools.
export default function RichTextEditor({ value, onChange, compact = false }) {
    const editor = useEditor({
        extensions: [StarterKit],
        content: value || '',
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: {
            attributes: {
                class: `prose rich-text-editor__input${compact ? ' rich-text-editor__input--compact' : ''}`,
            },
        },
    });

    // Tiptap v3 doesn't re-render on every transaction, so the toolbar
    // subscribes to the formatting at the cursor -- otherwise moving the
    // cursor into bold text wouldn't light up Bold until the next edit.
    const state = useEditorState({
        editor,
        selector: ({ editor }) => editor && {
            bold: editor.isActive('bold'),
            italic: editor.isActive('italic'),
            strike: editor.isActive('strike'),
            underline: editor.isActive('underline'),
            heading: HEADINGS.map(({ level }) => editor.isActive('heading', { level })),
            list: LISTS.map(({ type }) => editor.isActive(type)),
        },
    });

    // Tiptap's `content` option only seeds the editor on mount, so it never
    // sees later changes to `value` on its own -- needed when a parent
    // resets the field (e.g. clearing the composer after a successful
    // submit). Only resync when the incoming value actually differs from
    // what's already in the editor, so normal typing (which flows the same
    // value right back through onChange) doesn't fight the cursor.
    useEffect(() => {
        if (!editor) return;
        const current = editor.getHTML();
        const next = value || '';
        if (next !== current) {
            editor.commands.setContent(next, { emitUpdate: false });
        }
    }, [value, editor]);

    if (!editor || !state) return null;

    const chain = () => editor.chain().focus();

    return (
        <div className="rich-text-editor">
            <div className="rich-text-editor__toolbar">
                <div className="rich-text-editor__group">
                    {!compact && (
                        <ToolbarMenu
                            label="Text style"
                            Icon={TextH}
                            active={state.heading.some(Boolean)}
                            options={[
                                { label: 'Paragraph', Icon: Paragraph, active: !state.heading.some(Boolean), onSelect: () => chain().setParagraph().run() },
                                ...HEADINGS.map(({ level, label, Icon }, i) => ({
                                    label,
                                    Icon,
                                    active: state.heading[i],
                                    onSelect: () => chain().toggleHeading({ level }).run(),
                                })),
                            ]}
                        />
                    )}
                    <ToolbarMenu
                        label="List"
                        Icon={ListBullets}
                        active={state.list.some(Boolean)}
                        options={LISTS.map(({ label, Icon, toggle }, i) => ({
                            label,
                            Icon,
                            active: state.list[i],
                            onSelect: () => toggle(chain()).run(),
                        }))}
                    />
                </div>
                <div className="rich-text-editor__group">
                    <ToolbarButton label="Bold" active={state.bold} onClick={() => chain().toggleBold().run()}>
                        <TextB size={ICON_SIZE} weight="bold" />
                    </ToolbarButton>
                    <ToolbarButton label="Italic" active={state.italic} onClick={() => chain().toggleItalic().run()}>
                        <TextItalic size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton label="Strikethrough" active={state.strike} onClick={() => chain().toggleStrike().run()}>
                        <TextStrikethrough size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton label="Underline" active={state.underline} onClick={() => chain().toggleUnderline().run()}>
                        <TextUnderline size={ICON_SIZE} />
                    </ToolbarButton>
                </div>
            </div>
            <EditorContent editor={editor} className="rich-text-editor__content" />
        </div>
    );
}

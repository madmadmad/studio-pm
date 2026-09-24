import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect } from 'react';

function ToolbarButton({ active, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rich-text-editor__tool${active ? ' rich-text-editor__tool--active' : ''}`}
        >
            {children}
        </button>
    );
}

export default function RichTextEditor({ value, onChange }) {
    const editor = useEditor({
        extensions: [StarterKit],
        content: value || '',
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: {
            attributes: {
                class: 'prose rich-text-editor__input',
            },
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

    if (!editor) return null;

    return (
        <div className="rich-text-editor">
            <div className="rich-text-editor__toolbar">
                <ToolbarButton active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>Bold</ToolbarButton>
                <ToolbarButton active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>Italic</ToolbarButton>
                <ToolbarButton active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>H2</ToolbarButton>
                <ToolbarButton active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>H3</ToolbarButton>
                <ToolbarButton active={editor.isActive('heading', { level: 4 })} onClick={() => editor.chain().focus().toggleHeading({ level: 4 }).run()}>H4</ToolbarButton>
                <ToolbarButton active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>List</ToolbarButton>
                <ToolbarButton active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>1. List</ToolbarButton>
            </div>
            <EditorContent editor={editor} className="rich-text-editor__content" />
        </div>
    );
}

import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect } from 'react';

function ToolbarButton({ active, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`text-xs font-medium px-2 py-1 rounded ${active ? 'bg-ink text-white' : 'text-sage hover:bg-paper'}`}
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
                class: 'proposal-body text-sm min-h-[160px] px-3 py-2 focus:outline-none',
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
        <div className="border border-border rounded overflow-hidden">
            <div className="flex gap-1 border-b border-border px-2 py-1 bg-paper">
                <ToolbarButton active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>Bold</ToolbarButton>
                <ToolbarButton active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>Italic</ToolbarButton>
                <ToolbarButton active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>H2</ToolbarButton>
                <ToolbarButton active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>H3</ToolbarButton>
                <ToolbarButton active={editor.isActive('heading', { level: 4 })} onClick={() => editor.chain().focus().toggleHeading({ level: 4 }).run()}>H4</ToolbarButton>
                <ToolbarButton active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>List</ToolbarButton>
                <ToolbarButton active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>1. List</ToolbarButton>
            </div>
            <EditorContent editor={editor} className="bg-white" />
        </div>
    );
}

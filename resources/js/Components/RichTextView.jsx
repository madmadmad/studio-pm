import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect } from 'react';

// Read-only rendering of rich text saved by RichTextEditor. Rendered
// through a non-editable Tiptap instance rather than
// dangerouslySetInnerHTML, so the HTML is parsed against the editor's
// schema: only formatting the editor itself can produce survives -- no
// scripts, event handlers or stray markup from a hand-crafted body.
export default function RichTextView({ value }) {
    const editor = useEditor({
        extensions: [StarterKit],
        content: value || '',
        editable: false,
        editorProps: {
            attributes: {
                class: 'prose',
            },
        },
    });

    // Same resync as RichTextEditor: `content` only seeds on mount.
    useEffect(() => {
        if (!editor) return;
        const next = value || '';
        if (next !== editor.getHTML()) {
            editor.commands.setContent(next, { emitUpdate: false });
        }
    }, [value, editor]);

    if (!editor) return null;

    return <EditorContent editor={editor} />;
}

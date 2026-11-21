"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { EditorContent, useEditor } from "@tiptap/react";
import Link from "@tiptap/extension-link";
import Highlight from "@tiptap/extension-highlight";
import StarterKit from "@tiptap/starter-kit";
import type { TipTapDocument } from "@/lib/learning";
import styles from "./rich-text-editor.module.css";

interface RichTextEditorProps {
  initialDocument: TipTapDocument;
  isSubmitting: boolean;
  saveLabel: string;
  onCancel: () => boolean | void;
  onDirtyChange: (dirty: boolean) => void;
  onSave: (document: TipTapDocument) => Promise<void> | void;
}

const EMPTY_DOCUMENT: TipTapDocument = {
  type: "doc",
  content: [{ type: "paragraph" }],
};

const NODE_ATTRIBUTES: Record<string, string[]> = {
  doc: [],
  paragraph: [],
  heading: ["level"],
  blockquote: [],
  bulletList: [],
  orderedList: ["start", "type"],
  listItem: [],
  text: [],
  hardBreak: [],
  codeBlock: ["language"],
  horizontalRule: [],
};

const MARK_ATTRIBUTES: Record<string, string[]> = {
  bold: [],
  italic: [],
  underline: [],
  strike: [],
  code: [],
  highlight: ["color"],
  link: ["href", "target", "rel", "class", "title"],
};

function validateDocumentShape(value: unknown, depth = 0): string | null {
  if (depth > 20) {
    return "This document is nested too deeply to edit safely.";
  }

  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return "This text block does not contain a valid Tiptap document.";
  }

  const node = value as Record<string, unknown>;
  const type = node.type;
  if (typeof type !== "string" || !Object.hasOwn(NODE_ATTRIBUTES, type)) {
    return "This text block uses a node the editor does not support. Its content was left untouched.";
  }

  if (type === "doc" && depth !== 0) {
    return "This text block contains a nested document the editor cannot preserve.";
  }

  if (
    type === "doc" &&
    (!Array.isArray(node.content) || node.content.length === 0)
  ) {
    return "This text block has no content to edit.";
  }

  const allowedKeys = new Set(["type", "attrs", "content", "text", "marks"]);
  if (Object.keys(node).some((key) => !allowedKeys.has(key))) {
    return "This text block contains document data the editor cannot round-trip safely.";
  }

  if (node.attrs !== undefined) {
    if (
      typeof node.attrs !== "object" ||
      node.attrs === null ||
      Array.isArray(node.attrs)
    ) {
      return "This text block contains unsupported node attributes.";
    }

    const attrs = node.attrs as Record<string, unknown>;
    const allowedAttributes = NODE_ATTRIBUTES[type];
    if (Object.keys(attrs).some((key) => !allowedAttributes.includes(key))) {
      return "This text block contains node attributes the editor cannot preserve.";
    }

    if (
      type === "heading"
      && (!Number.isInteger(attrs.level) || ![1, 2, 3, 4, 5, 6].includes(Number(attrs.level)))
    ) {
      return "This text block contains an unsupported heading level.";
    }

    if (
      type === "orderedList"
      && attrs.start !== undefined
      && (!Number.isInteger(attrs.start) || Number(attrs.start) < 1)
    ) {
      return "This text block contains an unsupported list start value.";
    }

    if (
      type === "orderedList"
      && attrs.type !== undefined
      && attrs.type !== null
      && (
        typeof attrs.type !== "string"
        || !["1", "a", "A", "i", "I"].includes(attrs.type)
      )
    ) {
      return "This text block contains an unsupported ordered list style.";
    }

    if (
      type === "codeBlock"
      && attrs.language !== undefined
      && attrs.language !== null
      && typeof attrs.language !== "string"
    ) {
      return "This text block contains an unsupported code language.";
    }
  }

  if (type === "text") {
    if (typeof node.text !== "string" || node.content !== undefined) {
      return "This text block contains an invalid text node.";
    }
  } else if (node.text !== undefined || node.marks !== undefined) {
    return "This text block contains text data in an unsupported location.";
  }

  const contentNodeTypes = new Set([
    "doc",
    "paragraph",
    "heading",
    "blockquote",
    "bulletList",
    "orderedList",
    "listItem",
    "codeBlock",
  ]);
  if (!contentNodeTypes.has(type) && node.content !== undefined) {
    return "This text block contains children on a node that cannot preserve them.";
  }

  if (node.content !== undefined) {
    if (!Array.isArray(node.content)) {
      return "This text block contains invalid nested content.";
    }

    for (const child of node.content) {
      const error = validateDocumentShape(child, depth + 1);
      if (error) return error;
    }
  }

  if (node.marks !== undefined) {
    if (!Array.isArray(node.marks)) {
      return "This text block contains invalid text formatting.";
    }

    for (const markValue of node.marks) {
      if (
        typeof markValue !== "object" ||
        markValue === null ||
        Array.isArray(markValue)
      ) {
        return "This text block contains invalid text formatting.";
      }

      const mark = markValue as Record<string, unknown>;
      const markType = mark.type;
      if (typeof markType !== "string" || !Object.hasOwn(MARK_ATTRIBUTES, markType)) {
        return "This text block uses formatting the editor does not support. Its content was left untouched.";
      }

      if (Object.keys(mark).some((key) => !["type", "attrs"].includes(key))) {
        return "This text block contains formatting data the editor cannot round-trip safely.";
      }

      if (markType === "link" && mark.attrs === undefined) {
        return "This text block contains a link without a destination.";
      }

      if (mark.attrs !== undefined) {
        if (
          typeof mark.attrs !== "object" ||
          mark.attrs === null ||
          Array.isArray(mark.attrs)
        ) {
          return "This text block contains unsupported formatting attributes.";
        }

        const attrs = mark.attrs as Record<string, unknown>;
        if (Object.keys(attrs).some((key) => !MARK_ATTRIBUTES[markType].includes(key))) {
          return "This text block contains formatting attributes the editor cannot preserve.";
        }

        if (
          markType === "link" &&
          (typeof attrs.href !== "string" ||
            !/^(https?:\/\/|mailto:)/i.test(attrs.href))
        ) {
          return "This text block contains a link format the editor cannot preserve.";
        }

        if (
          Object.values(attrs).some((attribute) =>
            attribute !== null && typeof attribute !== "string"
          )
        ) {
          return "This text block contains formatting values the editor cannot preserve.";
        }
      }
    }
  }

  return null;
}

function toolbarButtonClass(active: boolean, disabled = false): string {
  return [
    "min-h-9 rounded-lg border px-2.5 text-xs font-semibold transition",
    active
      ? "border-teal-200 bg-teal-50 text-teal-800"
      : "border-transparent text-slate-600 hover:border-slate-200 hover:bg-slate-50",
    disabled ? "cursor-not-allowed opacity-40" : "",
  ].join(" ");
}

export default function RichTextEditor({
  initialDocument,
  isSubmitting,
  saveLabel,
  onCancel,
  onDirtyChange,
  onSave,
}: RichTextEditorProps) {
  const [validationError, setValidationError] = useState<string | null>(null);
  const [isSavePending, setIsSavePending] = useState(false);
  const dirtyRef = useRef(false);
  const isEditorSaving = isSubmitting || isSavePending;
  const compatibilityError = validateDocumentShape(initialDocument);
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3, 4, 5, 6] },
        link: false,
      }),
      Link.extend({
        addAttributes() {
          return {
            ...(this.parent?.() ?? {}),
            title: { default: null },
          };
        },
      }).configure({
        autolink: false,
        linkOnPaste: false,
        openOnClick: false,
        protocols: ["http", "https", "mailto"],
        isAllowedUri: (url, context) =>
          /^(https?:\/\/|mailto:)/i.test(url) &&
          context.defaultValidate(url),
      }),
      Highlight.configure({ multicolor: true }),
    ],
    content: compatibilityError ? EMPTY_DOCUMENT : initialDocument,
    immediatelyRender: false,
    editorProps: {
      attributes: {
        class: styles.proseMirror,
      },
    },
    onUpdate: () => {
      setValidationError(null);
      if (!dirtyRef.current) {
        dirtyRef.current = true;
        onDirtyChange(true);
      }
    },
  });

  useEffect(() => {
    editor?.setEditable(!isEditorSaving);
  }, [editor, isEditorSaving]);

  const handleCancel = useCallback(() => {
    if (onCancel() === false) return;

    dirtyRef.current = false;
    onDirtyChange(false);
  }, [onCancel, onDirtyChange]);

  const handleSave = useCallback(async () => {
    if (!editor || compatibilityError || isEditorSaving) return;

    if (editor.isEmpty) {
      setValidationError("Add some text before saving this block.");
      return;
    }

    setIsSavePending(true);
    editor.setEditable(false);
    try {
      await onSave(editor.getJSON() as TipTapDocument);
    } catch (error: unknown) {
      setValidationError(
        error instanceof Error ? error.message : "Failed to save this text block."
      );
    } finally {
      setIsSavePending(false);
    }
  }, [compatibilityError, editor, isEditorSaving, onSave]);

  const applyLink = useCallback(() => {
    if (!editor || isEditorSaving) return;

    const currentHref = editor.getAttributes("link").href as string | undefined;
    const input = window.prompt("Link URL (https://, http://, or mailto:)", currentHref ?? "");
    if (input === null) return;

    const href = input.trim();
    if (href === "") {
      editor.chain().focus().unsetLink().run();
      return;
    }

    if (!/^(https?:\/\/|mailto:)/i.test(href)) {
      setValidationError("Links must begin with http://, https://, or mailto:.");
      return;
    }

    setValidationError(null);
    editor.chain().focus().setLink({ href }).run();
  }, [editor, isEditorSaving]);

  if (!editor) {
    return (
      <div className="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-xs text-slate-500">
        Preparing rich text editor…
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      {compatibilityError ? (
        <div role="alert" className="p-4 text-sm text-amber-800">
          {compatibilityError}
        </div>
      ) : (
        <>
          <div
            aria-label="Text formatting"
            className="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 p-2"
            role="toolbar"
          >
            {[1, 2, 3].map((level) => (
              <button
                key={level}
                type="button"
                aria-label={`Heading ${level}`}
                aria-pressed={editor.isActive("heading", { level })}
                disabled={isEditorSaving}
                className={toolbarButtonClass(editor.isActive("heading", { level }), isEditorSaving)}
                onClick={() => editor.chain().focus().toggleHeading({ level: level as 1 | 2 | 3 }).run()}
              >
                H{level}
              </button>
            ))}
            <span aria-hidden="true" className="mx-1 h-5 border-l border-slate-200" />
            <button
              type="button"
              aria-label="Bold"
              aria-pressed={editor.isActive("bold")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("bold"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleBold().run()}
            >
              <strong>B</strong>
            </button>
            <button
              type="button"
              aria-label="Italic"
              aria-pressed={editor.isActive("italic")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("italic"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleItalic().run()}
            >
              <em>I</em>
            </button>
            <button
              type="button"
              aria-label="Underline"
              aria-pressed={editor.isActive("underline")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("underline"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleUnderline().run()}
            >
              <u>U</u>
            </button>
            <button
              type="button"
              aria-label="Highlight"
              aria-pressed={editor.isActive("highlight")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("highlight"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleHighlight().run()}
            >
              <span className="bg-yellow-200 px-0.5">Mark</span>
            </button>
            <button
              type="button"
              aria-label="Insert link"
              aria-pressed={editor.isActive("link")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("link"), isEditorSaving)}
              onClick={applyLink}
            >
              Link
            </button>
            <span aria-hidden="true" className="mx-1 h-5 border-l border-slate-200" />
            <button
              type="button"
              aria-label="Bulleted list"
              aria-pressed={editor.isActive("bulletList")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("bulletList"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleBulletList().run()}
            >
              • List
            </button>
            <button
              type="button"
              aria-label="Numbered list"
              aria-pressed={editor.isActive("orderedList")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("orderedList"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleOrderedList().run()}
            >
              1. List
            </button>
            <button
              type="button"
              aria-label="Block quote"
              aria-pressed={editor.isActive("blockquote")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("blockquote"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleBlockquote().run()}
            >
              Quote
            </button>
            <button
              type="button"
              aria-label="Code block"
              aria-pressed={editor.isActive("codeBlock")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(editor.isActive("codeBlock"), isEditorSaving)}
              onClick={() => editor.chain().focus().toggleCodeBlock().run()}
            >
              Code
            </button>
            <span aria-hidden="true" className="mx-1 h-5 border-l border-slate-200" />
            <button
              type="button"
              aria-label="Undo"
              disabled={isEditorSaving || !editor.can().undo()}
              className={toolbarButtonClass(false, isEditorSaving || !editor.can().undo())}
              onClick={() => editor.chain().focus().undo().run()}
            >
              Undo
            </button>
            <button
              type="button"
              aria-label="Redo"
              disabled={isEditorSaving || !editor.can().redo()}
              className={toolbarButtonClass(false, isEditorSaving || !editor.can().redo())}
              onClick={() => editor.chain().focus().redo().run()}
            >
              Redo
            </button>
          </div>

          <div className={styles.surface}>
            <EditorContent editor={editor} />
          </div>
        </>
      )}

      {validationError && (
        <p role="alert" className="border-t border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-700">
          {validationError}
        </p>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-white px-3 py-3">
        <p className="text-[11px] text-slate-500">Formatting is saved as structured lesson content.</p>
        <div className="flex gap-2">
          <button
            type="button"
            disabled={isEditorSaving}
            onClick={handleCancel}
            className="min-h-10 rounded-lg border border-slate-200 px-4 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={isEditorSaving || Boolean(compatibilityError)}
            onClick={handleSave}
            className="min-h-10 rounded-lg bg-[#0e8988] px-4 text-xs font-bold text-white hover:bg-[#087b83] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {isEditorSaving ? "Saving…" : saveLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

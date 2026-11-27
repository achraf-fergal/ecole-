"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { EditorContent, useEditor } from "@tiptap/react";
import Image from "@tiptap/extension-image";
import Link from "@tiptap/extension-link";
import Highlight from "@tiptap/extension-highlight";
import StarterKit from "@tiptap/starter-kit";
import {
  getLessonAssetAccess,
  type LearningLessonAsset,
  type LearningRouteParams,
  type TipTapDocument,
} from "@/lib/learning";
import { uploadAdminLessonAsset } from "@/lib/admin-learning";
import styles from "./rich-text-editor.module.css";

interface RichTextEditorProps {
  initialDocument: TipTapDocument;
  isSubmitting: boolean;
  saveLabel: string;
  onCancel: () => boolean | void;
  onDirtyChange: (dirty: boolean) => void;
  onSave: (document: TipTapDocument) => Promise<void> | void;
  routeParams?: LearningRouteParams;
  imageAssets?: LearningLessonAsset[];
  onImageAssetUploaded?: (asset: LearningLessonAsset) => void;
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
  image: ["src", "alt", "title", "assetId"],
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

const BLOCK_NODE_TYPES = new Set([
  "paragraph",
  "heading",
  "blockquote",
  "bulletList",
  "orderedList",
  "codeBlock",
  "horizontalRule",
  "image",
]);

function isValidChildNode(
  parentType: string,
  childType: string,
  childIndex: number,
): boolean {
  switch (parentType) {
    case "doc":
    case "blockquote":
      return BLOCK_NODE_TYPES.has(childType);
    case "paragraph":
    case "heading":
      return childType === "text" || childType === "hardBreak";
    case "bulletList":
    case "orderedList":
      return childType === "listItem";
    case "listItem":
      return childIndex === 0
        ? childType === "paragraph"
        : BLOCK_NODE_TYPES.has(childType);
    case "codeBlock":
      return childType === "text";
    default:
      return false;
  }
}

function validateDocumentShape(
  value: unknown,
  depth = 0,
  parentType?: string,
  childIndex = 0,
): string | null {
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

  if (depth === 0 && type !== "doc") {
    return "This text block does not contain a valid Tiptap document.";
  }

  if (type === "doc" && depth !== 0) {
    return "This text block contains a nested document the editor cannot preserve.";
  }

  if (
    parentType !== undefined &&
    !isValidChildNode(parentType, type, childIndex)
  ) {
    return "This text block contains nodes in an unsupported structure.";
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
      && attrs.level !== undefined
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

    if (type === "image") {
      if (
        attrs.src !== undefined &&
        attrs.src !== null &&
        typeof attrs.src !== "string"
      ) {
        return "This text block contains an invalid image source.";
      }
      if (
        attrs.alt !== undefined &&
        attrs.alt !== null &&
        typeof attrs.alt !== "string"
      ) {
        return "This text block contains an invalid image alt text.";
      }
      if (
        attrs.title !== undefined &&
        attrs.title !== null &&
        typeof attrs.title !== "string"
      ) {
        return "This text block contains an invalid image title.";
      }
      if (
        attrs.assetId !== undefined &&
        attrs.assetId !== null &&
        (!Number.isInteger(attrs.assetId) || Number(attrs.assetId) < 1)
      ) {
        return "This text block contains an invalid image asset reference.";
      }
    }
  }

  if (type === "text") {
    if (
      typeof node.text !== "string" ||
      node.text.length === 0 ||
      Object.hasOwn(node, "content")
    ) {
      return "This text block contains an invalid text node.";
    }
  } else if (Object.hasOwn(node, "text")) {
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
  const hasContent = Object.hasOwn(node, "content");
  let children: unknown[] = [];

  if (hasContent) {
    if (!contentNodeTypes.has(type) || !Array.isArray(node.content)) {
      return "This text block contains children on a node that cannot preserve them.";
    }

    children = node.content;
  }

  if (
    ["blockquote", "bulletList", "orderedList", "listItem"].includes(type) &&
    children.length === 0
  ) {
    return "This text block contains a node that requires child content.";
  }

  if (
    type === "listItem" &&
    (typeof children[0] !== "object" ||
      children[0] === null ||
      Array.isArray(children[0]) ||
      (children[0] as Record<string, unknown>).type !== "paragraph")
  ) {
    return "Each list item must begin with a paragraph.";
  }

  if (
    parentType === "codeBlock" &&
    Array.isArray(node.marks) &&
    node.marks.length > 0
  ) {
    return "Code blocks cannot contain marked text.";
  }

  if (hasContent) {
    for (const [index, child] of children.entries()) {
      const error = validateDocumentShape(child, depth + 1, type, index);
      if (error) return error;
    }
  }

  if (Object.hasOwn(node, "marks")) {
    if (
      !["text", "hardBreak"].includes(type) ||
      !Array.isArray(node.marks)
    ) {
      return "This text block contains formatting in an unsupported location.";
    }

    const markTypes: string[] = [];
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

      markTypes.push(markType);
    }

    if (
      new Set(markTypes).size !== markTypes.length ||
      (markTypes.includes("code") && markTypes.length > 1)
    ) {
      return "This text block combines formatting that the editor does not support.";
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

const LessonImageExtension = Image.extend({
  addAttributes() {
    return {
      ...(this.parent?.() ?? {}),
      assetId: {
        default: null,
        parseHTML: (element) => {
          const raw = element.getAttribute("data-asset-id");
          if (!raw) return null;
          const parsed = Number(raw);
          return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
        },
        renderHTML: (attributes) => {
          if (!attributes.assetId) return {};
          return { "data-asset-id": String(attributes.assetId) };
        },
      },
    };
  },
}).configure({
  inline: false,
  allowBase64: false,
});

function hasImageNode(doc: TipTapDocument): boolean {
  const stack = [...(doc.content ?? [])];
  while (stack.length > 0) {
    const node = stack.pop();
    if (!node) continue;
    if (node.type === "image") return true;
    if (Array.isArray(node.content)) {
      stack.push(...node.content);
    }
  }
  return false;
}

export default function RichTextEditor({
  initialDocument,
  isSubmitting,
  saveLabel,
  onCancel,
  onDirtyChange,
  onSave,
  routeParams,
  imageAssets = [],
  onImageAssetUploaded,
}: RichTextEditorProps) {
  const [validationError, setValidationError] = useState<string | null>(null);
  const [isSavePending, setIsSavePending] = useState(false);
  const [isImagePanelOpen, setIsImagePanelOpen] = useState(false);
  const [imageUrlInput, setImageUrlInput] = useState("");
  const [imageAltInput, setImageAltInput] = useState("");
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [isInsertingImage, setIsInsertingImage] = useState(false);
  const [imagePanelError, setImagePanelError] = useState<string | null>(null);
  const dirtyRef = useRef(false);
  const isHydratingImagesRef = useRef(false);
  const isEditorSaving = isSubmitting || isSavePending;
  const compatibilityError = validateDocumentShape(initialDocument);
  const activeImageAssets = imageAssets.filter(
    (asset) => asset.asset_type === "image" && asset.status === "active"
  );
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
      LessonImageExtension,
    ],
    content: compatibilityError ? EMPTY_DOCUMENT : initialDocument,
    immediatelyRender: false,
    editorProps: {
      attributes: {
        class: styles.proseMirror,
      },
    },
    onUpdate: () => {
      if (isHydratingImagesRef.current) return;
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

  useEffect(() => {
    if (!editor || !routeParams) return;

    const assetNodes: Array<{ pos: number; assetId: number }> = [];
    editor.state.doc.descendants((node, pos) => {
      if (node.type.name === "image" && typeof node.attrs.assetId === "number") {
        assetNodes.push({ pos, assetId: node.attrs.assetId });
      }
    });

    if (assetNodes.length === 0) return;

    let cancelled = false;
    const uniqueAssetIds = Array.from(new Set(assetNodes.map((item) => item.assetId)));

    Promise.all(
      uniqueAssetIds.map(async (assetId) => {
        try {
          const access = await getLessonAssetAccess(routeParams, assetId);
          if ("url" in access && typeof access.url === "string") {
            return [assetId, access.url] as const;
          }
        } catch {
          // Leave existing src intact if refreshing fails.
        }
        return null;
      })
    ).then((results) => {
      if (cancelled || editor.isDestroyed) return;
      const urlMap = new Map<number, string>();
      for (const entry of results) {
        if (entry) urlMap.set(entry[0], entry[1]);
      }
      if (urlMap.size === 0) return;

      const tr = editor.state.tr;
      let changed = false;
      editor.state.doc.descendants((node, pos) => {
        if (node.type.name === "image" && typeof node.attrs.assetId === "number") {
          const freshUrl = urlMap.get(node.attrs.assetId);
          if (freshUrl && node.attrs.src !== freshUrl) {
            tr.setNodeMarkup(pos, undefined, { ...node.attrs, src: freshUrl });
            changed = true;
          }
        }
      });

      if (changed) {
        tr.setMeta("addToHistory", false);
        isHydratingImagesRef.current = true;
        try {
          editor.view.dispatch(tr);
        } finally {
          isHydratingImagesRef.current = false;
        }
      }
    });

    return () => {
      cancelled = true;
    };
  }, [editor, routeParams]);

  const handleCancel = useCallback(() => {
    if (onCancel() === false) return;

    dirtyRef.current = false;
    onDirtyChange(false);
  }, [onCancel, onDirtyChange]);

  const handleSave = useCallback(async () => {
    if (!editor || compatibilityError || isEditorSaving) return;

    const json = editor.getJSON() as TipTapDocument;
    if (editor.isEmpty && !hasImageNode(json)) {
      setValidationError("Add some text or an image before saving this block.");
      return;
    }

    setIsSavePending(true);
    editor.setEditable(false);
    try {
      await onSave(json);
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

  const insertImageNode = useCallback(
    (attrs: { src: string; alt?: string; title?: string; assetId?: number }) => {
      if (!editor) return;
      editor
        .chain()
        .focus()
        .setImage(attrs as { src: string; alt?: string; title?: string })
        .run();
      setIsImagePanelOpen(false);
      setImageUrlInput("");
      setImageAltInput("");
      setImageFile(null);
      setImagePanelError(null);
    },
    [editor]
  );

  const handleInsertImageUrl = useCallback(() => {
    if (!editor || isEditorSaving || isInsertingImage) return;
    const trimmedUrl = imageUrlInput.trim();
    if (!/^https?:\/\//i.test(trimmedUrl)) {
      setImagePanelError("Image URL must begin with http:// or https://.");
      return;
    }
    insertImageNode({
      src: trimmedUrl,
      alt: imageAltInput.trim() || undefined,
    });
  }, [editor, imageAltInput, imageUrlInput, insertImageNode, isEditorSaving, isInsertingImage]);

  const handleInsertExistingImageAsset = useCallback(
    async (asset: LearningLessonAsset) => {
      if (!editor || !routeParams || isEditorSaving || isInsertingImage) return;
      setImagePanelError(null);
      setIsInsertingImage(true);
      try {
        const access = await getLessonAssetAccess(routeParams, asset.id);
        if (!("url" in access) || typeof access.url !== "string") {
          throw new Error("Could not resolve image URL.");
        }
        insertImageNode({
          src: access.url,
          alt: imageAltInput.trim() || asset.alt_text || asset.title || asset.original_filename || undefined,
          title: asset.title || undefined,
          assetId: asset.id,
        });
      } catch (error: unknown) {
        setImagePanelError(
          error instanceof Error ? error.message : "Failed to load selected image."
        );
      } finally {
        setIsInsertingImage(false);
      }
    },
    [editor, imageAltInput, insertImageNode, isEditorSaving, isInsertingImage, routeParams]
  );

  const handleUploadAndInsertImage = useCallback(async () => {
    if (!editor || !routeParams || !imageFile || isEditorSaving || isInsertingImage) return;
    if (routeParams.lessonId === undefined || routeParams.lessonId === null) {
      setImagePanelError("Missing lesson identifier for image upload.");
      return;
    }
    setImagePanelError(null);
    setIsInsertingImage(true);
    try {
      const formData = new FormData();
      formData.append("file", imageFile);
      formData.append("asset_type", "image");
      const alt = imageAltInput.trim();
      if (alt) {
        formData.append("alt_text", alt);
      }
      const uploaded = await uploadAdminLessonAsset(
        routeParams.centerSlug,
        routeParams.courseSlug,
        routeParams.lessonId,
        formData
      );
      onImageAssetUploaded?.(uploaded);
      const access = await getLessonAssetAccess(routeParams, uploaded.id);
      if (!("url" in access) || typeof access.url !== "string") {
        throw new Error("Image uploaded, but failed to generate preview URL.");
      }
      insertImageNode({
        src: access.url,
        alt: alt || uploaded.alt_text || uploaded.title || uploaded.original_filename || undefined,
        title: uploaded.title || undefined,
        assetId: uploaded.id,
      });
    } catch (error: unknown) {
      setImagePanelError(
        error instanceof Error ? error.message : "Failed to upload image."
      );
    } finally {
      setIsInsertingImage(false);
    }
  }, [
    editor,
    imageAltInput,
    imageFile,
    insertImageNode,
    isEditorSaving,
    isInsertingImage,
    onImageAssetUploaded,
    routeParams,
  ]);

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
            {[1, 2, 3, 4, 5, 6].map((level) => (
              <button
                key={level}
                type="button"
                aria-label={`Heading ${level}`}
                aria-pressed={editor.isActive("heading", { level })}
                disabled={isEditorSaving}
                className={toolbarButtonClass(editor.isActive("heading", { level }), isEditorSaving)}
                onClick={() => editor.chain().focus().toggleHeading({ level: level as 1 | 2 | 3 | 4 | 5 | 6 }).run()}
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
            <button
              type="button"
              aria-label="Insert image"
              aria-pressed={isImagePanelOpen || editor.isActive("image")}
              disabled={isEditorSaving}
              className={toolbarButtonClass(isImagePanelOpen || editor.isActive("image"), isEditorSaving)}
              onClick={() => {
                setImagePanelError(null);
                setIsImagePanelOpen((prev) => !prev);
              }}
            >
              Image
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

          {isImagePanelOpen && (
            <div className="border-t border-slate-200 bg-slate-50/90 p-3 space-y-3">
              <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-bold text-[#102b4c]">Insert Image Inline</p>
                <button
                  type="button"
                  onClick={() => {
                    setIsImagePanelOpen(false);
                    setImagePanelError(null);
                  }}
                  className="text-xs font-semibold text-slate-500 hover:text-slate-700"
                >
                  Close
                </button>
              </div>

              <div>
                <label className="block text-[11px] font-semibold text-slate-600">
                  Alt text (optional)
                  <input
                    type="text"
                    value={imageAltInput}
                    onChange={(e) => setImageAltInput(e.target.value)}
                    placeholder="Describe the image for learners"
                    disabled={isInsertingImage || isEditorSaving}
                    className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-[#0e8988] focus:outline-none"
                  />
                </label>
              </div>

              {routeParams && (
                <div className="rounded-lg border border-slate-200 bg-white p-2.5 space-y-2">
                  <p className="text-[11px] font-bold text-slate-700">Upload New Image</p>
                  <div className="flex flex-wrap items-center gap-2">
                    <input
                      type="file"
                      accept="image/*"
                      disabled={isInsertingImage || isEditorSaving}
                      onChange={(e) => setImageFile(e.target.files?.[0] ?? null)}
                      className="text-xs text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-teal-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-teal-800 hover:file:bg-teal-100"
                    />
                    <button
                      type="button"
                      disabled={!imageFile || isInsertingImage || isEditorSaving}
                      onClick={handleUploadAndInsertImage}
                      className="rounded-lg bg-[#0e8988] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#087b83] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {isInsertingImage ? "Inserting…" : "Upload & Insert"}
                    </button>
                  </div>
                </div>
              )}

              {routeParams && activeImageAssets.length > 0 && (
                <div className="rounded-lg border border-slate-200 bg-white p-2.5 space-y-2">
                  <p className="text-[11px] font-bold text-slate-700">Or Pick from Lesson Images</p>
                  <div className="flex flex-wrap gap-1.5">
                    {activeImageAssets.map((asset) => (
                      <button
                        key={asset.id}
                        type="button"
                        disabled={isInsertingImage || isEditorSaving}
                        onClick={() => handleInsertExistingImageAsset(asset)}
                        className="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-[#0e8988] hover:bg-teal-50 hover:text-teal-900 disabled:opacity-50"
                      >
                        {asset.title || asset.original_filename || `Image #${asset.id}`}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              <div className="rounded-lg border border-slate-200 bg-white p-2.5 space-y-2">
                <p className="text-[11px] font-bold text-slate-700">Or Insert by Image URL</p>
                <div className="flex flex-wrap items-center gap-2">
                  <input
                    type="url"
                    value={imageUrlInput}
                    onChange={(e) => setImageUrlInput(e.target.value)}
                    placeholder="https://example.com/image.png"
                    disabled={isInsertingImage || isEditorSaving}
                    className="min-w-[200px] flex-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-800 focus:border-[#0e8988] focus:outline-none"
                  />
                  <button
                    type="button"
                    disabled={!imageUrlInput.trim() || isInsertingImage || isEditorSaving}
                    onClick={handleInsertImageUrl}
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-[#0e8988] hover:text-[#0e8988] disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    Insert URL
                  </button>
                </div>
              </div>

              {imagePanelError && (
                <p role="alert" className="text-xs font-medium text-rose-700">
                  {imagePanelError}
                </p>
              )}
            </div>
          )}
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

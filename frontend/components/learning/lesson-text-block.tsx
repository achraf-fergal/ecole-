"use client";

import React, { useEffect, useState } from "react";
import {
  getLessonAssetAccess,
  type LearningRouteParams,
  type LessonBlock,
  type TipTapDocument,
  type TipTapMark,
  type TipTapNode,
} from "@/lib/learning";

interface LessonTextBlockProps {
  block: LessonBlock;
  params?: LearningRouteParams;
  locale?: "en" | "fr";
}

function lessonText(locale: "en" | "fr", text: string): string {
  if (locale !== "fr") return text;
  return text === "Image unavailable" ? "Image indisponible" : text;
}

function sanitizeHref(href: unknown): string | null {
  if (typeof href !== "string") return null;
  const trimmed = href.trim();
  if (/^(https?:\/\/|mailto:)/i.test(trimmed)) {
    return trimmed;
  }
  return null;
}

function sanitizeImageSrc(src: unknown): string | null {
  if (typeof src !== "string") return null;
  const trimmed = src.trim();
  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed;
  }
  return null;
}

function InlineLessonImage({
  assetId,
  fallbackSrc,
  alt,
  title,
  params,
  locale,
}: {
  assetId: number | null;
  fallbackSrc: string | null;
  alt: string;
  title?: string;
  params?: LearningRouteParams;
  locale: "en" | "fr";
}) {
  const [fetchedSrc, setFetchedSrc] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!assetId || !params) return;

    let cancelled = false;
    getLessonAssetAccess(params, assetId)
      .then((access) => {
        if (cancelled) return;
        if ("url" in access && typeof access.url === "string") {
          setFetchedSrc(access.url);
        } else if (fallbackSrc) {
          setFetchedSrc(fallbackSrc);
        } else {
          setFailed(true);
        }
      })
      .catch(() => {
        if (!cancelled) {
          if (fallbackSrc) {
            setFetchedSrc(fallbackSrc);
          } else {
            setFailed(true);
          }
        }
      });

    return () => {
      cancelled = true;
    };
  }, [assetId, fallbackSrc, params]);

  const resolvedSrc = assetId && params ? fetchedSrc : fallbackSrc;

  if (failed) {
    return (
      <div className="my-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-center text-xs text-slate-500">
        {lessonText(locale, "Image unavailable")}
      </div>
    );
  }

  if (!resolvedSrc) {
    return (
      <div className="my-4 h-48 w-full animate-pulse rounded-xl bg-slate-100" />
    );
  }

  return (
    <figure className="my-5">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={resolvedSrc}
        alt={alt}
        title={title}
        className="mx-auto max-h-[560px] w-auto max-w-full rounded-xl border border-slate-100 object-contain"
        loading="lazy"
      />
    </figure>
  );
}

function renderTextWithMarks(
  text: string,
  marks?: TipTapMark[],
  key?: string | number
): React.ReactNode {
  if (!marks || marks.length === 0) {
    return <React.Fragment key={key}>{text}</React.Fragment>;
  }

  return marks.reduce<React.ReactNode>((acc, mark, markIndex) => {
    const markKey = `${key}-m-${markIndex}`;

    switch (mark.type) {
      case "bold":
        return <strong key={markKey} className="font-bold text-[#102b4c]">{acc}</strong>;

      case "italic":
        return <em key={markKey} className="italic">{acc}</em>;

      case "strike":
        return <s key={markKey} className="line-through">{acc}</s>;

      case "code":
        return (
          <code
            key={markKey}
            className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-800"
          >
            {acc}
          </code>
        );

      case "underline":
        return <u key={markKey} className="underline">{acc}</u>;

      case "highlight": {
        const color = mark.attrs?.color;
        const safeColor =
          typeof color === "string" && /^#[\da-f]{3,8}$/i.test(color)
            ? color
            : undefined;

        return (
          <mark
            key={markKey}
            className="rounded bg-yellow-200 px-0.5"
            style={safeColor ? { backgroundColor: safeColor } : undefined}
          >
            {acc}
          </mark>
        );
      }

      case "link": {
        const safeUrl = sanitizeHref(mark.attrs?.href);
        if (!safeUrl) return <span key={markKey}>{acc}</span>;

        return (
          <a
            key={markKey}
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="font-semibold text-[#0e8988] underline transition hover:text-[#0a6665]"
          >
            {acc}
          </a>
        );
      }

      default:
        return acc;
    }
  }, text);
}

function renderNode(
  node: TipTapNode,
  index: number,
  params: LearningRouteParams | undefined,
  locale: "en" | "fr"
): React.ReactNode {
  const key = `node-${index}`;

  switch (node.type) {
    case "paragraph":
      return (
        <p key={key} className="mt-4 text-sm leading-8 text-slate-700 first:mt-0">
          {node.content?.map((child, i) => renderNode(child, i, params, locale)) ?? null}
        </p>
      );

    case "heading": {
      const rawLevel = Number(node.attrs?.level);
      const level = Number.isInteger(rawLevel) && rawLevel >= 1 && rawLevel <= 6
        ? rawLevel
        : 1;
      const children = node.content?.map((child, i) => renderNode(child, i, params, locale));

      if (level === 1) {
        return (
          <h3 key={key} className="mt-8 text-2xl font-black text-[#102b4c] first:mt-0">
            {children}
          </h3>
        );
      }

      if (level === 2) {
        return (
          <h4 key={key} className="mt-6 text-xl font-extrabold text-[#102b4c] first:mt-0">
            {children}
          </h4>
        );
      }

      if (level === 3) {
        return (
          <h5 key={key} className="mt-5 text-lg font-bold text-[#102b4c] first:mt-0">
            {children}
          </h5>
        );
      }

      if (level === 4) {
        return <h6 key={key} className="mt-4 text-base font-bold text-[#102b4c] first:mt-0">{children}</h6>;
      }

      if (level === 5) {
        return (
          <div
            key={key}
            role="heading"
            aria-level={6}
            className="mt-3 text-sm font-bold text-[#102b4c] first:mt-0"
          >
            {children}
          </div>
        );
      }

      return (
        <div
          key={key}
          role="heading"
          aria-level={6}
          className="mt-3 text-xs font-bold uppercase tracking-wider text-[#102b4c] first:mt-0"
        >
          {children}
        </div>
      );
    }

    case "bulletList":
      return (
        <ul key={key} className="mt-4 list-disc space-y-1 pl-6 text-sm leading-7 text-slate-700">
          {node.content?.map((child, i) => renderNode(child, i, params, locale))}
        </ul>
      );

    case "orderedList": {
      const start = Number(node.attrs?.order ?? node.attrs?.start) || 1;
      return (
        <ol
          key={key}
          start={start}
          className="mt-4 list-decimal space-y-1 pl-6 text-sm leading-7 text-slate-700"
        >
          {node.content?.map((child, i) => renderNode(child, i, params, locale))}
        </ol>
      );
    }

    case "listItem":
      return (
        <li key={key} className="leading-7">
          {node.content?.map((child, i) => renderNode(child, i, params, locale))}
        </li>
      );

    case "blockquote":
      return (
        <blockquote
          key={key}
          className="mt-5 border-l-4 border-[#0e8988] bg-slate-50/70 py-2 pl-4 pr-3 italic text-slate-700 rounded-r-xl"
        >
          {node.content?.map((child, i) => renderNode(child, i, params, locale))}
        </blockquote>
      );

    case "codeBlock":
      return (
        <pre
          key={key}
          className="mt-5 overflow-x-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-slate-100"
        >
          <code>
            {node.content?.map((child) => child.text ?? "").join("")}
          </code>
        </pre>
      );

    case "horizontalRule":
      return <hr key={key} className="my-6 border-slate-200" />;

    case "hardBreak":
      return <br key={key} />;

    case "image": {
      const rawAssetId = node.attrs?.assetId;
      const assetId =
        typeof rawAssetId === "number" && Number.isInteger(rawAssetId) && rawAssetId > 0
          ? rawAssetId
          : null;
      const fallbackSrc = sanitizeImageSrc(node.attrs?.src);
      if (!assetId && !fallbackSrc) return null;

      const alt = typeof node.attrs?.alt === "string" ? node.attrs.alt : "";
      const title = typeof node.attrs?.title === "string" ? node.attrs.title : undefined;

      return (
        <InlineLessonImage
          key={key}
          assetId={assetId}
          fallbackSrc={fallbackSrc}
          alt={alt}
          title={title}
          params={params}
          locale={locale}
        />
      );
    }

    case "text":
      return renderTextWithMarks(node.text ?? "", node.marks, key);

    default:
      if (node.content && node.content.length > 0) {
        return (
          <div key={key}>
            {node.content.map((child, i) => renderNode(child, i, params, locale))}
          </div>
        );
      }
      return null;
  }
}

export default function LessonTextBlock({ block, params, locale = "en" }: LessonTextBlockProps) {
  const content = block.content_json as TipTapDocument | null;

  if (!content || !Array.isArray(content.content) || content.content.length === 0) {
    return null;
  }

  return (
    <article className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-7 shadow-xs">
      <div className="prose prose-slate max-w-none text-slate-700">
        {content.content.map((node, i) => renderNode(node, i, params, locale))}
      </div>
    </article>
  );
}

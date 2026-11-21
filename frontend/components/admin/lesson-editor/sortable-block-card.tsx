"use client";

import type { ReactNode } from "react";
import { useSortable } from "@dnd-kit/react/sortable";

interface SortableBlockCardProps {
  blockId: number;
  index: number;
  blockLabel: string;
  disabled: boolean;
  children: ReactNode;
}

export default function SortableBlockCard({
  blockId,
  index,
  blockLabel,
  disabled,
  children,
}: SortableBlockCardProps) {
  const {
    ref,
    handleRef,
    isDragging,
    isDropTarget,
  } = useSortable({
    id: blockId,
    index,
    disabled,
  });

  return (
    <article
      ref={ref}
      className={`relative flex gap-3 ${
        isDropTarget
          ? "before:absolute before:-top-2 before:left-0 before:right-0 before:h-1 before:rounded-full before:bg-[#0e8988]"
          : ""
      } ${isDragging ? "opacity-55" : ""}`}
      aria-roledescription="sortable lesson block"
    >
      <button
        ref={handleRef}
        type="button"
        disabled={disabled}
        aria-label={`Reorder ${blockLabel}, position ${index + 1}`}
        title="Drag to reorder. Keyboard: press Space, use arrow keys, then Space to drop."
        className="mt-0.5 flex h-11 w-11 shrink-0 touch-none items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-lg text-slate-500 transition hover:border-teal-200 hover:bg-teal-50 hover:text-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:opacity-40"
      >
        <span aria-hidden="true">⠿</span>
      </button>
      <div className="min-w-0 flex-1">{children}</div>
    </article>
  );
}

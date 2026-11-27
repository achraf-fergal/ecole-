import type { ReactNode } from "react";

interface FillBlankRendererProps {
  prompt: string;
  renderBlank: (
    blankId: string,
    blankNumber: number,
    partIndex: number
  ) => ReactNode;
}

/** Render the text and stable [blank_id] placeholders used by fill-in questions. */
export default function FillBlankRenderer({
  prompt,
  renderBlank,
}: FillBlankRendererProps) {
  const parts = prompt.split(/(\[[a-zA-Z0-9_\-]+\])/g);

  return (
    <>
      {parts.map((part, partIndex) => {
        const match = /^\[([a-zA-Z0-9_\-]+)\]$/.exec(part);
        if (!match) return <span key={partIndex}>{part}</span>;

        const blankNumber = parts
          .slice(0, partIndex + 1)
          .filter((previousPart) => /^\[[a-zA-Z0-9_\-]+\]$/.test(previousPart))
          .length;
        return renderBlank(match[1], blankNumber, partIndex);
      })}
    </>
  );
}

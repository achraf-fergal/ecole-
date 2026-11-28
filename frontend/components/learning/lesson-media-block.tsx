"use client";

import React from "react";
import LessonAssetViewer from "./lesson-asset-viewer";
import type {
  LessonBlock,
  LearningRouteParams,
} from "@/lib/learning";

interface LessonMediaBlockProps {
  block: LessonBlock;
  params: LearningRouteParams;
  autoPreviewImages?: boolean;
  autoPreviewVideos?: boolean;
  locale?: "en" | "fr";
}

export default function LessonMediaBlock({
  block,
  params,
  autoPreviewImages = true,
  autoPreviewVideos = true,
  locale = "en",
}: LessonMediaBlockProps) {
  if (!block.asset) {
    return null;
  }

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-xs">
      <LessonAssetViewer
        asset={block.asset}
        params={params}
        autoPreviewImages={autoPreviewImages}
        autoPreviewVideos={autoPreviewVideos}
        locale={locale}
      />
    </div>
  );
}

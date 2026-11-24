"use client";

import { useState } from "react";
import type {
  CloudflareVideoAccess,
  ExternalVideoAccess,
} from "@/lib/learning";

type VideoAccess = CloudflareVideoAccess | ExternalVideoAccess;

interface LessonVideoPlayerProps {
  access: VideoAccess;
  title: string;
  caption: string | null;
}

function isTrustedEmbedUrl(access: VideoAccess): boolean {
  let url: URL;

  try {
    url = new URL(
      access.provider === "cloudflare_stream"
        ? access.player_url
        : access.embed_url
    );
  } catch {
    return false;
  }

  if (url.protocol !== "https:" || url.username || url.password) {
    return false;
  }

  if (access.provider === "cloudflare_stream") {
    return /^customer-[a-z0-9-]+\.cloudflarestream\.com$/i.test(url.hostname)
      && /^\/[A-Za-z0-9_.-]+\/iframe$/.test(url.pathname);
  }

  if (access.provider === "youtube") {
    return url.hostname === "www.youtube-nocookie.com"
      && /^\/embed\/[A-Za-z0-9_-]{11}$/.test(url.pathname);
  }

  return access.provider === "google_drive"
    && url.hostname === "drive.google.com"
    && /^\/file\/d\/[A-Za-z0-9_-]{10,200}\/preview$/.test(url.pathname);
}

export default function LessonVideoPlayer({
  access,
  title,
  caption,
}: LessonVideoPlayerProps) {
  const [isLoading, setIsLoading] = useState(true);
  const [hasError, setHasError] = useState(false);

  if (!isTrustedEmbedUrl(access)) {
    return (
      <p role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-700">
        This video provider returned an invalid playback link.
      </p>
    );
  }

  const iframeUrl = access.provider === "cloudflare_stream"
    ? access.player_url
    : access.embed_url;
  const externalVideo = access.provider === "youtube"
    || access.provider === "google_drive";
  const openUrl = externalVideo ? access.open_url : null;

  return (
    <div>
      <div className="relative aspect-video overflow-hidden rounded-xl bg-[#071d36]">
        {isLoading && !hasError && (
          <div
            role="status"
            className="absolute inset-0 z-10 flex items-center justify-center text-sm font-semibold text-white"
          >
            Loading video…
          </div>
        )}

        {hasError ? (
          <div
            role="alert"
            className="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-sm text-white"
          >
            <p>Unable to load the video player.</p>
            {openUrl && (
              <a
                href={openUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="font-bold underline"
              >
                Open on {access.provider === "youtube" ? "YouTube" : "Google Drive"}
              </a>
            )}
          </div>
        ) : (
          <iframe
            src={iframeUrl}
            title={title}
            className="absolute inset-0 h-full w-full"
            loading="lazy"
            referrerPolicy="strict-origin-when-cross-origin"
            sandbox={externalVideo
              ? "allow-forms allow-scripts allow-same-origin allow-presentation allow-popups"
              : undefined}
            allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen"
            allowFullScreen
            onLoad={() => setIsLoading(false)}
            onError={() => {
              setHasError(true);
              setIsLoading(false);
            }}
          />
        )}
      </div>

      {caption && (
        <p className="mt-3 text-sm leading-6 text-slate-600">{caption}</p>
      )}

      {access.provider === "youtube" && (
        <p className="mt-3 text-xs leading-5 text-slate-500">
          Some videos cannot be embedded because of the owner’s settings or YouTube restrictions.
          {openUrl && (
            <> <a href={openUrl} target="_blank" rel="noopener noreferrer" className="font-semibold underline">Open on YouTube</a>.</>
          )}
        </p>
      )}

      {access.provider === "google_drive" && (
        <p className="mt-3 text-xs leading-5 text-slate-500">
          Drive playback depends on the file’s sharing permissions. The owner must grant students access;
          if the preview is unavailable, ask the owner to review sharing settings.
          {openUrl && (
            <> <a href={openUrl} target="_blank" rel="noopener noreferrer" className="font-semibold underline">Open in Google Drive</a>.</>
          )}
        </p>
      )}
    </div>
  );
}

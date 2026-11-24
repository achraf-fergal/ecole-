
"use client";

import { useState } from "react";

import {
  downloadLessonAsset,
  getLessonAssetAccess,
  LearningApiError,
  type CloudflareVideoAccess,
  type ExternalAssetAccess,
  type ExternalVideoAccess,
  type LearningLessonAsset,
  type LearningRouteParams,
  type LessonAssetAccess,
  type StoredAssetAccess,
} from "@/lib/learning";
import LessonVideoPlayer from "@/components/learning/lesson-video-player";

function isStoredAsset(access: LessonAssetAccess): access is StoredAssetAccess {
  return "type" in access && access.type === "stored_asset";
}

function isExternalAsset(access: LessonAssetAccess): access is ExternalAssetAccess {
  return "type" in access && access.type === "external_link";
}

function isCloudflareVideo(access: LessonAssetAccess): access is CloudflareVideoAccess {
  return "provider" in access && access.provider === "cloudflare_stream";
}

function isExternalVideo(access: LessonAssetAccess): access is ExternalVideoAccess {
  return "type" in access && access.type === "external_video";
}

interface LessonAssetViewerProps {
  asset: LearningLessonAsset;
  params: LearningRouteParams;
}

function AssetIcon({ type }: { type: string }) {
  const paths: Record<string, string> = {
    video: "M5 5h14v14H5z M10 9l5 3-5 3z",
    image: "M4 5h16v14H4z M4 15l5-5 4 4 3-3 4 4",
    file: "M6 3h8l4 4v14H6z M14 3v5h5 M9 13h6 M9 17h6",
    external_link: "M14 4h6v6 M20 4l-9 9 M17 13v6H4V6h6",
  };

  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="h-5 w-5"
      aria-hidden="true"
    >
      <path d={paths[type] ?? paths.file} />
    </svg>
  );
}

export default function LessonAssetViewer({
  asset,
  params,
}: LessonAssetViewerProps) {
  const [access, setAccess] = useState<LessonAssetAccess | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const [error, setError] = useState("");

  const isVideo = asset.asset_type === "video";
  const isImage = asset.asset_type === "image";
  const isPdf = asset.mime_type === "application/pdf";
  const isArchived = asset.status === "archived";

  async function handleOpen() {
    if (isArchived || isLoading || access) return;

    setError("");
    setIsLoading(true);

    try {
      const result = await getLessonAssetAccess(
        params,
        asset.id
      );

      setAccess(result);
    } catch (err: unknown) {
      if (
        err instanceof LearningApiError &&
        err.status === 401
      ) {
        setError("Your session has expired. Sign in again.");
      } else {
        setError(
          err instanceof Error
            ? err.message
            : "Unable to open this resource."
        );
      }
    } finally {
      setIsLoading(false);
    }
  }

  async function handleDownload() {
    if (isArchived || !asset.is_downloadable || isDownloading) return;

    setError("");
    setIsDownloading(true);

    try {
      const result = await downloadLessonAsset(
        params,
        asset.id
      );

      const objectUrl = URL.createObjectURL(result.blob);
      const anchor = document.createElement("a");

      anchor.href = objectUrl;
      anchor.download = result.filename;

      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();

      window.setTimeout(() => {
        URL.revokeObjectURL(objectUrl);
      }, 1000);
    } catch (err: unknown) {
      setError(
        err instanceof Error
          ? err.message
          : "Download failed."
      );
    } finally {
      setIsDownloading(false);
    }
  }

  const title =
    asset.title ||
    asset.original_filename ||
    "Learning resource";

  return (
    <article className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#e8f5f3] text-[#0e8988]">
            <AssetIcon type={asset.asset_type} />
          </span>

          <div className="min-w-0">
            <h3 className="truncate text-sm font-extrabold text-[#102b4c]">
              {title}
            </h3>

            <p className="mt-1 text-[11px] capitalize text-slate-500">
              {asset.asset_type.replace("_", " ")}
              {asset.mime_type ? ` · ${asset.mime_type}` : ""}
            </p>
          </div>
        </div>

        <div className="flex shrink-0 flex-wrap items-center gap-2">
          {isArchived ? (
            <span className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
              Archived — restore to preview
            </span>
          ) : !access ? (
            <button
              type="button"
              onClick={handleOpen}
              disabled={isLoading}
              className="inline-flex min-h-10 items-center justify-center rounded-xl bg-[#092443] px-4 py-2 text-xs font-bold text-white transition hover:bg-[#123b64] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isLoading
                ? "Loading..."
                : isVideo
                  ? "Load video"
                  : isImage
                    ? "View image"
                    : "Open resource"}
            </button>
          ) : null}

          {asset.is_downloadable && !isArchived && (
            <button
              type="button"
              onClick={handleDownload}
              disabled={isDownloading}
              className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 transition hover:border-[#0e8988] hover:text-[#0e8988] disabled:opacity-50"
            >
              {isDownloading ? "Downloading..." : "Download"}
            </button>
          )}
        </div>
      </div>

      {error && (
        <div
          role="alert"
          className="mx-4 mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-xs leading-6 text-red-700 sm:mx-5"
        >
          {error}
          <button
            type="button"
            onClick={() => {
              setError("");
              setAccess(null);
            }}
            className="ml-2 font-bold underline"
          >
            Retry
          </button>
        </div>
      )}

      {access && !isArchived && (
        <div className="border-t border-slate-100 p-4 sm:p-5">
          {isStoredAsset(access) && isImage && (
            <div className="overflow-hidden rounded-xl bg-slate-100">
              {/* The URL is short-lived and issued by the protected asset endpoint. */}
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={access.url}
                alt={access.alt_text || title}
                className="mx-auto max-h-[560px] w-full object-contain"
                loading="lazy"
              />

              {access.caption && (
                <p className="px-4 py-3 text-center text-xs text-slate-500">
                  {access.caption}
                </p>
              )}
            </div>
          )}

          {isStoredAsset(access) &&
            !isImage &&
            isPdf && (
              <div>
                <div className="mb-3 flex items-center justify-between gap-3">
                  <p className="text-xs font-bold text-slate-600">
                    PDF Preview
                  </p>

                  <a
                    href={access.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-xs font-bold text-[#0e8988] hover:underline"
                  >
                    Open in new tab
                  </a>
                </div>

                <iframe
                  src={access.url}
                  title={title}
                  className="h-[560px] w-full rounded-xl border border-slate-200 bg-slate-50"
                  loading="lazy"
                />
              </div>
            )}

          {isStoredAsset(access) &&
            !isImage &&
            !isPdf && (
              <div className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-4">
                <p className="text-xs text-slate-600">
                  This resource is ready to open.
                </p>

                <a
                  href={access.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-xs font-extrabold text-[#0e8988] hover:underline"
                >
                  Open resource →
                </a>
              </div>
            )}

          {(isCloudflareVideo(access) || isExternalVideo(access)) && (
              <div>
                <LessonVideoPlayer
                  access={access}
                  title={title}
                  caption={asset.caption}
                />
                {isCloudflareVideo(access) && (
                <p className="mt-3 text-[10px] text-slate-400">
                  Secure video access expires at{" "}
                  {new Date(access.expires_at).toLocaleTimeString()}
                </p>
                )}
              </div>
            )}

          {isExternalAsset(access) && (
            <div className="rounded-xl bg-slate-50 p-4">
              <a
                href={access.url}
                target="_blank"
                rel="noopener noreferrer"
                className="text-sm font-bold text-[#0e8988] hover:underline"
              >
                Open external learning resource →
              </a>
            </div>
          )}
        </div>
      )}
    </article>
  );
}

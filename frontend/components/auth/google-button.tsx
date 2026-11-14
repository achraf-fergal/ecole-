"use client";

import { useState } from "react";
import { getGoogleAuthUrl } from "@/lib/auth";

interface GoogleAuthButtonProps {
  label?: string;
  mode?: "signin" | "signup";
}

export default function GoogleAuthButton({
  label,
  mode = "signup",
}: GoogleAuthButtonProps) {
  const [isRedirecting, setIsRedirecting] = useState(false);

  const defaultLabel =
    mode === "signup"
      ? "Sign up with Google"
      : "Sign in with Google";

  function handleClick() {
    setIsRedirecting(true);
    window.location.href = getGoogleAuthUrl();
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={isRedirecting}
      className="group relative flex min-h-12 w-full items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-xs transition hover:border-slate-300 hover:bg-slate-50/80 hover:text-slate-900 focus:outline-hidden focus:ring-4 focus:ring-teal-100 disabled:cursor-not-allowed disabled:opacity-60"
    >
      {isRedirecting ? (
        <span className="flex items-center gap-2">
          <svg
            className="h-4 w-4 animate-spin text-teal-600"
            viewBox="0 0 24 24"
            fill="none"
          >
            <circle
              className="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              strokeWidth="4"
            />
            <path
              className="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
          </svg>
          Connecting to Google...
        </span>
      ) : (
        <>
          {/* Authentic Google multicolor SVG icon */}
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            aria-hidden="true"
            className="shrink-0"
          >
            <path
              fill="#4285F4"
              d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"
            />
            <path
              fill="#34A853"
              d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.34 24 12 24z"
            />
            <path
              fill="#FBBC05"
              d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.16 0 9.94 0 12s.45 3.84 1.25 5.42l4.03-3.15z"
            />
            <path
              fill="#EA4335"
              d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.34 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"
            />
          </svg>
          <span>{label || defaultLabel}</span>
        </>
      )}
    </button>
  );
}

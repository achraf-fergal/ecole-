"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import AuthCard from "@/components/auth/auth-card";
import { apiResendVerification } from "@/lib/auth";

function VerifyEmailContent() {
  const searchParams = useSearchParams();
  const status = searchParams.get("status");

  const [isResending, setIsResending] = useState(false);
  const [resendMessage, setResendMessage] = useState("");
  const [resendError, setResendError] = useState("");

  async function handleResend() {
    setIsResending(true);
    setResendMessage("");
    setResendError("");

    try {
      const msg = await apiResendVerification();
      setResendMessage(msg);
    } catch (err: unknown) {
      setResendError(err instanceof Error ? err.message : "Failed to send link. Please sign in first.");
    } finally {
      setIsResending(false);
    }
  }

  if (status === "verified" || status === "already-verified") {
    return (
      <AuthCard
        eyebrow="EMAIL VERIFICATION"
        title="Email Verified"
        description="Your email address has been verified successfully."
      >
        <div className="py-4 text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e5f5f2] text-[#0e8988]">
            <svg
              width="32"
              height="32"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.3"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="m5 12 4 4L19 6" />
            </svg>
          </div>

          <h2 className="mt-4 text-xl font-black text-[#102b4c]">
            {status === "already-verified"
              ? "Your email is already verified"
              : "Account successfully activated"}
          </h2>

          <p className="mt-2 text-sm leading-6 text-slate-600">
            You now have full access to learning materials, cohort schedules, and course content.
          </p>

          <div className="mt-8 flex flex-col gap-3">
            <Link
              href="/courses"
              className="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#f6a23b] px-6 py-3 text-sm font-extrabold text-[#102b4c] transition hover:bg-[#ffb451]"
            >
              Explore Courses
            </Link>

            <Link
              href="/login"
              className="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#092443] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
            >
              Sign In to Dashboard
            </Link>
          </div>
        </div>
      </AuthCard>
    );
  }

  if (status === "invalid") {
    return (
      <AuthCard
        eyebrow="VERIFICATION FAILED"
        title="Invalid or expired link"
        description="This verification link has expired or is invalid."
      >
        <div className="py-4 text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-50 text-red-600">
            <svg
              width="32"
              height="32"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.3"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          </div>

          <h2 className="mt-4 text-xl font-black text-[#102b4c]">
            Link expired
          </h2>

          <p className="mt-2 text-sm leading-6 text-slate-600">
            For security reasons, verification links expire after 60 minutes. Please sign in to request a fresh verification link.
          </p>

          {resendMessage && (
            <div className="mt-4 rounded-xl border border-teal-200 bg-teal-50 p-3 text-xs text-teal-800">
              {resendMessage}
            </div>
          )}

          {resendError && (
            <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700">
              {resendError}
            </div>
          )}

          <div className="mt-6 flex flex-col gap-3">
            <button
              type="button"
              onClick={handleResend}
              disabled={isResending}
              className="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#0e8988] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#0b6e6d] disabled:opacity-50"
            >
              {isResending ? "Sending..." : "Resend verification email"}
            </button>

            <Link
              href="/login"
              className="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#092443] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
            >
              Sign in
            </Link>
          </div>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      eyebrow="EMAIL VERIFICATION"
      title="Verify your email address"
      description="Please verify your email address to access your courses."
    >
      <div className="py-4 text-center">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e5f5f2] text-[#0e8988]">
          <svg
            width="32"
            height="32"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <rect width="20" height="16" x="2" y="4" rx="2" />
            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
          </svg>
        </div>

        <h2 className="mt-4 text-xl font-black text-[#102b4c]">
          Check your inbox
        </h2>

        <p className="mt-2 text-sm leading-6 text-slate-600">
          We sent a verification link to your email. Click the link to complete your account setup.
        </p>

        {resendMessage && (
          <div className="mt-4 rounded-xl border border-teal-200 bg-teal-50 p-3 text-xs text-teal-800">
            {resendMessage}
          </div>
        )}

        {resendError && (
          <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700">
            {resendError}
          </div>
        )}

        <div className="mt-6 flex flex-col gap-3">
          <button
            type="button"
            onClick={handleResend}
            disabled={isResending}
            className="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#0e8988] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#0b6e6d] disabled:opacity-50"
          >
            {isResending ? "Sending..." : "Resend verification email"}
          </button>

          <Link
            href="/courses"
            className="flex min-h-12 w-full items-center justify-center rounded-xl bg-slate-100 px-6 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-200"
          >
            Back to Courses
          </Link>
        </div>
      </div>
    </AuthCard>
  );
}

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={<div className="min-h-screen bg-slate-50" />}>
      <VerifyEmailContent />
    </Suspense>
  );
}

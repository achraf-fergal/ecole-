"use client";

import Link from "next/link";
import { Suspense, useState, type FormEvent } from "react";

import AuthCard from "@/components/auth/auth-card";
import { apiForgotPassword } from "@/lib/auth";

function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isLoading) return;

    setError("");
    setMessage("");
    setIsLoading(true);

    try {
      const msg = await apiForgotPassword(email);
      setMessage(msg);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Request failed.");
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <AuthCard
      eyebrow="ACCOUNT RECOVERY"
      title="Reset your password"
      description="Enter your email address and we'll send you a secure link to reset your password."
      footer={
        <p className="text-sm text-slate-600">
          Remember your password?{" "}
          <Link
            href="/login"
            className="font-bold text-[#0e8988] transition hover:text-[#092443] hover:underline"
          >
            Sign in
          </Link>
        </p>
      }
    >
      {message ? (
        <div className="py-2 text-center">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#e5f5f2] text-[#0e8988]">
            <svg
              width="28"
              height="28"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.3"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h9" />
              <polyline points="22,6 12,13 2,6" />
              <circle cx="18" cy="18" r="3" />
            </svg>
          </div>

          <h2 className="mt-4 text-lg font-black text-[#102b4c]">Check your inbox</h2>
          <p className="mt-2 text-sm leading-6 text-slate-600">
            {message}
          </p>

          <Link
            href="/login"
            className="mt-6 inline-flex min-h-11 items-center justify-center rounded-xl bg-[#092443] px-6 py-2.5 text-xs font-bold text-white transition hover:bg-[#123b64]"
          >
            Return to Sign In
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-5" noValidate>
          {error && (
            <div
              role="alert"
              className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700"
            >
              {error}
            </div>
          )}

          <div>
            <label
              htmlFor="forgot-email"
              className="mb-2 block text-sm font-bold text-[#173655]"
            >
              Email address
            </label>
            <input
              id="forgot-email"
              name="email"
              type="email"
              autoComplete="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="name@example.com"
              className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
            />
          </div>

          <button
            type="submit"
            disabled={isLoading}
            className="flex min-h-13 w-full items-center justify-center gap-3 rounded-xl bg-[#f6a23b] px-6 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988] disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isLoading ? "Sending link..." : "Send password reset link"}
            {!isLoading && <span aria-hidden="true">→</span>}
          </button>
        </form>
      )}
    </AuthCard>
  );
}

export default function ForgotPasswordPage() {
  return (
    <Suspense fallback={<div className="min-h-screen bg-slate-50" />}>
      <ForgotPasswordForm />
    </Suspense>
  );
}

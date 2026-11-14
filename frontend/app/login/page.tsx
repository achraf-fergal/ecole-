"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";

import AuthCard from "@/components/auth/auth-card";
import GoogleAuthButton from "@/components/auth/google-button";
import { apiLogin, apiResendVerification, sanitizeRedirect } from "@/lib/auth";

function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const rawRedirect = searchParams.get("redirect");
  const redirectTarget = sanitizeRedirect(rawRedirect, "/courses");
  const urlError = searchParams.get("error");

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(
    urlError === "google_auth_failed"
      ? "Google authentication failed. Please try again."
      : urlError === "google_auth_cancelled"
      ? "Google sign-in was cancelled."
      : urlError === "account_disabled"
      ? "Your account has been deactivated. Please contact support."
      : urlError === "google_email_missing"
      ? "No email address was provided by your Google account."
      : ""
  );
  const [unverifiedState, setUnverifiedState] = useState(false);
  const [resendSuccess, setResendSuccess] = useState(false);
  const [isResending, setIsResending] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isLoading) return;

    setError("");
    setUnverifiedState(false);
    setResendSuccess(false);
    setIsLoading(true);

    try {
      const data = await apiLogin({ email, password });

      if (data.email_verified === false) {
        setUnverifiedState(true);
      }

      router.push(redirectTarget);
      router.refresh();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : "Authentication failed.";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  }

  async function handleResendVerification() {
    setIsResending(true);
    setError("");

    try {
      await apiResendVerification();
      setResendSuccess(true);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to resend verification email.");
    } finally {
      setIsResending(false);
    }
  }

  return (
    <AuthCard
      eyebrow="WELCOME BACK"
      title="Sign in to your account"
      description="Enter your email and password to access your dashboard and learning materials."
      footer={
        <p className="text-sm text-slate-600">
          Don&apos;t have an account?{" "}
          <Link
            href={`/register${rawRedirect ? `?redirect=${encodeURIComponent(rawRedirect)}` : ""}`}
            className="font-bold text-[#0e8988] transition hover:text-[#092443] hover:underline"
          >
            Create an account
          </Link>
        </p>
      }
    >
      <div className="mb-6 space-y-4">
        <GoogleAuthButton mode="signin" label="Sign in with Google" />

        <div className="relative flex items-center justify-center">
          <div className="w-full border-t border-slate-200" />
          <span className="bg-white px-3 text-xs font-semibold tracking-wider text-slate-600 uppercase">
            or sign in with email
          </span>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-5" noValidate>
        {error && (
          <div
            role="alert"
            className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700"
          >
            {error}
          </div>
        )}

        {unverifiedState && (
          <div
            role="status"
            className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800"
          >
            <p className="font-bold">Your email address is not verified yet.</p>
            <p className="mt-1 text-xs text-amber-700">
              Please check your inbox or resend the verification email to access protected course content.
            </p>
            <button
              type="button"
              onClick={handleResendVerification}
              disabled={isResending || resendSuccess}
              className="mt-3 inline-flex items-center gap-1.5 text-xs font-extrabold text-[#092443] underline transition hover:text-[#0e8988] disabled:opacity-50"
            >
              {isResending ? "Sending link..." : resendSuccess ? "Verification link sent!" : "Resend verification email"}
            </button>
          </div>
        )}

        <div>
          <label
            htmlFor="login-email"
            className="mb-2 block text-sm font-bold text-[#173655]"
          >
            Email address
          </label>
          <input
            id="login-email"
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

        <div>
          <div className="mb-2 flex items-center justify-between">
            <label
              htmlFor="login-password"
              className="block text-sm font-bold text-[#173655]"
            >
              Password
            </label>
            <Link
              href="/forgot-password"
              className="text-xs font-semibold text-[#0e8988] transition hover:text-[#092443] hover:underline"
            >
              Forgot password?
            </Link>
          </div>

          <div className="relative">
            <input
              id="login-password"
              name="password"
              type={showPassword ? "text" : "password"}
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••••••"
              className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 pr-12 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              aria-label={showPassword ? "Hide password" : "Show password"}
              className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-xs font-bold text-slate-500 hover:text-slate-800"
            >
              {showPassword ? "Hide" : "Show"}
            </button>
          </div>
        </div>

        <button
          type="submit"
          disabled={isLoading}
          className="flex min-h-13 w-full items-center justify-center gap-3 rounded-xl bg-[#f6a23b] px-6 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988] disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isLoading ? "Signing in..." : "Sign in to account"}
          {!isLoading && <span aria-hidden="true">→</span>}
        </button>
      </form>
    </AuthCard>
  );
}

export default function LoginPage() {
  return (
    <Suspense fallback={<div className="min-h-screen bg-slate-50" />}>
      <LoginForm />
    </Suspense>
  );
}

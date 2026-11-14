"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState, type FormEvent } from "react";

import AuthCard from "@/components/auth/auth-card";
import PasswordStrengthIndicator from "@/components/auth/password-strength";
import { apiResetPassword } from "@/lib/auth";

function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const token = searchParams.get("token") || "";
  const emailParam = searchParams.get("email") || "";

  const [email, setEmail] = useState(emailParam);
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");
  const [isSuccess, setIsSuccess] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isLoading) return;

    setError("");

    if (!token) {
      setError("Missing or invalid password reset token. Please request a new link.");
      return;
    }

    if (password.length < 12) {
      setError("Password must be at least 12 characters long.");
      return;
    }

    if (password !== passwordConfirmation) {
      setError("Passwords do not match.");
      return;
    }

    setIsLoading(true);

    try {
      await apiResetPassword({
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });

      setIsSuccess(true);
      setTimeout(() => {
        router.push("/login");
      }, 3000);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to reset password.");
    } finally {
      setIsLoading(false);
    }
  }

  if (isSuccess) {
    return (
      <AuthCard
        eyebrow="PASSWORD UPDATED"
        title="Password reset successful"
        description="Your password has been securely updated. All other active sessions have been invalidated."
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
            Ready to sign in
          </h2>

          <p className="mt-2 text-sm leading-6 text-slate-600">
            Redirecting you to the sign-in page...
          </p>

          <Link
            href="/login"
            className="mt-6 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-[#092443] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
          >
            Sign in now
          </Link>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      eyebrow="SET NEW PASSWORD"
      title="Create new password"
      description="Enter your new password below. It must be at least 12 characters long."
      footer={
        <p className="text-sm text-slate-600">
          Remembered your password?{" "}
          <Link
            href="/login"
            className="font-bold text-[#0e8988] transition hover:text-[#092443] hover:underline"
          >
            Sign in
          </Link>
        </p>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
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
            htmlFor="reset-email"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            Email address
          </label>
          <input
            id="reset-email"
            name="email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
          />
        </div>

        <div>
          <label
            htmlFor="reset-password"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            New password (min 12 characters) *
          </label>
          <div className="relative">
            <input
              id="reset-password"
              name="password"
              type={showPassword ? "text" : "password"}
              autoComplete="new-password"
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

          <PasswordStrengthIndicator password={password} />
        </div>

        <div>
          <label
            htmlFor="reset-password-confirm"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            Confirm new password *
          </label>
          <div className="relative">
            <input
              id="reset-password-confirm"
              name="password_confirmation"
              type={showConfirmPassword ? "text" : "password"}
              autoComplete="new-password"
              required
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              placeholder="••••••••••••"
              className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 pr-12 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
            />
            <button
              type="button"
              onClick={() => setShowConfirmPassword(!showConfirmPassword)}
              aria-label={showConfirmPassword ? "Hide password confirmation" : "Show password confirmation"}
              className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-xs font-bold text-slate-500 hover:text-slate-800"
            >
              {showConfirmPassword ? "Hide" : "Show"}
            </button>
          </div>
        </div>

        <button
          type="submit"
          disabled={isLoading}
          className="flex min-h-13 w-full items-center justify-center gap-3 rounded-xl bg-[#f6a23b] px-6 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988] disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isLoading ? "Updating password..." : "Update password"}
          {!isLoading && <span aria-hidden="true">→</span>}
        </button>
      </form>
    </AuthCard>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={<div className="min-h-screen bg-slate-50" />}>
      <ResetPasswordForm />
    </Suspense>
  );
}

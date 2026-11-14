"use client";

import Link from "next/link";
import { Suspense, useState, type FormEvent } from "react";

import AuthCard from "@/components/auth/auth-card";
import GoogleAuthButton from "@/components/auth/google-button";
import PasswordStrengthIndicator from "@/components/auth/password-strength";
import { apiRegister } from "@/lib/auth";

function RegisterForm() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [terms, setTerms] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");
  const [registeredEmail, setRegisteredEmail] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isLoading) return;

    setError("");

    if (password.length < 12) {
      setError("Password must be at least 12 characters long.");
      return;
    }

    if (password !== passwordConfirmation) {
      setError("Passwords do not match.");
      return;
    }

    if (!terms) {
      setError("You must accept the privacy notice and terms of service.");
      return;
    }

    setIsLoading(true);

    try {
      await apiRegister({
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
        terms,
      });

      setRegisteredEmail(email);
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : "Registration failed.";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  }

  if (registeredEmail) {
    return (
      <AuthCard
        eyebrow="ACCOUNT CREATED"
        title="Verify your email address"
        description="We have sent a verification link to your email address."
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

          <h2 className="mt-5 text-xl font-black text-[#102b4c]">
            Almost there!
          </h2>

          <p className="mt-3 text-sm leading-6 text-slate-600">
            A confirmation link was sent to{" "}
            <span className="font-bold text-[#092443]">{registeredEmail}</span>.
            Please click the link in your email to verify your account and unlock your courses.
          </p>

          <div className="mt-6 rounded-xl border border-teal-100 bg-teal-50/70 p-4 text-xs leading-5 text-teal-900">
            Did not receive the email? Check your spam folder or sign in to request a new link.
          </div>

          <div className="mt-8 flex flex-col gap-3">
            <Link
              href="/login"
              className="flex min-h-12 w-full items-center justify-center rounded-xl bg-[#092443] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
            >
              Proceed to Sign In
            </Link>
          </div>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      eyebrow="CREATE ACCOUNT"
      title="Start learning today"
      description="Create your learner account to access courses and cohorts."
      footer={
        <p className="text-sm text-slate-600">
          Already have an account?{" "}
          <Link
            href="/login"
            className="font-bold text-[#0e8988] transition hover:text-[#092443] hover:underline"
          >
            Sign in
          </Link>
        </p>
      }
    >
      <div className="mb-6 space-y-4">
        <GoogleAuthButton mode="signup" label="Sign up with Google" />

        <div className="relative flex items-center justify-center">
          <div className="w-full border-t border-slate-200" />
          <span className="bg-white px-3 text-xs font-semibold tracking-wider text-slate-600 uppercase">
            or sign up with email
          </span>
        </div>
      </div>

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
            htmlFor="register-name"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            Full name *
          </label>
          <input
            id="register-name"
            name="name"
            type="text"
            autoComplete="name"
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Jane Doe"
            className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
          />
        </div>

        <div>
          <label
            htmlFor="register-email"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            Email address *
          </label>
          <input
            id="register-email"
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
          <label
            htmlFor="register-password"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            Password (min 12 characters) *
          </label>
          <div className="relative">
            <input
              id="register-password"
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
            htmlFor="register-password-confirm"
            className="mb-1.5 block text-sm font-bold text-[#173655]"
          >
            Confirm password *
          </label>
          <div className="relative">
            <input
              id="register-password-confirm"
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

        <label className="flex cursor-pointer items-start gap-3 rounded-xl bg-slate-50 p-3.5">
          <input
            id="register-terms"
            name="terms"
            type="checkbox"
            required
            checked={terms}
            onChange={(e) => setTerms(e.target.checked)}
            className="mt-1 h-4 w-4 shrink-0 accent-[#0e8988]"
          />
          <span className="text-xs leading-5 text-slate-600">
            I agree to the Terms of Service and acknowledge the Privacy Notice. *
          </span>
        </label>

        <button
          type="submit"
          disabled={isLoading}
          className="flex min-h-13 w-full items-center justify-center gap-3 rounded-xl bg-[#f6a23b] px-6 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988] disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isLoading ? "Creating account..." : "Create Account"}
          {!isLoading && <span aria-hidden="true">→</span>}
        </button>
      </form>
    </AuthCard>
  );
}

export default function RegisterPage() {
  return (
    <Suspense fallback={<div className="min-h-screen bg-slate-50" />}>
      <RegisterForm />
    </Suspense>
  );
}

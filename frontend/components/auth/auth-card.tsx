import Link from "next/link";
import type { ReactNode } from "react";

interface AuthCardProps {
  eyebrow?: string;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}

export default function AuthCard({
  eyebrow = "SECURITY & ACCESS",
  title,
  description,
  children,
  footer,
}: AuthCardProps) {
  return (
    <div className="flex min-h-[calc(100vh-4rem)] flex-col justify-center px-4 py-12 sm:px-6 lg:px-8">
      <div className="mx-auto w-full max-w-md">
        <div className="mb-6 text-center">
          <Link
            href="/"
            className="inline-flex items-center gap-2 text-xl font-black tracking-tight text-[#092443] transition hover:opacity-90"
          >
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#092443] text-lg font-black text-white shadow-sm">
              LC
            </span>
            <span>Language Center</span>
          </Link>
        </div>

        <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_16px_50px_rgba(9,36,67,0.07)]">
          <div className="bg-[#092443] px-6 py-8 text-white sm:px-8">
            <span className="text-xs font-extrabold uppercase tracking-[0.17em] text-[#80d5d0]">
              {eyebrow}
            </span>

            <h1 className="mt-2 text-2xl font-black text-white sm:text-3xl">
              {title}
            </h1>

            {description && (
              <p className="mt-2 text-sm leading-6 text-slate-300">
                {description}
              </p>
            )}
          </div>

          <div className="p-6 sm:p-8">
            {children}
          </div>

          {footer && (
            <div className="border-t border-slate-100 bg-slate-50 px-6 py-4 text-center sm:px-8">
              {footer}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

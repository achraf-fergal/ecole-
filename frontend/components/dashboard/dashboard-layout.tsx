"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type ReactNode } from "react";

import { apiLogout, type AuthUser } from "@/lib/auth";

interface DashboardLayoutProps {
  user: AuthUser;
  activeCenterSlug?: string;
  onCenterChange?: (slug: string) => void;
  children: ReactNode;
}

export default function DashboardLayout({
  user,
  activeCenterSlug,
  onCenterChange,
  children,
}: DashboardLayoutProps) {
  const router = useRouter();
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const activeCenter =
    user.centers.find((c) => c.slug === activeCenterSlug) || user.centers[0];

  async function handleLogout() {
    setIsLoggingOut(true);
    try {
      await apiLogout();
      router.push("/login");
      router.refresh();
    } catch {
      router.push("/login");
    } finally {
      setIsLoggingOut(false);
    }
  }

  const navItems = [
    {
      label: "Users & Roles",
      href: "/admin/users",
      icon: (
        <svg
          className="h-5 w-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
        >
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      ),
      active: true,
    },
    {
      label: "Courses",
      href: "/courses",
      icon: (
        <svg
          className="h-5 w-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
        >
          <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z" />
          <path d="M6 6h10" />
          <path d="M6 10h10" />
        </svg>
      ),
      active: false,
    },
  ];

  return (
    <div className="flex min-h-screen bg-slate-50 text-slate-800">
      {/* SIDEBAR (Desktop) */}
      <aside className="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-white lg:flex">
        {/* Brand */}
        <div className="flex h-16 items-center gap-3 border-b border-slate-100 px-6">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#092443] text-[#f6a23b]">
            <svg
              className="h-6 w-6"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
              <path d="M6 12v5c3 3 9 3 12 0v-5" />
            </svg>
          </div>
          <div>
            <span className="block text-sm font-extrabold tracking-tight text-[#092443]">
              Language SaaS
            </span>
            <span className="block text-xs font-semibold text-slate-600">
              Admin Portal
            </span>
          </div>
        </div>

        {/* Center Switcher */}
        {user.centers.length > 0 && (
          <div className="border-b border-slate-100 p-4">
            <label className="mb-1 block text-xs font-bold tracking-wider text-slate-600 uppercase">
              Current Center
            </label>
            <select
              value={activeCenter?.slug || ""}
              onChange={(e) => onCenterChange?.(e.target.value)}
              className="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 outline-none transition focus:border-teal-500 focus:bg-white"
            >
              {user.centers.map((c) => (
                <option key={c.id} value={c.slug}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Navigation */}
        <nav className="flex-1 space-y-1 p-4">
          {navItems.map((item) => (
            <Link
              key={item.label}
              href={item.href}
              className={`flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-bold transition ${
                item.active
                  ? "bg-[#092443] text-white shadow-xs"
                  : "text-slate-600 hover:bg-slate-100 hover:text-slate-900"
              }`}
            >
              {item.icon}
              {item.label}
            </Link>
          ))}
        </nav>

        {/* User Card & Logout */}
        <div className="border-t border-slate-100 p-4">
          <div className="mb-3 flex items-center gap-3 rounded-xl bg-slate-50 p-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-teal-600 text-sm font-black text-white">
              {user.name.charAt(0).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <div className="truncate text-xs font-bold text-slate-900">
                {user.name}
              </div>
              <div className="truncate text-[11px] text-slate-600">
                {user.email}
              </div>
              <div className="mt-1 flex gap-1">
                {user.is_platform_admin && (
                  <span className="inline-block rounded-md bg-purple-100 px-1.5 py-0.5 text-[10px] font-black tracking-wider text-purple-800 uppercase">
                    Super Admin
                  </span>
                )}
                {!user.is_platform_admin && activeCenter && (
                  <span className="inline-block rounded-md bg-blue-100 px-1.5 py-0.5 text-[10px] font-black tracking-wider text-blue-800 uppercase">
                    {activeCenter.roles[0]?.name || "Admin"}
                  </span>
                )}
              </div>
            </div>
          </div>

          <button
            type="button"
            onClick={handleLogout}
            disabled={isLoggingOut}
            className="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 py-2.5 text-xs font-bold text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700"
          >
            <svg
              className="h-4 w-4"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            {isLoggingOut ? "Signing out..." : "Sign Out"}
          </button>
        </div>
      </aside>

      {/* MAIN CONTENT AREA */}
      <div className="flex min-w-0 flex-1 flex-col">
        {/* Mobile Header */}
        <header className="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-5 lg:hidden">
          <div className="flex items-center gap-2">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-[#092443] text-[#f6a23b]">
              <svg
                className="h-5 w-5"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
              >
                <path d="M22 10v6M2 10l10-5 10 5-10 5z" />
              </svg>
            </div>
            <span className="text-sm font-black text-[#092443]">Dashboard</span>
          </div>

          <button
            type="button"
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="rounded-lg border border-slate-200 p-2 text-slate-600"
          >
            <svg
              className="h-5 w-5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <line x1="3" y1="12" x2="21" y2="12" />
              <line x1="3" y1="6" x2="21" y2="6" />
              <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
          </button>
        </header>

        {mobileMenuOpen && (
          <div className="border-b border-slate-200 bg-white p-4 lg:hidden">
            <nav className="space-y-1">
              {navItems.map((item) => (
                <Link
                  key={item.label}
                  href={item.href}
                  className="block rounded-lg px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100"
                  onClick={() => setMobileMenuOpen(false)}
                >
                  {item.label}
                </Link>
              ))}
              <button
                type="button"
                onClick={handleLogout}
                className="block w-full text-left rounded-lg px-3 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
              >
                Sign Out
              </button>
            </nav>
          </div>
        )}

        {/* Content body */}
        <main className="flex-1 p-5 sm:p-8 lg:p-10">{children}</main>
      </div>
    </div>
  );
}

"use client";

import Link from "next/link";
import { useEffect, useState, useTransition } from "react";

import DashboardLayout from "@/components/dashboard/dashboard-layout";
import {
  apiAddUserToCenter,
  apiGetAvailableRoles,
  apiGetCenterUsers,
  apiToggleSuperAdmin,
  apiUpdateUserMembershipStatus,
  apiUpdateUserRoles,
  type CenterMember,
  type CenterRole,
} from "@/lib/admin";
import { apiGetMe, type AuthUser } from "@/lib/auth";

export default function UsersManagementPage() {
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(null);
  const [activeCenterSlug, setActiveCenterSlug] = useState<string>("");
  const [members, setMembers] = useState<CenterMember[]>([]);
  const [availableRoles, setAvailableRoles] = useState<CenterRole[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");

  // Filters
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");

  // Edit Roles Modal
  const [editingMember, setEditingMember] = useState<CenterMember | null>(null);
  const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
  const [isSavingRoles, setIsSavingRoles] = useState(false);

  // Add Member Modal
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [newEmail, setNewEmail] = useState("");
  const [newName, setNewName] = useState("");
  const [newRoles, setNewRoles] = useState<string[]>(["student"]);
  const [isAddingMember, setIsAddingMember] = useState(false);

  const [, startTransition] = useTransition();

  // Load Current User on Mount
  useEffect(() => {
    async function initUser() {
      try {
        const user = await apiGetMe();
        if (!user) {
          setError("Authentication required.");
          setIsLoading(false);
          return;
        }

        setCurrentUser(user);

        const initialSlug =
          user.centers[0]?.slug || process.env.CENTER_SLUG || "demo-center";
        setActiveCenterSlug(initialSlug);
      } catch (err: unknown) {
        setError(
          err instanceof Error ? err.message : "Authentication required."
        );
        setIsLoading(false);
      }
    }

    initUser();
  }, []);

  // Load Center Members & Roles when activeCenterSlug changes
  useEffect(() => {
    if (!activeCenterSlug) return;

    let isMounted = true;

    async function loadData() {
      setIsLoading(true);
      setError("");

      try {
        const [usersRes, rolesRes] = await Promise.all([
          apiGetCenterUsers(activeCenterSlug, {
            search: search || undefined,
            role: roleFilter || undefined,
          }),
          apiGetAvailableRoles(activeCenterSlug),
        ]);

        if (isMounted) {
          setMembers(usersRes.data);
          setAvailableRoles(rolesRes);
        }
      } catch (err: unknown) {
        if (isMounted) {
          setError(
            err instanceof Error ? err.message : "Failed to load members."
          );
        }
      } finally {
        if (isMounted) {
          setIsLoading(false);
        }
      }
    }

    loadData();

    return () => {
      isMounted = false;
    };
  }, [activeCenterSlug, search, roleFilter]);

  // Handle Role Update Submission
  async function handleSaveRoles() {
    if (!editingMember || selectedRoles.length === 0) return;

    setIsSavingRoles(true);
    setError("");
    setSuccessMsg("");

    try {
      await apiUpdateUserRoles(
        activeCenterSlug,
        editingMember.id,
        selectedRoles
      );

      setSuccessMsg(`Roles updated for ${editingMember.name}.`);
      setEditingMember(null);

      // Refresh list
      const refreshed = await apiGetCenterUsers(activeCenterSlug, {
        search: search || undefined,
        role: roleFilter || undefined,
      });
      setMembers(refreshed.data);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update roles.");
    } finally {
      setIsSavingRoles(false);
    }
  }

  // Handle Add Member Submission
  async function handleAddMember(e: React.FormEvent) {
    e.preventDefault();
    if (!newEmail || newRoles.length === 0) return;

    setIsAddingMember(true);
    setError("");
    setSuccessMsg("");

    try {
      await apiAddUserToCenter(activeCenterSlug, {
        email: newEmail,
        name: newName || undefined,
        roles: newRoles,
      });

      setSuccessMsg(`User ${newEmail} added successfully.`);
      setIsAddModalOpen(false);
      setNewEmail("");
      setNewName("");
      setNewRoles(["student"]);

      // Refresh list
      const refreshed = await apiGetCenterUsers(activeCenterSlug);
      setMembers(refreshed.data);
    } catch (err: unknown) {
      setError(
        err instanceof Error ? err.message : "Failed to add member to center."
      );
    } finally {
      setIsAddingMember(false);
    }
  }

  // Handle Membership Status Toggle
  async function handleToggleStatus(member: CenterMember) {
    const nextStatus =
      member.membership_status === "active" ? "suspended" : "active";

    try {
      await apiUpdateUserMembershipStatus(
        activeCenterSlug,
        member.id,
        nextStatus
      );

      startTransition(() => {
        setMembers((prev) =>
          prev.map((m) =>
            m.id === member.id ? { ...m, membership_status: nextStatus } : m
          )
        );
      });
      setSuccessMsg(
        `Status updated to ${nextStatus} for ${member.name}.`
      );
    } catch (err: unknown) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to update membership status."
      );
    }
  }

  // Handle Super Admin Toggle (Platform Admin only)
  async function handleToggleSuperAdmin(userId: number, currentStatus: boolean) {
    try {
      await apiToggleSuperAdmin(userId, !currentStatus);
      setSuccessMsg("Super Admin privilege updated.");
      // Refresh list
      const refreshed = await apiGetCenterUsers(activeCenterSlug, {
        search: search || undefined,
        role: roleFilter || undefined,
      });
      setMembers(refreshed.data);
    } catch (err: unknown) {
      setError(
        err instanceof Error ? err.message : "Failed to toggle Super Admin."
      );
    }
  }

  // Authorization check
  const isAuthorized =
    currentUser?.is_platform_admin ||
    currentUser?.centers.some((c) =>
      c.roles.some((r) => r.key === "center_admin")
    );

  if (isLoading && !currentUser) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <div className="flex items-center gap-3 text-slate-600">
          <svg
            className="h-6 w-6 animate-spin text-teal-600"
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
          <span className="font-bold">Verifying authorization...</span>
        </div>
      </div>
    );
  }

  if (!currentUser || !isAuthorized) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md rounded-2xl border border-red-200 bg-white p-8 text-center shadow-xs">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-red-600">
            <svg
              className="h-7 w-7"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          </div>
          <h2 className="mt-4 text-xl font-black text-slate-900">
            Access Restricted
          </h2>
          <p className="mt-2 text-sm text-slate-600">
            You need Center Admin or Super Admin permissions to manage users and
            roles.
          </p>
          <div className="mt-6">
            <Link
              href="/courses"
              className="inline-flex rounded-xl bg-[#092443] px-6 py-2.5 text-xs font-bold text-white hover:bg-[#123b64]"
            >
              Return to Courses
            </Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <DashboardLayout
      user={currentUser}
      activeCenterSlug={activeCenterSlug}
      onCenterChange={(slug) => setActiveCenterSlug(slug)}
    >
      <div className="space-y-6">
        {/* Header Title & Actions */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-black tracking-tight text-[#092443]">
              User & Role Management
            </h1>
            <p className="text-xs text-slate-600 sm:text-sm">
              Manage center members, assign permissions, and control user roles.
            </p>
          </div>

          <button
            type="button"
            onClick={() => setIsAddModalOpen(true)}
            className="inline-flex items-center gap-2 rounded-xl bg-[#092443] px-5 py-2.5 text-xs font-extrabold text-white shadow-xs transition hover:bg-[#123b64]"
          >
            <svg
              className="h-4 w-4"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
            >
              <line x1="12" y1="5" x2="12" y2="19" />
              <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            Add Member
          </button>
        </div>

        {/* Feedback Alerts */}
        {error && (
          <div
            role="alert"
            className="flex items-center justify-between rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
          >
            <span>{error}</span>
            <button
              onClick={() => setError("")}
              className="font-bold text-red-500 hover:text-red-700"
            >
              ✕
            </button>
          </div>
        )}

        {successMsg && (
          <div
            role="status"
            className="flex items-center justify-between rounded-xl border border-teal-200 bg-teal-50 p-4 text-sm text-teal-800"
          >
            <span>{successMsg}</span>
            <button
              onClick={() => setSuccessMsg("")}
              className="font-bold text-teal-500 hover:text-teal-700"
            >
              ✕
            </button>
          </div>
        )}

        {/* Filters Bar */}
        <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs sm:flex-row sm:items-center">
          <div className="relative flex-1">
            <svg
              className="absolute top-3 left-3 h-4 w-4 text-slate-400"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <circle cx="11" cy="11" r="8" />
              <line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by name or email..."
              className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-4 pl-9 text-xs outline-none transition focus:border-teal-500 focus:bg-white"
            />
          </div>

          <div className="w-full sm:w-48">
            <select
              value={roleFilter}
              onChange={(e) => setRoleFilter(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-teal-500 focus:bg-white"
            >
              <option value="">All Roles</option>
              {availableRoles.map((r) => (
                <option key={r.id} value={r.key}>
                  {r.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        {/* Users Table */}
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs text-slate-600">
              <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-bold tracking-wider text-slate-600 uppercase">
                <tr>
                  <th className="px-6 py-4">User</th>
                  <th className="px-6 py-4">Roles in Center</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading ? (
                  <tr>
                    <td colSpan={4} className="py-12 text-center text-slate-600">
                      Loading users...
                    </td>
                  </tr>
                ) : members.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="py-12 text-center text-slate-600">
                      No members found matching your search.
                    </td>
                  </tr>
                ) : (
                  members.map((member) => (
                    <tr
                      key={member.id}
                      className="transition hover:bg-slate-50/70"
                    >
                      {/* Name & Email */}
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#092443] text-xs font-black text-white">
                            {member.name.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <div className="font-bold text-slate-900">
                              {member.name}
                            </div>
                            <div className="text-[11px] text-slate-600">
                              {member.email}
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* Roles */}
                      <td className="px-6 py-4">
                        <div className="flex flex-wrap gap-1.5">
                          {member.roles.map((r) => (
                            <span
                              key={r.key}
                              className={`rounded-md px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wider ${
                                r.key === "center_admin"
                                  ? "bg-blue-100 text-blue-800"
                                  : r.key === "teacher"
                                  ? "bg-emerald-100 text-emerald-800"
                                  : "bg-amber-100 text-amber-800"
                              }`}
                            >
                              {r.name}
                            </span>
                          ))}
                        </div>
                      </td>

                      {/* Status */}
                      <td className="px-6 py-4">
                        <span
                          className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold ${
                            member.membership_status === "active"
                              ? "bg-emerald-50 text-emerald-700"
                              : "bg-red-50 text-red-700"
                          }`}
                        >
                          <span
                            className={`h-1.5 w-1.5 rounded-full ${
                              member.membership_status === "active"
                                ? "bg-emerald-500"
                                : "bg-red-500"
                            }`}
                          />
                          {member.membership_status}
                        </span>
                      </td>

                      {/* Actions */}
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => {
                              setEditingMember(member);
                              setSelectedRoles(member.roles.map((r) => r.key));
                            }}
                            className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:border-[#092443] hover:text-[#092443]"
                          >
                            Edit Roles
                          </button>

                          <button
                            type="button"
                            onClick={() => handleToggleStatus(member)}
                            className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-100"
                            title="Toggle Active/Suspended"
                          >
                            {member.membership_status === "active"
                              ? "Suspend"
                              : "Activate"}
                          </button>

                          {currentUser.is_platform_admin && (
                            <button
                              type="button"
                              onClick={() =>
                                handleToggleSuperAdmin(member.id, false)
                              }
                              className="rounded-lg border border-purple-200 bg-purple-50 px-2.5 py-1.5 text-[11px] font-bold text-purple-700 transition hover:bg-purple-100"
                              title="Toggle Platform Super Admin"
                            >
                              Super Admin
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* EDIT ROLES MODAL */}
      {editingMember && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-xs">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-black text-slate-900">
              Edit Roles for {editingMember.name}
            </h3>
            <p className="mt-1 text-xs text-slate-600">
              Select one or more roles for this member within the current
              center.
            </p>

            <div className="mt-5 space-y-3">
              {availableRoles.map((role) => {
                const isChecked = selectedRoles.includes(role.key);
                return (
                  <label
                    key={role.id}
                    className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition ${
                      isChecked
                        ? "border-teal-500 bg-teal-50/50"
                        : "border-slate-200 hover:bg-slate-50"
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={isChecked}
                      onChange={(e) => {
                        if (e.target.checked) {
                          setSelectedRoles([...selectedRoles, role.key]);
                        } else {
                          setSelectedRoles(
                            selectedRoles.filter((k) => k !== role.key)
                          );
                        }
                      }}
                      className="mt-0.5 h-4 w-4 rounded-sm border-slate-300 text-teal-600 focus:ring-teal-500"
                    />
                    <div>
                      <div className="text-xs font-bold text-slate-900">
                        {role.name}
                      </div>
                      {role.description && (
                        <div className="text-[11px] text-slate-600">
                          {role.description}
                        </div>
                      )}
                    </div>
                  </label>
                );
              })}
            </div>

            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setEditingMember(null)}
                className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleSaveRoles}
                disabled={isSavingRoles || selectedRoles.length === 0}
                className="rounded-xl bg-[#092443] px-5 py-2 text-xs font-bold text-white transition hover:bg-[#123b64] disabled:opacity-50"
              >
                {isSavingRoles ? "Saving..." : "Save Roles"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ADD MEMBER MODAL */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-xs">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-black text-slate-900">
              Add Member to Center
            </h3>
            <p className="mt-1 text-xs text-slate-600">
              Assign an existing user or invite a new member with specific
              roles.
            </p>

            <form onSubmit={handleAddMember} className="mt-4 space-y-4">
              <div>
                <label className="mb-1 block text-xs font-bold text-slate-700">
                  Email Address *
                </label>
                <input
                  type="email"
                  required
                  value={newEmail}
                  onChange={(e) => setNewEmail(e.target.value)}
                  placeholder="user@example.com"
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs outline-none focus:border-teal-500 focus:bg-white"
                />
              </div>

              <div>
                <label className="mb-1 block text-xs font-bold text-slate-700">
                  Full Name (Optional)
                </label>
                <input
                  type="text"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  placeholder="John Doe"
                  className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs outline-none focus:border-teal-500 focus:bg-white"
                />
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-bold text-slate-700">
                  Assign Roles *
                </label>
                <div className="space-y-2">
                  {availableRoles.map((role) => {
                    const isChecked = newRoles.includes(role.key);
                    return (
                      <label
                        key={role.id}
                        className="flex cursor-pointer items-center gap-2 text-xs text-slate-700"
                      >
                        <input
                          type="checkbox"
                          checked={isChecked}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setNewRoles([...newRoles, role.key]);
                            } else {
                              setNewRoles(
                                newRoles.filter((k) => k !== role.key)
                              );
                            }
                          }}
                          className="h-3.5 w-3.5 rounded-sm border-slate-300 text-teal-600"
                        />
                        <span className="font-semibold">{role.name}</span>
                      </label>
                    );
                  })}
                </div>
              </div>

              <div className="mt-6 flex justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setIsAddModalOpen(false)}
                  className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isAddingMember || newRoles.length === 0}
                  className="rounded-xl bg-[#092443] px-5 py-2 text-xs font-bold text-white transition hover:bg-[#123b64] disabled:opacity-50"
                >
                  {isAddingMember ? "Adding..." : "Add Member"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}

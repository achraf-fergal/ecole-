
"use client";

import { useState, type FormEvent } from "react";

import type { Locale } from "@/lib/courses";

interface CourseRegistrationFormProps {
  apiBaseUrl: string;
  centerSlug: string;
  courseSlug: string;
  courseTitle: string;
  locale: Locale;
}

const translations = {
  en: {
    eyebrow: "START YOUR JOURNEY",
    title: "Request Course Enrollment",
    description:
      "Leave your contact details and our admissions team will help you find the right study option.",
    name: "Full name",
    phone: "Phone number",
    email: "Email address",
    contactMethod: "Preferred contact method",
    chooseMethod: "Choose a method",
    whatsapp: "WhatsApp",
    phoneCall: "Phone call",
    emailContact: "Email",
    message: "Additional message",
    messagePlaceholder:
      "Tell us about your learning goals (optional)",
    privacy:
      "I agree that the center may use my contact details to respond to this request.",
    submit: "Send registration request",
    submitting: "Sending...",
    successTitle: "Request received!",
    successMessage:
      "Thank you. Our admissions team will contact you soon. This request does not confirm your enrollment.",
    anotherRequest: "Send another request",
    contactRequired:
      "Please enter at least one contact method: phone or email.",
    preferredContactRequired:
      "Please provide the contact information for your preferred method.",
    privacyRequired:
      "Please accept the contact and privacy notice.",
    genericError:
      "We could not submit your request. Please try again.",
    validationError:
      "Please check your information and try again.",
    rateLimit:
      "Too many requests. Please wait before trying again.",
    course: "Selected course",
  },

  fr: {
    eyebrow: "COMMENCEZ VOTRE PARCOURS",
    title: "Demander une inscription",
    description:
      "Laissez vos coordonnées et notre équipe vous aidera à choisir la formule adaptée.",
    name: "Nom complet",
    phone: "Numéro de téléphone",
    email: "Adresse e-mail",
    contactMethod: "Moyen de contact préféré",
    chooseMethod: "Choisir un moyen",
    whatsapp: "WhatsApp",
    phoneCall: "Appel téléphonique",
    emailContact: "E-mail",
    message: "Message complémentaire",
    messagePlaceholder:
      "Parlez-nous de vos objectifs (facultatif)",
    privacy:
      "J'accepte que le centre utilise mes coordonnées pour répondre à ma demande.",
    submit: "Envoyer ma demande",
    submitting: "Envoi en cours...",
    successTitle: "Demande reçue !",
    successMessage:
      "Merci. Notre équipe vous contactera prochainement. Cette demande ne confirme pas votre inscription.",
    anotherRequest: "Envoyer une autre demande",
    contactRequired:
      "Veuillez fournir un numéro de téléphone ou une adresse e-mail.",
    preferredContactRequired:
      "Veuillez renseigner les coordonnées correspondant à votre choix.",
    privacyRequired:
      "Veuillez accepter la notice de contact et de confidentialité.",
    genericError:
      "Impossible d'envoyer votre demande. Veuillez réessayer.",
    validationError:
      "Veuillez vérifier les informations saisies.",
    rateLimit:
      "Trop de demandes. Veuillez patienter avant de réessayer.",
    course: "Cours sélectionné",
  },
} as const;

export default function CourseRegistrationForm({
  apiBaseUrl,
  centerSlug,
  courseSlug,
  courseTitle,
  locale,
}: CourseRegistrationFormProps) {
  const t = translations[locale];

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault();

    if (isSubmitting) return;

    setError("");

    const form = event.currentTarget;
    const formData = new FormData(form);

    const name = String(formData.get("name") ?? "").trim();
    const phone = String(formData.get("phone") ?? "").trim();
    const email = String(formData.get("email") ?? "").trim();
    const contactMethod = String(
      formData.get("preferred_contact_method") ?? ""
    );
    const message = String(formData.get("message") ?? "").trim();

    const privacyAccepted =
      formData.get("privacy_notice_accepted") === "on";

    const website = String(formData.get("website") ?? "");

    // Basic validation before contacting the API.
    if (!phone && !email) {
      setError(t.contactRequired);
      return;
    }

    if (
      (contactMethod === "email" && !email) ||
      ((contactMethod === "phone" ||
        contactMethod === "whatsapp") &&
        !phone)
    ) {
      setError(t.preferredContactRequired);
      return;
    }

    if (!privacyAccepted) {
      setError(t.privacyRequired);
      return;
    }

    if (!apiBaseUrl || !centerSlug || !courseSlug) {
      setError(t.genericError);
      return;
    }

    setIsSubmitting(true);

    try {
      const baseUrl = new URL(apiBaseUrl);

      // Allow HTTP only for local development.
      const isLocal =
        ["localhost", "127.0.0.1", "::1"].includes(
          baseUrl.hostname
        );

      if (
        baseUrl.protocol !== "https:" &&
        !(
          process.env.NODE_ENV === "development" &&
          baseUrl.protocol === "http:" &&
          isLocal
        )
      ) {
        throw new Error("Invalid API protocol.");
      }

      const endpoint = new URL(
        `/api/public/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(courseSlug)}/leads`,
        baseUrl
      );

      const body = new URLSearchParams({
        name,
        phone,
        email,
        preferred_contact_method: contactMethod,
        message,
        privacy_notice_accepted: "1",
        website,
      });

      const response = await fetch(endpoint.toString(), {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body,
        credentials: "omit",
        cache: "no-store",
      });

      if (response.status === 429) {
        setError(t.rateLimit);
        return;
      }

      const payload: unknown = await response.json().catch(
        () => null
      );

      if (response.status === 422) {
        setError(
          payload &&
            typeof payload === "object" &&
            "message" in payload &&
            typeof payload.message === "string"
            ? payload.message
            : t.validationError
        );

        return;
      }

      if (!response.ok) {
        throw new Error("Request failed.");
      }

      setSubmitted(true);
      form.reset();
    } catch (caughtError) {
      console.error("Course registration error:", caughtError);
      setError(t.genericError);
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <section className="mx-auto max-w-4xl">
      <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-[0_16px_50px_rgba(9,36,67,0.07)]">

        <div className="bg-[#092443] px-6 py-8 text-white sm:px-10 sm:py-10">
          <span className="text-xs font-extrabold uppercase tracking-[0.17em] text-[#80d5d0]">
            {t.eyebrow}
          </span>

          <h2
            id="registration-heading"
            className="mt-3 text-2xl font-black sm:text-3xl"
          >
            {t.title}
          </h2>

          <p className="mt-3 max-w-xl text-sm leading-7 text-slate-300">
            {t.description}
          </p>

          <div className="mt-5 inline-flex max-w-full items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-3">
            <span className="text-xs text-slate-300">
              {t.course}:
            </span>

            <span className="truncate text-sm font-bold text-[#ffbd69]">
              {courseTitle}
            </span>
          </div>
        </div>

        <div className="p-6 sm:p-10" aria-live="polite">
          {submitted ? (
            <div className="py-5 text-center">
              <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e5f5f2] text-[#0e8988]">
                <svg
                  width="30"
                  height="30"
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

              <h3 className="mt-5 text-2xl font-black text-[#102b4c]">
                {t.successTitle}
              </h3>

              <p className="mx-auto mt-3 max-w-md text-sm leading-7 text-slate-600">
                {t.successMessage}
              </p>

              <button
                type="button"
                onClick={() => setSubmitted(false)}
                className="mt-7 min-h-12 rounded-full bg-[#092443] px-7 py-3 text-sm font-bold text-white transition hover:bg-[#123b64]"
              >
                {t.anotherRequest}
              </button>
            </div>
          ) : (
            <form
              onSubmit={handleSubmit}
              aria-labelledby="registration-heading"
              className="space-y-5"
            >
              {/* Honeypot field: hidden from normal users. */}
              <div
                aria-hidden="true"
                className="pointer-events-none absolute -left-[10000px] h-px w-px overflow-hidden"
              >
                <label htmlFor="registration-website">
                  Website
                </label>

                <input
                  id="registration-website"
                  name="website"
                  type="text"
                  tabIndex={-1}
                  autoComplete="off"
                />
              </div>

              <div>
                <label
                  htmlFor="registration-name"
                  className="mb-2 block text-sm font-bold text-[#173655]"
                >
                  {t.name} *
                </label>

                <input
                  id="registration-name"
                  name="name"
                  type="text"
                  autoComplete="name"
                  minLength={2}
                  maxLength={150}
                  required
                  className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
                  placeholder={t.name}
                />
              </div>

              <div className="grid gap-5 sm:grid-cols-2">
                <div>
                  <label
                    htmlFor="registration-phone"
                    className="mb-2 block text-sm font-bold text-[#173655]"
                  >
                    {t.phone}
                  </label>

                  <input
                    id="registration-phone"
                    name="phone"
                    type="tel"
                    autoComplete="tel"
                    maxLength={30}
                    className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
                    placeholder="+212 6..."
                  />
                </div>

                <div>
                  <label
                    htmlFor="registration-email"
                    className="mb-2 block text-sm font-bold text-[#173655]"
                  >
                    {t.email}
                  </label>

                  <input
                    id="registration-email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    maxLength={254}
                    className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
                    placeholder="name@example.com"
                  />
                </div>
              </div>

              <p className="-mt-2 text-xs text-slate-500">
                {t.contactRequired}
              </p>

              <div>
                <label
                  htmlFor="registration-contact-method"
                  className="mb-2 block text-sm font-bold text-[#173655]"
                >
                  {t.contactMethod}
                </label>

                <select
                  id="registration-contact-method"
                  name="preferred_contact_method"
                  defaultValue=""
                  className="min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
                >
                  <option value="">
                    {t.chooseMethod}
                  </option>

                  <option value="whatsapp">
                    {t.whatsapp}
                  </option>

                  <option value="phone">
                    {t.phoneCall}
                  </option>

                  <option value="email">
                    {t.emailContact}
                  </option>
                </select>
              </div>

              <div>
                <label
                  htmlFor="registration-message"
                  className="mb-2 block text-sm font-bold text-[#173655]"
                >
                  {t.message}
                </label>

                <textarea
                  id="registration-message"
                  name="message"
                  rows={4}
                  maxLength={2000}
                  placeholder={t.messagePlaceholder}
                  className="w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-7 outline-none transition focus:border-[#0e8988] focus:bg-white focus:ring-4 focus:ring-[#0e8988]/10"
                />
              </div>

              <label className="flex cursor-pointer items-start gap-3 rounded-xl bg-slate-50 p-4">
                <input
                  name="privacy_notice_accepted"
                  type="checkbox"
                  required
                  className="mt-1 h-4 w-4 shrink-0 accent-[#0e8988]"
                />

                <span className="text-xs leading-6 text-slate-600">
                  {t.privacy} *
                </span>
              </label>

              {error && (
                <p
                  role="alert"
                  className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700"
                >
                  {error}
                </p>
              )}

              <button
                type="submit"
                disabled={isSubmitting}
                className="flex min-h-13 w-full items-center justify-center gap-3 rounded-xl bg-[#f6a23b] px-6 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988] disabled:cursor-not-allowed disabled:opacity-60"
              >
                {isSubmitting ? t.submitting : t.submit}

                {!isSubmitting && (
                  <span aria-hidden="true">→</span>
                )}
              </button>

              <p className="text-center text-xs leading-6 text-slate-500">
                {locale === "en"
                  ? "Submitting this request does not confirm enrollment or require an online payment."
                  : "L'envoi de cette demande ne confirme pas l'inscription et ne nécessite aucun paiement en ligne."}
              </p>
            </form>
          )}
        </div>
      </div>
    </section>
  );
}

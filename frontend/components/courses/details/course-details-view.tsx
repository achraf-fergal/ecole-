
import Image from "next/image";
import Link from "next/link";
import CourseRegistrationForm from "@/components/courses/details/course-registration-form";
import CourseCurriculum from "@/components/courses/details/course-curriculum";

import type {
  CourseDetails,
  Locale,
} from "@/lib/courses";

/* =========================================================
   PAGE TRANSLATIONS
   Keep all UI labels here for easier editing.
========================================================= */

const translations = {
  en: {
    home: "Home",
    about: "About Us",
    courses: "Courses",
    contact: "Contact",
    preRegister: "Pre-registration",

    breadcrumb: "Course Details",
    level: "LEVEL",
    overview: "COURSE OVERVIEW",
    heroHighlights: "KEY LEARNING HIGHLIGHTS",

    requestEnrollment: "Request Enrollment",
    contactCenter: "Talk to our team",

    language: "Course Language",
    levelLabel: "Level",
    duration: "Duration",
    format: "Study Format",

    formats: {
      online: "Online",
      in_person: "In-person",
      hybrid: "Hybrid",
    },

    aboutTitle: "About This Course",
    outcomesTitle: "What You'll Learn",
    audienceTitle: "Who Is This Course For?",
    requirementsTitle: "Requirements",

    pricingTitle: "Join This Course",
    priceFixed: "Course price",
    priceFrom: "Starting from",
    free: "Free",
    uponRequest: "Contact for pricing",

    hours: "Hours",
    enrollmentNote:
      "Our admissions team will contact you to discuss the available options.",
    noPayment:
      "Submitting an enquiry does not confirm your enrollment.",

    groupsTitle: "Available Study Options",
    startDate: "Starts",
    endDate: "Ends",
    location: "Location",
    open: "Registration open",
    closed: "Registration closed",
    waitlist: "Waiting list",

    finalEyebrow: "YOUR NEXT LEARNING STEP",
    finalTitle: "Ready to Start Learning?",
    finalDescription:
      "Contact our team to discover the right study option for your goals.",
    finalButton: "Request Enrollment",

    footerDescription:
      "Discover language programmes and take your next learning step.",
    quickLinks: "Quick links",
    legal: "Legal",
    privacy: "Privacy policy",
    terms: "Terms and conditions",
    legalUnavailable: "Legal pages are being prepared.",
    rights: "All rights reserved.",

    noTranslation: "This course is not available in the selected language.",
    viewSource: "View the available language",
    backCourses: "Back to courses",

    notConfiguredTitle: "Course details are temporarily unavailable",
    notConfiguredDescription:
      "The course service has not been configured. Please contact the centre.",

    weekdays: {
      monday: "Monday",
      tuesday: "Tuesday",
      wednesday: "Wednesday",
      thursday: "Thursday",
      friday: "Friday",
      saturday: "Saturday",
      sunday: "Sunday",
    },
  },

  fr: {
    home: "Accueil",
    about: "À propos",
    courses: "Cours",
    contact: "Contact",
    preRegister: "Préinscription",

    breadcrumb: "Détails du cours",
    level: "NIVEAU",
    overview: "PRÉSENTATION DU COURS",
    heroHighlights: "POINTS CLÉS DE LA FORMATION",

    requestEnrollment: "Demander une inscription",
    contactCenter: "Contacter notre équipe",

    language: "Langue du cours",
    levelLabel: "Niveau",
    duration: "Durée",
    format: "Format d'étude",

    formats: {
      online: "En ligne",
      in_person: "En présentiel",
      hybrid: "Hybride",
    },

    aboutTitle: "À propos de ce cours",
    outcomesTitle: "Ce que vous apprendrez",
    audienceTitle: "À qui s'adresse ce cours ?",
    requirementsTitle: "Prérequis",

    pricingTitle: "Rejoindre ce cours",
    priceFixed: "Prix du cours",
    priceFrom: "À partir de",
    free: "Gratuit",
    uponRequest: "Tarif sur demande",

    hours: "heures",
    enrollmentNote:
      "Notre équipe vous contactera pour vous présenter les options disponibles.",
    noPayment:
      "Une demande de renseignements ne confirme pas votre inscription.",

    groupsTitle: "Options de formation disponibles",
    startDate: "Début",
    endDate: "Fin",
    location: "Lieu",
    open: "Inscriptions ouvertes",
    closed: "Inscriptions fermées",
    waitlist: "Liste d'attente",

    finalEyebrow: "VOTRE PROCHAINE ÉTAPE",
    finalTitle: "Prêt à commencer votre apprentissage ?",
    finalDescription:
      "Contactez notre équipe pour découvrir la formule adaptée à vos objectifs.",
    finalButton: "Demander une inscription",

    footerDescription:
      "Découvrez nos programmes de langues et avancez dans votre parcours.",
    quickLinks: "Liens rapides",
    legal: "Informations légales",
    privacy: "Politique de confidentialité",
    terms: "Conditions d'utilisation",
    legalUnavailable: "Les pages légales sont en préparation.",
    rights: "Tous droits réservés.",

    noTranslation: "Ce cours n'est pas disponible dans la langue sélectionnée.",
    viewSource: "Voir la langue disponible",
    backCourses: "Retour aux cours",

    notConfiguredTitle: "Les détails du cours sont temporairement indisponibles",
    notConfiguredDescription:
      "Le service des cours n'est pas configuré. Veuillez contacter le centre.",

    weekdays: {
      monday: "Lundi",
      tuesday: "Mardi",
      wednesday: "Mercredi",
      thursday: "Jeudi",
      friday: "Vendredi",
      saturday: "Samedi",
      sunday: "Dimanche",
    },
  },
} as const;

/* =========================================================
   SHARED HELPERS
========================================================= */

function courseHref(slug: string, locale: Locale): string {
  const path = `/courses/${encodeURIComponent(slug)}`;

  return locale === "fr"
    ? `${path}?lang=fr`
    : path;
}

function formatDuration(
  hours: number,
  locale: Locale
): string {
  return `${hours} ${locale === "en" ? "hours" : "heures"}`;
}

function formatMoney(
  amount: string,
  currency: string,
  locale: Locale
): string {
  const numericAmount = Number(amount);

  if (!Number.isFinite(numericAmount)) {
    return `${amount} ${currency}`;
  }

  try {
    return new Intl.NumberFormat(
      locale === "fr" ? "fr-MA" : "en-MA",
      {
        style: "currency",
        currency,
      }
    ).format(numericAmount);
  } catch {
    return `${amount} ${currency}`;
  }
}

function getPriceLabel(
  course: CourseDetails,
  locale: Locale
): string {
  const t = translations[locale];

  switch (course.pricing.mode) {
    case "fixed":
      return formatMoney(
        course.pricing.amount!,
        course.pricing.currency,
        locale
      );

    case "from":
      return `${t.priceFrom} ${formatMoney(
        course.pricing.amount!,
        course.pricing.currency,
        locale
      )}`;

    case "free":
      return t.free;

    default:
      return t.uponRequest;
  }
}

function formatDate(
  value: string,
  locale: Locale
): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat(
    locale === "fr" ? "fr-MA" : "en-MA",
    {
      dateStyle: "medium",
    }
  ).format(date);
}

/* =========================================================
   HEADER
========================================================= */

function CourseDetailsHeader({
  locale,
  course,
}: {
  locale: Locale;
  course: CourseDetails;
}) {
  const t = translations[locale];

  const availableLocales = course.localization.availableLocales.filter(
    (item): item is Locale => item === "en" || item === "fr"
  );

  return (
    <header className="sticky top-0 z-50 border-b border-white/10 bg-[#092443] text-white shadow-lg">
      <div className="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-5 py-3 sm:px-8 lg:px-12">

        {/* Brand */}
        <Link
          href="/"
          className="flex shrink-0 items-center gap-3"
        >
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#f6a23b] text-[#092443]">
            <svg
              width="25"
              height="25"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              aria-hidden="true"
            >
              <path d="M3 5.5A2.5 2.5 0 0 1 5.5 3H21v16H5.5A2.5 2.5 0 0 0 3 21.5z" />
              <path d="M7 8h9M7 12h8" />
            </svg>
          </span>

          <span>
            <span className="block text-lg font-extrabold leading-tight">
              Language Center
            </span>

            <span className="block text-[10px] tracking-wider text-slate-300">
              Learn. Progress. Achieve.
            </span>
          </span>
        </Link>

        {/* Desktop Navigation */}
        <nav
          aria-label="Main navigation"
          className="hidden items-center gap-7 lg:flex"
        >
          <Link
            href="/"
            className="text-sm font-semibold text-slate-200 hover:text-[#ffb451]"
          >
            {t.home}
          </Link>

          <Link
            href="/about"
            className="text-sm font-semibold text-slate-200 hover:text-[#ffb451]"
          >
            {t.about}
          </Link>

          <Link
            href="/courses"
            aria-current="page"
            className="border-b-2 border-[#0e8988] pb-2 text-sm font-extrabold text-white"
          >
            {t.courses}
          </Link>

          <Link
            href="/#contact"
            className="text-sm font-semibold text-slate-200 hover:text-[#ffb451]"
          >
            {t.contact}
          </Link>
        </nav>

        {/* Header Actions */}
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 rounded-full border border-white/20 px-2.5 py-2">
            {availableLocales.map((item) => (
              <Link
                key={item}
                href={courseHref(course.slug, item)}
                hrefLang={item}
                aria-current={item === locale ? "page" : undefined}
                className={`text-xs font-bold ${
                  item === locale
                    ? "text-[#f6a23b]"
                    : "text-slate-300 hover:text-white"
                }`}
              >
                {item.toUpperCase()}
              </Link>
            ))}
          </div>

          <Link
            href="#request-enrollment-form"
            className="rounded-full bg-[#f6a23b] px-4 py-3 text-xs font-extrabold text-[#102b4c] transition hover:bg-[#ffb451] sm:px-5"
          >
            {t.preRegister}
          </Link>
        </div>
      </div>
    </header>
  );
}

/* =========================================================
   HERO
========================================================= */

function CourseHero({
  course,
  locale,
}: {
  course: CourseDetails;
  locale: Locale;
}) {
  const t = translations[locale];
  const heroHighlights = course.outcomes
    .filter((outcome) => outcome.isHighlight)
    .slice(0, 3);

  return (
    <section className="bg-gradient-to-br from-white via-[#f1f7f9] to-[#eaf4f5]">
      <div className="mx-auto grid max-w-[1440px] items-center gap-10 px-5 py-10 sm:px-8 md:py-14 lg:grid-cols-[1.08fr_0.92fr] lg:px-12 lg:py-16">

        {/* Text Content */}
        <div>
          <nav
            aria-label="Breadcrumb"
            className="mb-7 flex flex-wrap items-center gap-2 text-xs font-medium text-slate-500"
          >
            <Link
              href="/"
              className="hover:text-[#0e8988]"
            >
              {t.home}
            </Link>

            <span aria-hidden="true">/</span>

            <Link
              href={locale === "fr" ? "/courses?lang=fr" : "/courses"}
              className="hover:text-[#0e8988]"
            >
              {t.courses}
            </Link>

            <span aria-hidden="true">/</span>

            <span
              aria-current="page"
              className="max-w-[180px] truncate font-semibold text-[#173655]"
            >
              {course.title}
            </span>
          </nav>

          <span className="text-xs font-extrabold tracking-[0.18em] text-[#0e8988]">
            {t.overview}
          </span>

          <div className="mt-4 flex flex-wrap items-center gap-2">
            <span className="rounded-full bg-[#e0f2ef] px-3 py-1.5 text-xs font-bold text-[#087b83]">
              {course.language.label}
            </span>

            <span className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600">
              {course.level.value.toUpperCase()}
              {" · "}
              {course.level.label}
            </span>
          </div>

          <h1 className="mt-5 max-w-2xl text-3xl font-black leading-tight tracking-tight text-[#102b4c] sm:text-4xl lg:text-5xl">
            {course.title}
          </h1>

          {course.shortDescription && (
            <p className="mt-5 max-w-xl text-sm leading-7 text-slate-600 sm:text-base">
              {course.shortDescription}
            </p>
          )}

          {heroHighlights.length > 0 && (
            <div className="mt-6">
              <p className="mb-3 text-[10px] font-extrabold uppercase tracking-[0.15em] text-[#0e8988]">
                {t.heroHighlights}
              </p>

              <ul className="grid gap-2 sm:grid-cols-2">
                {heroHighlights.map((outcome, index) => (
                  <li
                    key={`${index}-${outcome.content}`}
                    className="flex items-start gap-2 text-sm leading-6 text-slate-600"
                  >
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#e0f2ef] text-[#087b83]">
                      <svg
                        width="12"
                        height="12"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        aria-hidden="true"
                      >
                        <path d="m5 12 4 4L19 6" />
                      </svg>
                    </span>

                    <span>{outcome.content}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Link
              href="#request-enrollment-form"
              className="inline-flex min-h-12 items-center justify-center rounded-full bg-[#f6a23b] px-7 py-3 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988]"
            >
              {t.requestEnrollment}
              <span className="ml-2" aria-hidden="true">
                →
              </span>
            </Link>

            <a
              href="#course-overview"
              className="inline-flex min-h-12 items-center justify-center rounded-full border border-[#b9d6d6] bg-white px-7 py-3 text-sm font-bold text-[#087b83] transition hover:bg-[#edf8f7]"
            >
              {locale === "en"
                ? "Explore course details"
                : "Découvrir le cours"}
            </a>
          </div>

          <p className="mt-4 text-xs leading-5 text-slate-500">
            {t.enrollmentNote}
          </p>
        </div>

        {/* Course Cover Image */}
        <div className="relative">
          <div className="relative aspect-[4/3] overflow-hidden rounded-3xl bg-gradient-to-br from-[#dceef0] via-[#eaf2f4] to-[#d3e5eb] shadow-[0_24px_60px_rgba(9,36,67,0.12)] sm:aspect-[5/4]">
            {course.coverImage.url ? (
              <Image
                src={course.coverImage.url}
                alt={course.coverImage.alt || course.title}
                fill
                preload
                sizes="(max-width: 767px) 100vw, (max-width: 1279px) 45vw, 560px"
                className="object-cover"
              />
            ) : (
              <div className="flex h-full flex-col items-center justify-center gap-4 text-[#0e8988]">
                <svg
                  width="74"
                  height="74"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.1"
                  aria-hidden="true"
                >
                  <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z" />
                  <path d="M8 8h8M8 12h6M8 16h8" />
                </svg>

                <span className="text-xs font-extrabold uppercase tracking-widest">
                  {locale === "en"
                    ? "Language Programme"
                    : "Programme de langues"}
                </span>
              </div>
            )}

            <div className="pointer-events-none absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-[#092443]/30 to-transparent" />
          </div>

          <div className="absolute -bottom-4 -left-3 hidden rounded-2xl border border-white/80 bg-white/95 px-5 py-3 shadow-lg sm:block">
            <span className="text-xs font-bold text-[#0e8988]">
              {t.level}
            </span>

            <p className="mt-1 font-extrabold text-[#102b4c]">
              {course.level.value.toUpperCase()}
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

/* =========================================================
   COURSE FACTS
========================================================= */

function FactIcon({
  kind,
}: {
  kind: "language" | "level" | "duration" | "format";
}) {
  const common = {
    width: 23,
    height: 23,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.7,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    "aria-hidden": true as const,
  };

  if (kind === "language") {
    return (
      <svg {...common}>
        <path d="M4 5h12M10 3v2c0 6-3 10-7 12M5 10c2 3 5 5 8 6M14 21l4-11 4 11M15.5 17h5" />
      </svg>
    );
  }

  if (kind === "level") {
    return (
      <svg {...common}>
        <path d="m12 3 2.8 5.7L21 9.6l-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z" />
      </svg>
    );
  }

  if (kind === "duration") {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </svg>
    );
  }

  return (
    <svg {...common}>
      <rect x="3" y="4" width="18" height="13" rx="2" />
      <path d="M8 21h8M12 17v4M7 9h10M7 12h6" />
    </svg>
  );
}

function CourseFacts({
  course,
  locale,
}: {
  course: CourseDetails;
  locale: Locale;
}) {
  const t = translations[locale];

  const facts = [
    {
      key: "language",
      icon: "language" as const,
      label: t.language,
      value: course.language.label,
    },
    {
      key: "level",
      icon: "level" as const,
      label: t.levelLabel,
      value: `${course.level.value.toUpperCase()} · ${course.level.label}`,
    },
    ...(course.durationHours
      ? [
          {
            key: "duration",
            icon: "duration" as const,
            label: t.duration,
            value: formatDuration(course.durationHours, locale),
          },
        ]
      : []),
    ...(course.studyFormat
      ? [
          {
            key: "format",
            icon: "format" as const,
            label: t.format,
            value: t.formats[course.studyFormat],
          },
        ]
      : []),
  ];

  return (
    <section
      id="course-information"
      aria-label="Course information"
      className="mx-auto grid max-w-[1440px] grid-cols-2 gap-3 px-5 py-8 sm:px-8 md:grid-cols-4 lg:px-12"
    >
      {facts.map((fact) => (
        <div
          key={fact.key}
          className="rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_5px_20px_rgba(16,43,76,0.035)] sm:p-5"
        >
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#e8f5f3] text-[#0e8988]">
            <FactIcon kind={fact.icon} />
          </span>

          <p className="mt-4 text-xs font-semibold text-slate-500">
            {fact.label}
          </p>

          <p className="mt-1 break-words text-sm font-extrabold text-[#102b4c] sm:text-base">
            {fact.value}
          </p>
        </div>
      ))}
    </section>
  );
}

/* =========================================================
   PRICING CARD
========================================================= */

function CoursePricingCard({
  course,
  locale,
}: {
  course: CourseDetails;
  locale: Locale;
}) {
  const t = translations[locale];

  return (
    <aside
      aria-labelledby="course-pricing-heading"
      className="rounded-2xl border border-slate-200 bg-white p-6 shadow-[0_12px_35px_rgba(16,43,76,0.07)] lg:sticky lg:top-28"
    >
      <span className="inline-flex rounded-full bg-[#e5f5f2] px-3 py-1.5 text-[10px] font-extrabold uppercase tracking-widest text-[#087b83]">
        {t.pricingTitle}
      </span>

      <h2
        id="course-pricing-heading"
        className="mt-5 text-xl font-black text-[#102b4c]"
      >
        {course.title}
      </h2>

      <div className="mt-5">
        <p className="text-xs font-semibold text-slate-500">
          {course.pricing.mode === "fixed"
            ? t.priceFixed
            : course.pricing.mode === "from"
              ? t.priceFrom
              : locale === "en"
                ? "Pricing"
                : "Tarification"}
        </p>

        <p className="mt-2 text-2xl font-black tracking-tight text-[#102b4c]">
          {getPriceLabel(course, locale)}
        </p>
      </div>

      <div className="my-6 space-y-4 border-y border-slate-100 py-5">
        <div className="flex items-center justify-between gap-3 text-sm">
          <span className="text-slate-500">{t.language}</span>
          <span className="font-bold text-[#173655]">
            {course.language.label}
          </span>
        </div>

        <div className="flex items-center justify-between gap-3 text-sm">
          <span className="text-slate-500">{t.levelLabel}</span>
          <span className="text-right font-bold text-[#173655]">
            {course.level.value.toUpperCase()}
          </span>
        </div>

        {course.durationHours !== null && (
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="text-slate-500">{t.duration}</span>
            <span className="font-bold text-[#173655]">
              {formatDuration(course.durationHours, locale)}
            </span>
          </div>
        )}

        {course.studyFormat && (
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="text-slate-500">{t.format}</span>
            <span className="font-bold text-[#173655]">
              {t.formats[course.studyFormat]}
            </span>
          </div>
        )}
      </div>

      <Link
        href="#request-enrollment-form"
        className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#f6a23b] px-5 py-3 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988]"
      >
        {t.requestEnrollment}
        <span aria-hidden="true">→</span>
      </Link>

      <p className="mt-4 text-center text-xs leading-6 text-slate-500">
        {t.enrollmentNote}
      </p>

      <p className="mt-2 text-center text-[11px] leading-5 text-slate-400">
        {t.noPayment}
      </p>
    </aside>
  );
}

/* =========================================================
   TEXT CONTENT SECTIONS
========================================================= */

function SectionHeading({
    eyebrow,
    title,
    id,
  }: {
    eyebrow: string;
    title: string;
    id?: string;
  }) {
    return (
      <div className="mb-5">
        <span className="text-[10px] font-extrabold uppercase tracking-[0.17em] text-[#0e8988]">
          {eyebrow}
        </span>
  
        <h2
          id={id}
          className="mt-2 text-2xl font-black tracking-tight text-[#102b4c] sm:text-3xl"
        >
          {title}
        </h2>
      </div>
    );
  }

function CourseOutcomes({
  course,
  locale,
}: {
  course: CourseDetails;
  locale: Locale;
}) {
  if (course.outcomes.length === 0) return null;

  const t = translations[locale];

  return (
    <section aria-labelledby="outcomes-heading">
      <SectionHeading
        id="outcomes-heading"

        eyebrow="LEARNING OBJECTIVES"
        title={t.outcomesTitle}
      />

      <div className="grid gap-3 sm:grid-cols-2">
        {course.outcomes.map((outcome, index) => (
          <article
            key={`${index}-${outcome.content}`}
            className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-[#b4d9d5]"
          >
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e5f5f2] text-[#0e8988]">
              <svg
                width="17"
                height="17"
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
            </span>

            <p className="text-sm leading-6 text-slate-700">
              {outcome.content}
            </p>
          </article>
        ))}
      </div>
    </section>
  );
}

function CourseAudienceAndRequirements({
  course,
  locale,
}: {
  course: CourseDetails;
  locale: Locale;
}) {
  const t = translations[locale];

  const hasAudience = Boolean(course.targetAudience);
  const hasRequirements = Boolean(course.prerequisites);

  if (!hasAudience && !hasRequirements) return null;

  return (
    <section className="grid gap-4 sm:grid-cols-2">
      {hasAudience && (
        <article className="rounded-2xl border border-slate-200 bg-white p-6">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#e8f5f3] text-[#0e8988]">
            <svg
              width="21"
              height="21"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.7"
              aria-hidden="true"
            >
              <circle cx="9" cy="8" r="3" />
              <path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 4v2" />
            </svg>
          </span>

          <h2 className="mt-4 text-lg font-extrabold text-[#102b4c]">
            {t.audienceTitle}
          </h2>

          <p className="mt-3 text-sm leading-7 text-slate-600">
            {course.targetAudience}
          </p>
        </article>
      )}

      {hasRequirements && (
        <article className="rounded-2xl border border-slate-200 bg-white p-6">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#e8f5f3] text-[#0e8988]">
            <svg
              width="21"
              height="21"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.7"
              aria-hidden="true"
            >
              <path d="M5 3h14v18H5zM8 8h8M8 12h8M8 16h5" />
            </svg>
          </span>

          <h2 className="mt-4 text-lg font-extrabold text-[#102b4c]">
            {t.requirementsTitle}
          </h2>

          <p className="mt-3 text-sm leading-7 text-slate-600">
            {course.prerequisites}
          </p>
        </article>
      )}
    </section>
  );
}

/* =========================================================
   AVAILABLE GROUPS
   This section remains hidden while the API returns no groups.
========================================================= */

function CourseGroups({
  course,
  locale,
}: {
  course: CourseDetails;
  locale: Locale;
}) {
  if (course.availableGroups.length === 0) return null;

  const t = translations[locale];

  function dayLabel(day: string): string {
    const key = day.toLowerCase() as keyof typeof t.weekdays;

    return t.weekdays[key] ?? day;
  }

  function statusLabel(
    status: "open" | "closed" | "waitlist"
  ): string {
    if (status === "open") return t.open;
    if (status === "waitlist") return t.waitlist;

    return t.closed;
  }

  return (
    <section aria-labelledby="groups-heading">
      <SectionHeading
        id="groups-heading"

        eyebrow="STUDY OPTIONS"
        title={t.groupsTitle}
      />

      <div className="grid gap-4 md:grid-cols-2">
        {course.availableGroups.map((group) => (
          <article
            key={group.id}
            className="rounded-2xl border border-slate-200 bg-white p-5"
          >
            <div className="flex items-start justify-between gap-3">
              <h3 className="font-extrabold text-[#102b4c]">
                {group.name}
              </h3>

              <span
                className={`shrink-0 rounded-full px-3 py-1 text-[10px] font-bold ${
                  group.enrollmentStatus === "open"
                    ? "bg-[#e5f5f2] text-[#087b83]"
                    : "bg-slate-100 text-slate-600"
                }`}
              >
                {statusLabel(group.enrollmentStatus)}
              </span>
            </div>

            {(group.startDate || group.endDate) && (
              <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
                {group.startDate && (
                  <span>
                    {t.startDate}: {formatDate(group.startDate, locale)}
                  </span>
                )}

                {group.endDate && (
                  <span>
                    {t.endDate}: {formatDate(group.endDate, locale)}
                  </span>
                )}
              </div>
            )}

            {group.schedules.length > 0 && (
              <div className="mt-4 space-y-2">
                {group.schedules.map((schedule, index) => (
                  <div
                    key={`${group.id}-${index}`}
                    className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-xs"
                  >
                    <span className="font-semibold text-slate-600">
                      {dayLabel(schedule.dayOfWeek)}
                    </span>

                    <div className="text-right">
                      <span className="block font-bold text-[#173655]">
                        {schedule.startTime} – {schedule.endTime}
                      </span>

                      {schedule.locationLabel && (
                        <span className="mt-1 block text-[11px] text-slate-500">
                          {schedule.locationLabel}
                        </span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}

            {(group.locationLabel || group.roomLabel) && (
              <div className="mt-4 flex flex-wrap gap-2 text-xs text-slate-500">
                {group.locationLabel && (
                  <span>
                    {t.location}: {group.locationLabel}
                  </span>
                )}

                {group.roomLabel && (
                  <span>· {group.roomLabel}</span>
                )}
              </div>
            )}
          </article>
        ))}
      </div>
    </section>
  );
}

/* =========================================================
   FOOTER
========================================================= */

function CourseDetailsFooter({
  locale,
}: {
  locale: Locale;
}) {
  const t = translations[locale];

  return (
    <footer className="mt-20 bg-[#071d36] text-white">
      <div className="mx-auto grid max-w-[1440px] gap-10 px-5 py-12 sm:px-8 md:grid-cols-3 lg:px-12">

        <div>
          <Link href="/" className="text-xl font-extrabold">
            Language Center
          </Link>

          <p className="mt-4 max-w-xs text-sm leading-7 text-slate-400">
            {t.footerDescription}
          </p>
        </div>

        <div>
          <h2 className="font-extrabold">
            {t.quickLinks}
          </h2>

          <div className="mt-4 flex flex-col gap-3 text-sm text-slate-400">
            <Link href="/" className="hover:text-white">
              {t.home}
            </Link>

            <Link href="/about" className="hover:text-white">
              {t.about}
            </Link>

            <Link href="/courses" className="hover:text-white">
              {t.courses}
            </Link>

            <Link href="/#contact" className="hover:text-white">
              {t.contact}
            </Link>
          </div>
        </div>

        <div>
          <h2 className="font-extrabold">
            {t.legal}
          </h2>

          <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
            <span
              aria-disabled="true"
              title={t.legalUnavailable}
              className="cursor-not-allowed opacity-60"
            >
              {t.privacy}
            </span>
            <span
              aria-disabled="true"
              title={t.legalUnavailable}
              className="cursor-not-allowed opacity-60"
            >
              {t.terms}
            </span>
          </div>
        </div>
      </div>

      <div className="border-t border-white/10">
        <div className="mx-auto max-w-[1440px] px-5 py-5 text-xs text-slate-500 sm:px-8 lg:px-12">
        © Language Center. {t.rights}        </div>
      </div>
    </footer>
  );
}

/* =========================================================
   MAIN COURSE DETAILS VIEW
========================================================= */

export default function CourseDetailsView({
  course,
  locale,
  centerSlug,
  apiBaseUrl,
}: {
  course: CourseDetails;
  locale: Locale;
  centerSlug: string;
  apiBaseUrl: string;
}) {
  const t = translations[locale];

  const showCurriculum =
    course.curriculum.isPublic &&
    course.curriculum.modules.length > 0;

  return (
    <main
      lang={locale}
      className="min-h-screen bg-[#f8fafc] pb-24 text-[#102b4c] md:pb-0"
    >
      <CourseDetailsHeader
        locale={locale}
        course={course}
      />

      <CourseHero
        course={course}
        locale={locale}
      />

      <CourseFacts
        course={course}
        locale={locale}
      />

      {/* Main Content and Sticky Pricing */}
      <section
        id="course-overview"
        className="mx-auto max-w-[1440px] scroll-mt-28 px-5 py-10 sm:px-8 lg:px-12 lg:py-14"
      >
        <div className="grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_350px] lg:gap-12">

          {/* Main Course Content */}
          <div className="order-2 min-w-0 space-y-12 lg:order-1">

            {course.description && (
              <section aria-labelledby="about-course-heading">
                <SectionHeading
                  id="about-course-heading"
                  eyebrow="ABOUT THE PROGRAMME"
                  title={t.aboutTitle}
                />

                <div className="max-w-3xl">
                  <p className="whitespace-pre-line text-sm leading-8 text-slate-600 sm:text-base">
                    {course.description}
                  </p>
                </div>
              </section>
            )}

            {course.outcomes.length > 0 && (
              <CourseOutcomes
                course={course}
                locale={locale}
              />
            )}

            <CourseAudienceAndRequirements
              course={course}
              locale={locale}
            />

            {showCurriculum && (
              <CourseCurriculum
                modules={course.curriculum.modules}
                locale={locale}
              />
            )}

            {course.availableGroups.length > 0 && (
              <CourseGroups
                course={course}
                locale={locale}
              />
            )}
          </div>

          {/* Pricing is always visible, even without a fixed amount. */}
          <div className="order-1 lg:order-2">
            <CoursePricingCard
              course={course}
              locale={locale}
            />
          </div>
        </div>
      </section>

      {/* Course-specific registration form */}
      <section
        id="request-enrollment-form"
        className="mx-auto max-w-[1440px] scroll-mt-28 px-5 py-12 sm:px-8 lg:px-12 lg:py-16"
      >
        <CourseRegistrationForm
          apiBaseUrl={apiBaseUrl}
          centerSlug={centerSlug}
          courseSlug={course.slug}
          courseTitle={course.title}
          locale={locale}
        />
      </section>

      {/* Final Enrollment CTA */}
      <section
        id="request-enrollment"
        className="mx-auto max-w-[1440px] px-5 pb-16 sm:px-8 lg:px-12"
      >
        <div className="flex flex-col justify-between gap-7 rounded-3xl bg-[#092443] p-8 text-white sm:p-10 lg:flex-row lg:items-center lg:p-14">
          <div className="max-w-2xl">
            <span className="text-xs font-extrabold uppercase tracking-[0.17em] text-[#80d5d0]">
              {t.finalEyebrow}
            </span>

            <h2 className="mt-3 text-2xl font-black leading-tight sm:text-3xl lg:text-4xl">
              {t.finalTitle}
            </h2>

            <p className="mt-4 text-sm leading-7 text-slate-300">
              {t.finalDescription}
            </p>
          </div>

          <Link
            href="#request-enrollment-form"
            className="inline-flex min-h-12 shrink-0 items-center justify-center gap-3 rounded-full bg-[#f6a23b] px-7 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-0.5 hover:bg-[#ffb451]"
          >
            {t.finalButton}
            <span aria-hidden="true">→</span>
          </Link>
        </div>
      </section>

      <CourseDetailsFooter locale={locale} />

      <div
        role="region"
        aria-label={translations[locale].requestEnrollment}
        className="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 px-5 pt-3 shadow-[0_-8px_25px_rgba(9,36,67,0.08)] backdrop-blur-md md:hidden"
        style={{
          paddingBottom: "max(0.75rem, env(safe-area-inset-bottom))",
        }}
      >
        <div className="mx-auto flex max-w-[600px] items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="truncate text-[10px] font-semibold text-slate-500">
              {translations[locale].pricingTitle}
            </p>

            <p className="truncate text-sm font-black text-[#102b4c]">
              {getPriceLabel(course, locale)}
            </p>
          </div>

          <Link
            href="#request-enrollment-form"
            className="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-[#f6a23b] px-5 py-3 text-xs font-extrabold text-[#102b4c] transition hover:bg-[#ffb451] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#0e8988]"
          >
            {translations[locale].requestEnrollment}
            <span aria-hidden="true">→</span>
          </Link>
        </div>
      </div>
    </main>
  );
}

/* =========================================================
   SPECIAL ROUTE STATES
========================================================= */


export function CourseTranslationUnavailable({
  slug,
  sourceLocale,
  availableLocales,
}: {
  slug: string;
  sourceLocale: string;
  availableLocales: string[];
}) {
  const sourceHref =
    sourceLocale === "fr"
      ? `/courses/${encodeURIComponent(slug)}?lang=fr`
      : `/courses/${encodeURIComponent(slug)}`;

  const sourceLabel =
    sourceLocale === "fr" ? "Français" : "English";

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f8fafc] px-5 py-16">
      <div className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-10">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[#e8f5f3] text-[#0e8988]">
          <svg
            width="27"
            height="27"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            aria-hidden="true"
          >
            <path d="M4 5h16M12 3v2M7 5c0 7 4 11 9 13M17 5c-1 5-4 8-8 10M5 21h14" />
          </svg>
        </div>

        <h1 className="mt-6 text-2xl font-black text-[#102b4c]">
          Course translation unavailable
        </h1>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          This course has not been published in the selected language yet.
        </p>

        {availableLocales.includes(sourceLocale) && (
          <Link
            href={sourceHref}
            hrefLang={sourceLocale}
            className="mt-7 inline-flex min-h-12 items-center justify-center rounded-full bg-[#f6a23b] px-7 py-3 text-sm font-extrabold text-[#102b4c]"
          >
            View {sourceLabel} version
          </Link>
        )}

        <div className="mt-5">
          <Link
            href="/courses"
            className="text-sm font-bold text-[#0e8988] hover:underline"
          >
            Back to courses
          </Link>
        </div>
      </div>
    </main>
  );
}


export function CourseDetailsNotConfigured() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f8fafc] px-5 py-16">
      <div className="max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center sm:p-10">
        <h1 className="text-2xl font-black text-[#102b4c]">
          Course service is not configured
        </h1>

        <p className="mt-3 text-sm leading-7 text-slate-600">
          The Laravel API URL or center configuration is missing.
          Check the frontend environment variables.
        </p>

        <Link
          href="/courses"
          className="mt-7 inline-flex min-h-12 items-center justify-center rounded-full bg-[#092443] px-7 py-3 text-sm font-bold text-white"
        >
          Back to courses
        </Link>
      </div>
    </main>
  );
}

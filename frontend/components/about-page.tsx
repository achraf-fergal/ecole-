
"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";

type Locale = "en" | "fr";

const content = {
  en: {
    nav: ["Home", "About Us", "Courses", "Contact"],
    portal: "Student Portal",
    preRegister: "Pre-Registration",

    heroLabel: "GET TO KNOW US",
    heroTitle: "Every language opens",
    heroAccent: "a new world.",
    heroDescription:
      "Discover our story, our educational values and the passion behind creating meaningful language learning experiences.",
    heroButton: "Discover Our Story",

    storyLabel: "OUR JOURNEY",
    storyTitle: "Our story, one step at a time.",
    storyDescription:
      "Every learning journey has a beginning. Explore the milestones, experiences and ideas that have shaped our centre.",

    timeline: [
      {
        number: "01",
        title: "The Beginning",
        description:
          "Every centre has a story. Introduce how your institute started, the idea behind its creation and the educational need it wanted to address.",
        image: "/images/learning-classroom.svg",
        alt: "Teacher and learners in a classroom",
      },
      {
        number: "02",
        title: "Our First Steps",
        description:
          "Share the first programmes, early learning experiences and the important moments that helped shape the centre.",
        image: "/images/learning-books.svg",
        alt: "Students studying together",
      },
      {
        number: "03",
        title: "Growing Together",
        description:
          "Describe how your programmes, educational approach and learning environment have developed over time.",
        image: "/images/learning-together.svg",
        alt: "Students collaborating during a lesson",
      },
      {
        number: "04",
        title: "Where We Are Today",
        description:
          "Present the identity of your centre today, its commitment to learners and the direction in which it wants to grow.",
        image: "/images/learning-city.svg",
        alt: "Students learning in a modern environment",
      },
    ],

    missionLabel: "OUR PURPOSE",
    missionTitle: "What drives us.",
    missionDescription:
      "To create a supportive learning environment where students can develop practical language skills, grow in confidence and work towards their personal and professional goals.",

    visionLabel: "OUR FUTURE",
    visionTitle: "What we aspire to.",
    visionDescription:
      "To make language learning a meaningful experience that connects people with new opportunities, cultures and possibilities.",

    valuesLabel: "WHAT WE BELIEVE IN",
    valuesTitle: "Our values shape every learning experience.",
    valuesDescription:
      "The principles that guide our approach, our decisions and the way we support learners.",

    values: [
      {
        icon: "graduation",
        title: "Quality Education",
        description:
          "We value meaningful learning and educational experiences with clear objectives.",
      },
      {
        icon: "heart",
        title: "Student First",
        description:
          "We believe each learner deserves respect, guidance and encouragement.",
      },
      {
        icon: "sparkles",
        title: "Continuous Growth",
        description:
          "Learning never stops. We believe in improving and developing every day.",
      },
      {
        icon: "globe",
        title: "Open to the World",
        description:
          "Languages connect cultures, communities and new opportunities.",
      },
    ],

    testimonialsLabel: "STUDENT EXPERIENCES",
    testimonialsTitle: "Stories from our learners.",
    testimonialsDescription:
      "Every learning journey is different. Discover the experiences shared by our students.",

    testimonialPlaceholder:
      "An approved student testimonial will appear here.",
    testimonialEmpty:
      "Student stories will be published here once the centre adds approved testimonials.",

    ctaLabel: "YOUR NEXT CHAPTER",
    ctaTitle: "Ready to start your language journey?",
    ctaDescription:
      "Explore our programmes and find a learning experience that matches your goals.",
    ctaButton: "Explore Our Courses",
    contactButton: "Contact Us",

    footerTagline: "Learn. Progress. Achieve.",
    quickLinks: "Quick Links",
    legal: "Legal",
    privacy: "Privacy Policy",
    terms: "Terms & Conditions",
    rights: "All rights reserved.",
  },

  fr: {
    nav: ["Accueil", "À propos", "Cours", "Contact"],
    portal: "Espace étudiant",
    preRegister: "Préinscription",

    heroLabel: "DÉCOUVREZ NOTRE CENTRE",
    heroTitle: "Chaque langue ouvre",
    heroAccent: "un nouveau monde.",
    heroDescription:
      "Découvrez notre histoire, nos valeurs pédagogiques et notre passion pour une expérience d'apprentissage enrichissante.",
    heroButton: "Découvrir notre histoire",

    storyLabel: "NOTRE PARCOURS",
    storyTitle: "Notre histoire, étape par étape.",
    storyDescription:
      "Chaque parcours d'apprentissage a un début. Découvrez les moments et les idées qui ont façonné notre centre.",

    timeline: [
      {
        number: "01",
        title: "Les débuts",
        description:
          "Chaque centre possède une histoire. Présentez sa création, son idée fondatrice et les besoins auxquels il souhaitait répondre.",
        image: "/images/learning-classroom.svg",
        alt: "Enseignant et apprenants dans une salle de classe",
      },
      {
        number: "02",
        title: "Nos premiers pas",
        description:
          "Présentez les premiers programmes, les premières expériences d'apprentissage et les moments importants du centre.",
        image: "/images/learning-books.svg",
        alt: "Étudiants en train d'étudier",
      },
      {
        number: "03",
        title: "Grandir ensemble",
        description:
          "Racontez l'évolution de vos programmes, de votre approche pédagogique et de votre environnement d'apprentissage.",
        image: "/images/learning-together.svg",
        alt: "Étudiants travaillant ensemble",
      },
      {
        number: "04",
        title: "Notre centre aujourd'hui",
        description:
          "Présentez l'identité actuelle de votre centre, son engagement envers les apprenants et ses ambitions.",
        image: "/images/learning-city.svg",
        alt: "Étudiants dans un environnement moderne",
      },
    ],

    missionLabel: "NOTRE MISSION",
    missionTitle: "Ce qui nous motive.",
    missionDescription:
      "Créer un environnement d'apprentissage encourageant où chacun peut développer ses compétences linguistiques, gagner en confiance et atteindre ses objectifs personnels et professionnels.",

    visionLabel: "NOTRE VISION",
    visionTitle: "Notre ambition.",
    visionDescription:
      "Faire de l'apprentissage des langues une expérience enrichissante qui rapproche les personnes des cultures et des nouvelles opportunités.",

    valuesLabel: "NOS CONVICTIONS",
    valuesTitle: "Nos valeurs guident chaque expérience.",
    valuesDescription:
      "Les principes qui orientent notre approche et notre manière d'accompagner les apprenants.",

    values: [
      {
        icon: "graduation",
        title: "Qualité de l'enseignement",
        description:
          "Nous valorisons un apprentissage utile avec des objectifs pédagogiques clairs.",
      },
      {
        icon: "heart",
        title: "L'apprenant avant tout",
        description:
          "Chaque apprenant mérite respect, accompagnement et encouragement.",
      },
      {
        icon: "sparkles",
        title: "Évolution continue",
        description:
          "Nous croyons à l'amélioration et au développement permanents.",
      },
      {
        icon: "globe",
        title: "Ouverture sur le monde",
        description:
          "Les langues rapprochent les cultures et ouvrent de nouvelles possibilités.",
      },
    ],

    testimonialsLabel: "EXPÉRIENCES DES ÉTUDIANTS",
    testimonialsTitle: "Les expériences de nos apprenants.",
    testimonialsDescription:
      "Chaque parcours est différent. Découvrez les expériences partagées par nos étudiants.",

    testimonialPlaceholder:
      "Un témoignage d'étudiant validé apparaîtra ici.",
    testimonialEmpty:
      "Les témoignages seront publiés lorsque le centre aura ajouté des avis approuvés.",

    ctaLabel: "VOTRE PROCHAINE ÉTAPE",
    ctaTitle: "Prêt à commencer votre parcours linguistique ?",
    ctaDescription:
      "Découvrez nos programmes et trouvez une expérience adaptée à vos objectifs.",
    ctaButton: "Découvrir nos cours",
    contactButton: "Nous contacter",

    footerTagline: "Apprendre. Progresser. Réussir.",
    quickLinks: "Liens rapides",
    legal: "Informations légales",
    privacy: "Politique de confidentialité",
    terms: "Conditions d'utilisation",
    rights: "Tous droits réservés.",
  },
} as const;

type IconName =
  | "arrow"
  | "book"
  | "check"
  | "globe"
  | "graduation"
  | "heart"
  | "sparkles"
  | "target"
  | "menu"
  | "users"
  | "x";

function Icon({
  name,
  size = 21,
}: {
  name: IconName;
  size?: number;
}) {
  const icons: Record<IconName, React.ReactNode> = {
    arrow: (
      <>
        <path d="M5 12h14" />
        <path d="m12 5 7 7-7 7" />
      </>
    ),

    book: (
      <>
        <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21z" />
        <path d="M8 7h8M8 11h7" />
      </>
    ),

    check: <path d="m5 12 4 4L19 6" />,

    globe: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18" />
        <path d="M12 3a15 15 0 0 1 0 18" />
        <path d="M12 3a15 15 0 0 0 0 18" />
      </>
    ),

    graduation: (
      <>
        <path d="m2 10 10-6 10 6-10 6z" />
        <path d="M6 12v5c3.5 2.5 8.5 2.5 12 0v-5" />
      </>
    ),

    heart: (
      <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8z" />
    ),

    sparkles: (
      <>
        <path d="m12 3 1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7z" />
        <path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z" />
      </>
    ),

    target: (
      <>
        <circle cx="12" cy="12" r="9" />
        <circle cx="12" cy="12" r="5" />
        <circle cx="12" cy="12" r="1" />
      </>
    ),

    menu: (
      <>
        <path d="M4 7h16" />
        <path d="M4 12h16" />
        <path d="M4 17h16" />
      </>
    ),

    users: (
      <>
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M22 21v-2a4 4 0 0 0-3-3.8M16 3.2a4 4 0 0 1 0 7.6" />
      </>
    ),

    x: (
      <>
        <path d="m6 6 12 12" />
        <path d="M18 6 6 18" />
      </>
    ),
  };

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {icons[name]}
    </svg>
  );
}

function Brand() {
  return (
    <Link href="/" className="flex items-center gap-3">
      <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#f6a23b] text-[#092443]">
        <Icon name="graduation" size={25} />
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
  );
}

export default function AboutPage() {
  const [locale, setLocale] = useState<Locale>("en");
  const [menuOpen, setMenuOpen] = useState(false);

  const t = content[locale];

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const navTargets = ["/", "/about", "/courses", "/contact"];

  return (
    <main className="min-h-screen overflow-hidden bg-[#f8fafc] text-[#102b4c]">

      {/* HEADER */}
      <header className="sticky top-0 z-50 bg-[#092443] text-white shadow-lg">
        <div className="mx-auto flex max-w-[1440px] items-center justify-between gap-5 px-5 py-3 sm:px-8 lg:px-12">

          <Brand />

          <nav className="hidden items-center gap-7 lg:flex">
            {t.nav.map((item, index) => (
              <a
                key={item}
                href={navTargets[index]}
                className={`text-sm font-semibold transition hover:text-[#f6a23b] ${
                  index === 1 ? "text-[#ffb451]" : "text-slate-100"
                }`}
              >
                {item}
              </a>
            ))}
          </nav>

          <div className="hidden items-center gap-3 md:flex">
            <button
              type="button"
              onClick={() =>
                setLocale(locale === "en" ? "fr" : "en")
              }
              className="flex items-center gap-2 rounded-full border border-white/25 px-3 py-2 text-xs font-bold hover:bg-white/10"
              aria-label="Change language"
            >
              <Icon name="globe" size={16} />
              {locale.toUpperCase()}
            </button>

            <a
              href="/student/login"
              className="rounded-full border border-white/40 px-4 py-2.5 text-xs font-bold transition hover:bg-white/10"
            >
              {t.portal}
            </a>

            <Link
              href="/#contact"
              className="rounded-full bg-[#f6a23b] px-5 py-3 text-xs font-extrabold text-[#102b4c] transition hover:bg-[#ffb451]"
            >
              {t.preRegister}
            </Link>
          </div>

          <div className="flex items-center gap-2 md:hidden">
            <button
              type="button"
              onClick={() =>
                setLocale(locale === "en" ? "fr" : "en")
              }
              className="rounded-full border border-white/30 px-3 py-2 text-xs font-bold"
            >
              {locale.toUpperCase()}
            </button>

            <button
              type="button"
              onClick={() => setMenuOpen(!menuOpen)}
              className="rounded-xl border border-white/30 p-2"
              aria-label="Toggle navigation"
              aria-expanded={menuOpen}
            >
              <Icon name={menuOpen ? "x" : "menu"} size={21} />
            </button>
          </div>
        </div>

        {menuOpen && (
          <nav className="border-t border-white/10 bg-[#092443] px-5 py-4 md:hidden">
            <div className="flex flex-col gap-1">
              {t.nav.map((item, index) => (
                <a
                  key={item}
                  href={navTargets[index]}
                  onClick={() => setMenuOpen(false)}
                  className="rounded-xl px-3 py-3 text-sm font-semibold hover:bg-white/10"
                >
                  {item}
                </a>
              ))}

              <a
                href="/student/login"
                className="mt-2 rounded-xl border border-white/25 px-3 py-3 text-sm font-semibold"
              >
                {t.portal}
              </a>
            </div>
          </nav>
        )}
      </header>

      {/* HERO - SPLIT LAYOUT */}
      <section className="relative bg-gradient-to-br from-[#f9fbfd] via-[#eef5f8] to-[#e6f2f3]">
        <div className="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-14 sm:px-8 lg:grid-cols-2 lg:px-12 lg:py-24">

          <div className="max-w-2xl">
            <span className="inline-flex items-center gap-2 rounded-full border border-[#a6e1dd] bg-[#dff6f4] px-4 py-2 text-xs font-extrabold tracking-widest text-[#087b83]">
              <Icon name="sparkles" size={15} />
              {t.heroLabel}
            </span>

            <h1 className="mt-7 text-5xl font-black leading-[1.08] tracking-tight sm:text-6xl lg:text-7xl">
              {t.heroTitle}
              <span className="mt-2 block text-[#0e8988]">
                {t.heroAccent}
              </span>
            </h1>

            <p className="mt-6 max-w-xl text-lg leading-8 text-slate-600">
              {t.heroDescription}
            </p>

            <a
              href="#our-story"
              className="mt-8 inline-flex items-center justify-center gap-3 rounded-full bg-[#092b4d] px-7 py-4 font-bold text-white shadow-lg transition hover:-translate-y-1 hover:bg-[#15476e]"
            >
              {t.heroButton}
              <Icon name="arrow" size={18} />
            </a>
          </div>

          <div className="relative">
            <div className="absolute -left-8 top-10 h-48 w-48 rounded-full bg-[#0e8988]/10 blur-3xl" />

            <div className="relative h-[350px] overflow-hidden rounded-[2rem] shadow-2xl sm:h-[470px]">
              <Image
                src="/images/learning-classroom.svg"
                alt="Students learning together in a modern classroom"
                fill
                priority
                sizes="(max-width: 1024px) 100vw, 50vw"
                className="object-cover"
              />

              <div className="absolute inset-0 bg-gradient-to-t from-[#092443]/55 via-transparent to-transparent" />

              <div className="absolute bottom-7 left-7 right-7 text-white">
                <p className="text-xs font-extrabold tracking-[0.2em] text-[#ffd18b]">
                  LANGUAGE LEARNING
                </p>
                <p className="mt-2 max-w-md text-3xl font-black leading-tight">
                  Connecting people through languages.
                </p>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* STORY - EDITORIAL TIMELINE */}
      <section
        id="our-story"
        className="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:px-12 lg:py-28"
      >
        <div className="mx-auto mb-16 max-w-3xl text-center">
          <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">
            {t.storyLabel}
          </span>

          <h2 className="mt-4 text-3xl font-black tracking-tight text-[#102b4c] sm:text-5xl">
            {t.storyTitle}
          </h2>

          <p className="mx-auto mt-5 max-w-2xl leading-8 text-slate-600">
            {t.storyDescription}
          </p>
        </div>

        <div className="relative mx-auto max-w-6xl">

          {/* Editorial timeline line */}
          <div className="absolute bottom-12 left-[13px] top-4 w-[2px] bg-gradient-to-b from-[#0e8988] via-[#91d4cf] to-transparent sm:left-[23px]" />

          <div className="space-y-16 sm:space-y-24">
            {t.timeline.map((item, index) => (
              <article
                key={item.number}
                className="relative pl-10 sm:pl-16"
              >
                {/* Timeline point */}
                <span className="absolute left-0 top-5 z-10 flex h-7 w-7 items-center justify-center rounded-full border-[5px] border-[#f8fafc] bg-[#0e8988] ring-2 ring-[#a9ded8] sm:left-[10px]" />

                <div
                  className={`grid items-center gap-7 rounded-[2rem] border border-slate-200/80 bg-white p-4 shadow-[0_12px_40px_rgba(16,43,76,0.04)] transition hover:shadow-lg sm:p-6 md:grid-cols-2 md:gap-10 ${
                    index % 2 === 1 ? "md:[direction:rtl]" : ""
                  }`}
                >
                  <div className={index % 2 === 1 ? "md:[direction:ltr]" : ""}>
                    <div className="relative h-[220px] overflow-hidden rounded-2xl sm:h-[300px]">
<Image
src={item.image}
alt={item.alt}
fill
sizes="(max-width: 768px) 100vw, 50vw"
loading={index === 0 ? "eager" : "lazy"}
className="object-cover transition duration-700 hover:scale-105"
/>
                    </div>
                  </div>

                  <div
                    className={`flex flex-col justify-center px-2 py-5 sm:px-5 ${
                      index % 2 === 1 ? "md:[direction:ltr]" : ""
                    }`}
                  >
                    <span className="text-sm font-black tracking-[0.2em] text-[#0e8988]">
                      MILESTONE {item.number}
                    </span>

                    <h3 className="mt-4 text-2xl font-black leading-tight text-[#102b4c] sm:text-3xl">
                      {item.title}
                    </h3>

                    <p className="mt-5 text-sm leading-8 text-slate-600 sm:text-base">
                      {item.description}
                    </p>

                    <div className="mt-6 flex items-center gap-2 text-sm font-bold text-[#0e8988]">
                      <span className="h-[2px] w-8 bg-[#0e8988]" />
                      {locale === "en" ? "Our journey" : "Notre parcours"}
                    </div>
                  </div>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* MISSION & VISION */}
      <section className="bg-white py-20 lg:py-24">
        <div className="mx-auto max-w-[1440px] px-5 sm:px-8 lg:px-12">
          <div className="grid gap-6 lg:grid-cols-2">

            <article className="relative overflow-hidden rounded-[2rem] bg-[#092443] p-8 text-white sm:p-11">
              <div className="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-[#0e8988]/20 blur-3xl" />

              <div className="relative">
                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-[#80d5d0]">
                  <Icon name="target" size={28} />
                </div>

                <p className="mt-7 text-xs font-extrabold tracking-[0.2em] text-[#80d5d0]">
                  {t.missionLabel}
                </p>

                <h2 className="mt-3 text-3xl font-black">
                  {t.missionTitle}
                </h2>

                <p className="mt-5 max-w-xl leading-8 text-slate-300">
                  {t.missionDescription}
                </p>
              </div>
            </article>

            <article className="relative overflow-hidden rounded-[2rem] bg-[#e9f5f4] p-8 text-[#102b4c] sm:p-11">
              <div className="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-[#f6a23b]/20 blur-3xl" />

              <div className="relative">
                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-[#0e8988] shadow-sm">
                  <Icon name="globe" size={28} />
                </div>

                <p className="mt-7 text-xs font-extrabold tracking-[0.2em] text-[#0e8988]">
                  {t.visionLabel}
                </p>

                <h2 className="mt-3 text-3xl font-black">
                  {t.visionTitle}
                </h2>

                <p className="mt-5 max-w-xl leading-8 text-slate-600">
                  {t.visionDescription}
                </p>
              </div>
            </article>

          </div>
        </div>
      </section>

      {/* VALUES */}
      <section className="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:px-12 lg:py-24">
        <div className="mx-auto mb-12 max-w-2xl text-center">
          <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">
            {t.valuesLabel}
          </span>

          <h2 className="mt-4 text-3xl font-black sm:text-4xl">
            {t.valuesTitle}
          </h2>

          <p className="mt-4 leading-8 text-slate-600">
            {t.valuesDescription}
          </p>
        </div>

        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
          {t.values.map((value) => (
            <article
              key={value.title}
              className="group rounded-[1.7rem] border border-slate-200 bg-white p-7 transition duration-300 hover:-translate-y-1 hover:border-[#9ddbd5] hover:bg-[#f4fbfa] hover:shadow-xl"
            >
              <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-[#e1f5f2] text-[#0e8988] transition group-hover:bg-[#0e8988] group-hover:text-white">
                <Icon name={value.icon as IconName} size={26} />
              </div>

              <h3 className="mt-6 text-xl font-extrabold">
                {value.title}
              </h3>

              <p className="mt-3 text-sm leading-7 text-slate-600">
                {value.description}
              </p>
            </article>
          ))}
        </div>
      </section>

      {/* TESTIMONIALS - CARDS */}
      <section className="bg-[#eef5f7] py-20 lg:py-24">
        <div className="mx-auto max-w-[1440px] px-5 sm:px-8 lg:px-12">

          <div className="mx-auto mb-12 max-w-2xl text-center">
            <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">
              {t.testimonialsLabel}
            </span>

            <h2 className="mt-4 text-3xl font-black sm:text-4xl">
              {t.testimonialsTitle}
            </h2>

            <p className="mt-4 leading-8 text-slate-600">
              {t.testimonialsDescription}
            </p>
          </div>

          {/* Empty testimonial slots: real approved reviews will replace these. */}
          <div className="grid gap-5 md:grid-cols-3">
            {[1, 2, 3].map((item) => (
              <article
                key={item}
                className="flex min-h-[245px] flex-col justify-between rounded-[1.7rem] border border-slate-200 bg-white p-7 shadow-sm"
              >
                <div>
                  <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#e1f5f2] text-[#0e8988]">
                    <Icon name="users" size={23} />
                  </span>

                  <p className="mt-6 text-base font-semibold leading-7 text-[#173655]">
                    {t.testimonialPlaceholder}
                  </p>
                </div>

                <div className="mt-6 border-t border-slate-100 pt-4">
                  <span className="text-xs font-bold text-slate-400">
                    {locale === "en"
                      ? "Approved student story"
                      : "Témoignage étudiant validé"}
                  </span>
                </div>
              </article>
            ))}
          </div>

          <p className="mt-6 text-center text-xs text-slate-500">
            {t.testimonialEmpty}
          </p>
        </div>
      </section>

      {/* CTA */}
      <section className="px-5 py-20 sm:px-8 lg:px-12">
        <div className="relative mx-auto max-w-[1440px] overflow-hidden rounded-[2rem] bg-gradient-to-r from-[#092443] to-[#0d5269] px-7 py-12 text-white sm:px-12 sm:py-16 lg:px-16">
          <div className="absolute -right-20 -top-24 h-72 w-72 rounded-full bg-[#0e8988]/25 blur-3xl" />
          <div className="absolute -bottom-24 left-1/3 h-60 w-60 rounded-full bg-[#f6a23b]/15 blur-3xl" />

          <div className="relative flex flex-col justify-between gap-8 lg:flex-row lg:items-center">
            <div className="max-w-2xl">
              <span className="text-xs font-extrabold tracking-[0.2em] text-[#80d5d0]">
                {t.ctaLabel}
              </span>

              <h2 className="mt-4 text-3xl font-black leading-tight sm:text-4xl lg:text-5xl">
                {t.ctaTitle}
              </h2>

              <p className="mt-5 leading-8 text-slate-300">
                {t.ctaDescription}
              </p>
            </div>

            <div className="flex shrink-0 flex-col gap-3 sm:flex-row lg:flex-col">
              <a
                href="/courses"
                className="inline-flex items-center justify-center gap-3 rounded-full bg-[#f6a23b] px-7 py-4 text-sm font-extrabold text-[#102b4c] transition hover:-translate-y-1 hover:bg-[#ffb451]"
              >
                {t.ctaButton}
                <Icon name="arrow" size={17} />
              </a>

              <a
                href="/contact"
                className="inline-flex items-center justify-center rounded-full border border-white/40 px-7 py-4 text-sm font-bold text-white transition hover:bg-white/10"
              >
                {t.contactButton}
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* FOOTER */}
      <footer className="bg-[#071d36] text-white">
        <div className="mx-auto grid max-w-[1440px] gap-10 px-5 py-14 sm:px-8 md:grid-cols-2 lg:grid-cols-4 lg:px-12">

          <div>
            <Brand />

            <p className="mt-5 max-w-xs text-sm leading-7 text-slate-400">
              {t.footerTagline}
            </p>
          </div>

          <div>
            <h3 className="font-extrabold">{t.quickLinks}</h3>

            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <Link href="/" className="hover:text-white">{t.nav[0]}</Link>
              <a href="/about" className="hover:text-white">{t.nav[1]}</a>
              <a href="/courses" className="hover:text-white">{t.nav[2]}</a>
              <a href="/contact" className="hover:text-white">{t.nav[3]}</a>
            </div>
          </div>

          <div>
            <h3 className="font-extrabold">{t.legal}</h3>

            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <a href="/privacy" className="hover:text-white">{t.privacy}</a>
              <a href="/terms" className="hover:text-white">{t.terms}</a>
              <a href="/student/login" className="hover:text-white">{t.portal}</a>
            </div>
          </div>

          <div>
            <h3 className="font-extrabold">Language Center</h3>

            <p className="mt-5 text-sm leading-7 text-slate-400">
              {t.heroDescription}
            </p>
          </div>
        </div>

        <div className="border-t border-white/10">
          <div className="mx-auto flex max-w-[1440px] flex-col justify-between gap-3 px-5 py-5 text-xs text-slate-500 sm:px-8 md:flex-row lg:px-12">
            <span>© Language Center. {t.rights}</span>
            <span>{t.footerTagline}</span>
          </div>
        </div>
      </footer>
    </main>
  );
}

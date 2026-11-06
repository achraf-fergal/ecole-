
"use client";

import Image from "next/image";
import { Suspense, useEffect, useState } from "react";

type Locale = "en" | "fr";

const content = {
  en: {
    nav: ["Home", "Courses", "About us", "Teachers", "FAQ", "Contact"],
    portal: "Student Portal",
    preRegister: "Pre-Registration",
    heroLabel: "LANGUAGE CENTER · CASABLANCA",
    heroTitle: "Learn a Language.",
    heroAccent: "Achieve Your Goals.",
    heroDescription:
      "Quality language courses with expert teachers and a supportive learning environment.",
    explore: "Explore Our Courses",
    contact: "Contact Us",
    programsLabel: "FIND YOUR PATH",
    programsTitle: "Explore Our Programmes",
    programsDescription:
      "Discover language programmes designed for your personal, academic and professional goals.",
    allCourses: "View All Courses",
    level: "Available levels",
    benefitsLabel: "THE LEARNING EXPERIENCE",
    benefitsTitle: "Why Choose Our Center?",
    benefitsDescription:
      "A supportive environment designed to help you learn and progress with confidence.",
    benefits: [
      {
        title: "Expert Teachers",
        description: "Learn with qualified and experienced teachers.",
        icon: "graduation",
      },
      {
        title: "Personalized Learning",
        description: "Explore programmes suited to your goals and level.",
        icon: "users",
      },
      {
        title: "Flexible Learning",
        description: "Ask about available schedules and study formats.",
        icon: "clock",
      },
      {
        title: "Ongoing Support",
        description: "Get guidance throughout your learning journey.",
        icon: "support",
      },
    ],
    approachLabel: "OUR APPROACH",
    approachTitle: "Learning That Moves You Forward",
    approachDescription:
      "Every learning journey starts with a goal. Discover the programme, understand its objectives and get guidance from the centre.",
    approachPoints: [
      "Programmes adapted to different learning objectives.",
      "Guidance to help you explore the right level.",
      "Clear information before confirming your enrolment.",
    ],
    approachButton: "Discover Our Courses",
    stepsLabel: "GETTING STARTED",
    stepsTitle: "How to Start Your Learning Journey",
    stepsDescription:
      "Our registration process is simple. The centre team will guide you through each step.",
    steps: [
      {
        title: "Choose Your Course",
        description: "Explore available languages and programmes.",
      },
      {
        title: "Send Your Request",
        description: "Complete the pre-registration form.",
      },
      {
        title: "Confirm With Us",
        description: "Our team will contact you with the next steps.",
      },
    ],
    faqLabel: "NEED MORE INFORMATION?",
    faqTitle: "Frequently Asked Questions",
    faqs: [
      {
        q: "How do I choose the right language level?",
        a: "Explore the course details and contact the centre. The team can explain the available levels and help you choose the appropriate programme.",
      },
      {
        q: "Does pre-registration guarantee my place?",
        a: "No. Pre-registration is an initial request. The centre will contact you to discuss availability, schedules, fees and the confirmation process.",
      },
      {
        q: "Can I study online or in person?",
        a: "Available study formats depend on the centre and the selected programme. Contact the team for current options.",
      },
      {
        q: "How do I access the Student Portal?",
        a: "After the centre confirms your enrolment and activates your account, you can access your courses through the Student Portal.",
      },
    ],
    contactLabel: "LET'S TALK",
    contactTitle: "Ready to Take the Next Step?",
    contactDescription:
      "Tell us what you want to learn. Our team can help you explore the available programmes.",
    contactButton: "Contact Our Team",
    footerTagline: "Learn. Progress. Achieve.",
    quickLinks: "Quick Links",
    programmes: "Our Programmes",
    legal: "Legal",
    privacy: "Privacy Policy",
    terms: "Terms & Conditions",
    footerNote:
      "A learning experience designed around your goals. Programme information is managed by each centre.",
    copyright: "All rights reserved.",
  },

  fr: {
    nav: ["Accueil", "Cours", "À propos", "Enseignants", "FAQ", "Contact"],
    portal: "Espace étudiant",
    preRegister: "Préinscription",
    heroLabel: "CENTRE DE LANGUES · CASABLANCA",
    heroTitle: "Apprenez une langue.",
    heroAccent: "Réalisez vos objectifs.",
    heroDescription:
      "Des cours de langues de qualité avec des enseignants qualifiés et un environnement d'apprentissage stimulant.",
    explore: "Découvrir nos cours",
    contact: "Nous contacter",
    programsLabel: "TROUVEZ VOTRE PARCOURS",
    programsTitle: "Découvrez nos programmes",
    programsDescription:
      "Des programmes de langues adaptés à vos objectifs personnels, académiques et professionnels.",
    allCourses: "Voir tous les cours",
    level: "Niveaux disponibles",
    benefitsLabel: "L'EXPÉRIENCE D'APPRENTISSAGE",
    benefitsTitle: "Pourquoi choisir notre centre ?",
    benefitsDescription:
      "Un environnement adapté pour apprendre et progresser en toute confiance.",
    benefits: [
      {
        title: "Enseignants qualifiés",
        description: "Apprenez avec des enseignants expérimentés.",
        icon: "graduation",
      },
      {
        title: "Apprentissage personnalisé",
        description: "Des programmes adaptés à vos objectifs.",
        icon: "users",
      },
      {
        title: "Flexibilité",
        description: "Renseignez-vous sur les horaires et formats disponibles.",
        icon: "clock",
      },
      {
        title: "Accompagnement",
        description: "Un accompagnement tout au long de votre parcours.",
        icon: "support",
      },
    ],
    approachLabel: "NOTRE APPROCHE",
    approachTitle: "Apprendre pour avancer",
    approachDescription:
      "Chaque parcours commence par un objectif. Découvrez le programme, ses objectifs pédagogiques et les conseils du centre.",
    approachPoints: [
      "Des programmes adaptés à différents objectifs.",
      "Un accompagnement pour choisir le bon niveau.",
      "Des informations claires avant de confirmer votre inscription.",
    ],
    approachButton: "Découvrir nos cours",
    stepsLabel: "POUR COMMENCER",
    stepsTitle: "Comment commencer votre parcours ?",
    stepsDescription:
      "Notre processus de préinscription est simple. L'équipe du centre vous accompagne à chaque étape.",
    steps: [
      {
        title: "Choisissez votre cours",
        description: "Découvrez les langues et programmes disponibles.",
      },
      {
        title: "Envoyez votre demande",
        description: "Remplissez le formulaire de préinscription.",
      },
      {
        title: "Confirmez avec nous",
        description: "Notre équipe vous contactera pour la suite.",
      },
    ],
    faqLabel: "BESOIN D'INFORMATIONS ?",
    faqTitle: "Questions fréquentes",
    faqs: [
      {
        q: "Comment choisir mon niveau de langue ?",
        a: "Consultez les détails des cours et contactez le centre. Son équipe pourra vous présenter les niveaux disponibles.",
      },
      {
        q: "La préinscription garantit-elle ma place ?",
        a: "Non. La préinscription est une première demande. Le centre vous contactera pour discuter des disponibilités, des horaires, des tarifs et de la confirmation.",
      },
      {
        q: "Puis-je étudier en ligne ou en présentiel ?",
        a: "Les formats disponibles dépendent du centre et du programme choisi. Contactez l'équipe pour connaître les options actuelles.",
      },
      {
        q: "Comment accéder à l'espace étudiant ?",
        a: "Après confirmation de votre inscription et activation de votre compte, vous pourrez accéder à vos cours.",
      },
    ],
    contactLabel: "ÉCHANGEONS",
    contactTitle: "Prêt à commencer ?",
    contactDescription:
      "Expliquez-nous ce que vous souhaitez apprendre. Notre équipe vous aidera à découvrir les programmes disponibles.",
    contactButton: "Contacter notre équipe",
    footerTagline: "Apprendre. Progresser. Réussir.",
    quickLinks: "Liens rapides",
    programmes: "Nos programmes",
    legal: "Informations légales",
    privacy: "Politique de confidentialité",
    terms: "Conditions d'utilisation",
    footerNote:
      "Une expérience d'apprentissage adaptée à vos objectifs. Les informations sont gérées par chaque centre.",
    copyright: "Tous droits réservés.",
  },
} as const;

const programmes = [
  {
    id: "english",
    title: "English",
    description: "General English, Business English and exam preparation.",
    image: "/images/learning-books.svg",
    alt: "Books and English learning materials",
    code: "EN",
  },
  {
    id: "french",
    title: "French",
    description: "Communication, academic and professional French.",
    image: "/images/learning-city.svg",
    alt: "Paris architecture",
    code: "FR",
  },
  {
    id: "german",
    title: "German",
    description: "German language programmes for different levels.",
    image: "/images/learning-city.svg",
    alt: "German city architecture",
    code: "DE",
  },
];

type IconName = "arrow" | "book" | "check" | "clock" | "graduation" | "globe" | "mail" | "menu" | "support" | "users" | "x";

function Icon({
  name,
  size = 20,
}: {
  name: IconName;
  size?: number;
}) {
  const paths: Record<IconName, React.ReactNode> = {
    arrow: <path d="M5 12h14m-7-7 7 7-7 7" />,
    book: <><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21z" /><path d="M8 7h8M8 11h7" /></>,
    check: <path d="m5 12 4 4L19 6" />,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    graduation: <><path d="m2 10 10-6 10 6-10 6z" /><path d="M6 12v5c3.5 2.5 8.5 2.5 12 0v-5" /></>,
    globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18" /></>,
    mail: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></>,
    menu: <><path d="M4 7h16M4 12h16M4 17h16" /></>,
    support: <><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><path d="M4 13h3v6H5a2 2 0 0 1-2-2v-2a2 2 0 0 1 1-2zM20 13h-3v6h2a2 2 0 0 0 2-2v-2a2 2 0 0 0-1-2z" /></>,
    users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.8M16 3.2a4 4 0 0 1 0 7.6" /></>,
    x: <path d="m6 6 12 12M18 6 6 18" />,
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
      {paths[name]}
    </svg>
  );
}
function CurrentYear() {
  return <>{new Date().getFullYear()}</>;
}

export default function HomePage() {
  const [locale, setLocale] = useState<Locale>("en");
  const [menuOpen, setMenuOpen] = useState(false);

  const t = content[locale];

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  return (
    <main className="min-h-screen overflow-hidden bg-[#f8fafc] text-[#102b4c]">

      {/* NAVIGATION */}
      <header className="sticky top-0 z-50 bg-[#092443] text-white shadow-lg">
        <div className="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-5 py-3 sm:px-8 lg:px-12">

          <a href="#home" className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#f6a23b] text-[#092443]">
              <Icon name="graduation" size={28} />
            </div>
            <div>
              <div className="text-lg font-extrabold">Language Center</div>
              <div className="text-[10px] tracking-wider text-slate-300">
                {t.footerTagline}
              </div>
            </div>
          </a>

          <nav className="hidden items-center gap-6 lg:flex">
            {t.nav.map((item, index) => (
              <a
                key={item}
                href={["#home", "#programs", "#about", "#approach", "#faq", "#contact"][index]}
                className="text-sm font-semibold transition hover:text-[#f6a23b]"
              >
                {item}
              </a>
            ))}
          </nav>

          <div className="hidden items-center gap-3 md:flex">
            <button
              type="button"
              onClick={() => setLocale(locale === "en" ? "fr" : "en")}
              className="flex items-center gap-2 rounded-full border border-white/25 px-3 py-2 text-sm font-bold"
            >
              <Icon name="globe" size={17} />
              {locale.toUpperCase()}
            </button>

            <a
              href="/student/login"
              className="rounded-full border border-white/40 px-4 py-2.5 text-xs font-bold hover:bg-white/10"
            >
              {t.portal}
            </a>

            <a
              href="#contact"
              className="rounded-full bg-[#f6a23b] px-5 py-3 text-xs font-extrabold text-[#102b4c] transition hover:bg-[#ffb451]"
            >
              {t.preRegister}
            </a>
          </div>

          <div className="flex items-center gap-2 md:hidden">
            <button
              type="button"
              onClick={() => setLocale(locale === "en" ? "fr" : "en")}
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
              <Icon name={menuOpen ? "x" : "menu"} />
            </button>
          </div>
        </div>

        {menuOpen && (
          <nav className="flex flex-col gap-1 border-t border-white/10 p-4 lg:hidden">
            {t.nav.map((item, index) => (
              <a
                key={item}
                href={["#home", "#programs", "#about", "#approach", "#faq", "#contact"][index]}
                onClick={() => setMenuOpen(false)}
                className="rounded-lg px-3 py-3 text-sm font-semibold hover:bg-white/10"
              >
                {item}
              </a>
            ))}
            <a href="/student/login" className="px-3 py-3 text-sm font-semibold">
              {t.portal}
            </a>
          </nav>
        )}
      </header>

      {/* HERO */}
      <section id="home" className="relative bg-gradient-to-br from-[#f9fbfd] via-[#eef5f8] to-[#e3f1f3]">
        <div className="mx-auto grid max-w-[1440px] items-center gap-10 px-5 py-14 sm:px-8 lg:grid-cols-2 lg:px-12 lg:py-20">

          <div className="max-w-2xl">
            <span className="inline-flex rounded-full border border-[#a6e1dd] bg-[#dff6f4] px-4 py-2 text-xs font-extrabold uppercase tracking-wider text-[#087b83]">
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

            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <a
                href="#programs"
                className="inline-flex items-center justify-center gap-3 rounded-full bg-[#092b4d] px-7 py-4 font-bold text-white transition hover:bg-[#15476e]"
              >
                {t.explore}
                <Icon name="arrow" />
              </a>

              <a
                href="#contact"
                className="inline-flex items-center justify-center gap-3 rounded-full border border-[#aabcc9] bg-white px-7 py-4 font-bold text-[#173655] transition hover:border-[#0e8988]"
              >
                <Icon name="mail" />
                {t.contact}
              </a>
            </div>

            <div className="mt-7 flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <Icon name="check" size={17} />
              {t.heroDescription}
            </div>
          </div>

          <div className="relative h-[350px] overflow-hidden rounded-[2rem] shadow-2xl sm:h-[450px] lg:h-[510px]">
            <Image
              src="/images/learning-classroom.svg"
              alt="Students learning together in a classroom"
              fill
              priority
              sizes="(max-width: 1024px) 100vw, 50vw"
              className="object-cover"
            />

            <div className="absolute inset-0 bg-gradient-to-t from-[#092443]/70 via-transparent to-transparent" />

            <div className="absolute bottom-7 left-7 text-white">
              <p className="text-sm font-bold uppercase tracking-widest text-[#ffd18b]">
                LANGUAGE LEARNING
              </p>
              <h2 className="mt-2 text-3xl font-black">
                Your next chapter starts here.
              </h2>
            </div>
          </div>
        </div>
      </section>

      {/* PROGRAMMES */}
      <section id="programs" className="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:px-12">
        <div className="mb-10 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
          <div>
            <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">
              {t.programsLabel}
            </span>
            <h2 className="mt-3 text-3xl font-black sm:text-4xl">
              {t.programsTitle}
            </h2>
            <p className="mt-3 max-w-2xl text-slate-600">
              {t.programsDescription}
            </p>
          </div>

          <a href="/courses" className="flex items-center gap-2 font-bold text-[#0b688d]">
            {t.allCourses} <Icon name="arrow" />
          </a>
        </div>

        {/* Temporary preview data. Replace with published Laravel API courses later. */}
        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          {programmes.map((programme) => (
            <article
              key={programme.id}
              className="group overflow-hidden rounded-[1.7rem] border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl"
            >
              <div className="relative h-52 overflow-hidden">
                <Image
                  src={programme.image}
                  alt={programme.alt}
                  fill
                  sizes="(max-width: 768px) 100vw, 33vw"
                  className="object-cover transition duration-700 group-hover:scale-105"
                />

                <span className="absolute bottom-4 left-4 rounded-full bg-white/95 px-3 py-1 text-xs font-extrabold text-[#102b4c]">
                  {programme.code}
                </span>
              </div>

              <div className="p-6">
                <h3 className="text-2xl font-extrabold">
                  {programme.id === "english"
                    ? locale === "en" ? "English" : "Anglais"
                    : programme.id === "french"
                    ? locale === "en" ? "French" : "Français"
                    : locale === "en" ? "German" : "Allemand"}
                </h3>

                <p className="mt-3 min-h-12 text-sm leading-6 text-slate-600">
                  {programme.description}
                </p>

                <div className="mt-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
                  <Icon name="graduation" size={16} />
                  {t.level}
                </div>

                <a
                  href="/courses"
                  className="mt-6 inline-flex items-center gap-2 font-extrabold text-[#086b98] hover:gap-3"
                >
                  {locale === "en" ? "Explore programme" : "Découvrir le programme"}
                  <Icon name="arrow" size={16} />
                </a>
              </div>
            </article>
          ))}
        </div>
      </section>

      {/* BENEFITS */}
      <section id="about" className="bg-white py-20">
        <div className="mx-auto max-w-[1440px] px-5 sm:px-8 lg:px-12">
          <div className="mx-auto mb-12 max-w-2xl text-center">
            <span className="text-xs font-extrabold uppercase tracking-widest text-[#0e8988]">
              {t.benefitsLabel}
            </span>

            <h2 className="mt-3 text-3xl font-black sm:text-4xl">
              {t.benefitsTitle}
            </h2>

            <p className="mt-4 leading-7 text-slate-600">
              {t.benefitsDescription}
            </p>
          </div>

          <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            {t.benefits.map((benefit) => (
              <article
                key={benefit.title}
                className="rounded-2xl border border-slate-200 bg-[#f8fafc] p-6 transition hover:border-[#9ad8d4] hover:bg-[#f2fbfa]"
              >
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#e1f5f2] text-[#0e8988]">
                  <Icon name={benefit.icon as IconName} size={24} />
                </div>

                <h3 className="mt-5 text-lg font-extrabold">
                  {benefit.title}
                </h3>

                <p className="mt-2 text-sm leading-6 text-slate-600">
                  {benefit.description}
                </p>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* LEARNING APPROACH */}
      <section id="approach" className="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-20 sm:px-8 lg:grid-cols-2 lg:px-12">
        <div className="relative h-[330px] overflow-hidden rounded-[2rem] shadow-xl sm:h-[440px]">
          <Image
            src="/images/learning-together.svg"
            alt="Teacher explaining a lesson to students"
            fill
            sizes="(max-width: 1024px) 100vw, 50vw"
            className="object-cover"
          />
        </div>

        <div className="max-w-xl">
          <span className="text-xs font-extrabold uppercase tracking-widest text-[#0e8988]">
            {t.approachLabel}
          </span>

          <h2 className="mt-3 text-3xl font-black sm:text-4xl">
            {t.approachTitle}
          </h2>

          <p className="mt-5 leading-8 text-slate-600">
            {t.approachDescription}
          </p>

          <div className="mt-7 space-y-4">
            {t.approachPoints.map((point) => (
              <div key={point} className="flex items-start gap-3">
                <span className="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#dcf4ef] text-[#0b827d]">
                  <Icon name="check" size={15} />
                </span>

                <p className="text-sm leading-6 text-slate-700">
                  {point}
                </p>
              </div>
            ))}
          </div>

          <a
            href="#programs"
            className="mt-8 inline-flex items-center gap-3 rounded-full bg-[#092b4d] px-7 py-4 font-bold text-white hover:bg-[#15476e]"
          >
            {t.approachButton}
            <Icon name="arrow" />
          </a>
        </div>
      </section>

      {/* REGISTRATION STEPS */}
      <section className="bg-[#092443] py-20 text-white">
        <div className="mx-auto max-w-[1440px] px-5 sm:px-8 lg:px-12">
          <div className="mx-auto mb-12 max-w-2xl text-center">
            <span className="text-xs font-extrabold uppercase tracking-widest text-[#80d5d0]">
              {t.stepsLabel}
            </span>

            <h2 className="mt-3 text-3xl font-black sm:text-4xl">
              {t.stepsTitle}
            </h2>

            <p className="mt-4 leading-7 text-slate-300">
              {t.stepsDescription}
            </p>
          </div>

          <div className="grid gap-5 md:grid-cols-3">
            {t.steps.map((step, index) => (
              <article
                key={step.title}
                className="rounded-3xl border border-white/15 bg-white/[0.06] p-7"
              >
                <div className="text-4xl font-black text-[#f6a23b]">
                  0{index + 1}
                </div>

                <h3 className="mt-6 text-xl font-extrabold">
                  {step.title}
                </h3>

                <p className="mt-3 text-sm leading-7 text-slate-300">
                  {step.description}
                </p>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* FAQ */}
      <section id="faq" className="mx-auto max-w-[1000px] px-5 py-20 sm:px-8">
        <div className="mb-10 text-center">
          <span className="text-xs font-extrabold uppercase tracking-widest text-[#0e8988]">
            {t.faqLabel}
          </span>

          <h2 className="mt-3 text-3xl font-black sm:text-4xl">
            {t.faqTitle}
          </h2>
        </div>

        <div className="space-y-3">
          {t.faqs.map((faq) => (
            <details
              key={faq.q}
              className="group rounded-2xl border border-slate-200 bg-white p-5 open:border-[#9ad8d4]"
            >
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-bold text-[#173655]">
                {faq.q}

                <span className="text-xl text-[#0e8988] transition group-open:rotate-180">
                  ⌄
                </span>
              </summary>

              <p className="pt-4 text-sm leading-7 text-slate-600">
                {faq.a}
              </p>
            </details>
          ))}
        </div>
      </section>

      {/* CONTACT CTA */}
      <section id="contact" className="px-5 pb-20 sm:px-8 lg:px-12">
        <div className="mx-auto flex max-w-[1440px] flex-col justify-between gap-7 rounded-[2rem] bg-[#e8f4f5] p-8 sm:p-12 lg:flex-row lg:items-center lg:p-16">
          <div className="max-w-2xl">
            <span className="text-xs font-extrabold uppercase tracking-widest text-[#0e8988]">
              {t.contactLabel}
            </span>

            <h2 className="mt-3 text-3xl font-black text-[#102b4c] sm:text-4xl">
              {t.contactTitle}
            </h2>

            <p className="mt-4 leading-7 text-slate-600">
              {t.contactDescription}
            </p>
          </div>

          <a
            href="/contact"
            className="inline-flex shrink-0 items-center justify-center gap-3 rounded-full bg-[#f6a23b] px-7 py-4 font-extrabold text-[#172b43] transition hover:bg-[#ffb451]"
          >
            <Icon name="mail" />
            {t.contactButton}
          </a>
        </div>
      </section>

      {/* FOOTER */}
      <footer className="bg-[#071d36] text-white">
        <div className="mx-auto grid max-w-[1440px] gap-10 px-5 py-14 sm:px-8 md:grid-cols-2 lg:grid-cols-4 lg:px-12">
          <div>
            <div className="flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#f6a23b] text-[#092443]">
                <Icon name="graduation" size={27} />
              </div>

              <div>
                <h3 className="text-lg font-extrabold">Language Center</h3>
                <p className="text-xs text-slate-400">
                  {t.footerTagline}
                </p>
              </div>
            </div>

            <p className="mt-5 max-w-xs text-sm leading-7 text-slate-400">
              {t.footerNote}
            </p>
          </div>

          <div>
            <h3 className="font-extrabold">{t.quickLinks}</h3>
            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <a href="#home" className="hover:text-white">{t.nav[0]}</a>
              <a href="#programs" className="hover:text-white">{t.nav[1]}</a>
              <a href="#about" className="hover:text-white">{t.nav[2]}</a>
              <a href="#contact" className="hover:text-white">{t.nav[5]}</a>
            </div>
          </div>

          <div>
            <h3 className="font-extrabold">{t.programmes}</h3>
            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <span>English</span>
              <span>French</span>
              <span>German</span>
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
        </div>

        <div className="border-t border-white/10">
          <div className="mx-auto flex max-w-[1440px] flex-col justify-between gap-3 px-5 py-5 text-xs text-slate-500 sm:px-8 md:flex-row lg:px-12">
            <span>
              © <Suspense fallback={null}><CurrentYear /></Suspense> Language Center. {t.copyright}
            </span>
            <span>Learn. Progress. Achieve.</span>
          </div>
        </div>
      </footer>
    </main>
  );
}

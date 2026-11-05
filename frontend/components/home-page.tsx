"use client";

import Image from "next/image";
import { useEffect, useState, type ReactNode } from "react";

type Locale = "en" | "fr";
type IconName =
  | "arrow"
  | "book"
  | "check"
  | "chevron"
  | "clock"
  | "globe"
  | "graduation"
  | "mail"
  | "map"
  | "menu"
  | "phone"
  | "play"
  | "sparkles"
  | "users"
  | "x";

const copy = {
  en: {
    nav: {
      home: "Home",
      courses: "Courses",
      about: "About us",
      approach: "Our approach",
      faq: "FAQ",
      contact: "Contact",
      portal: "Student portal",
      preRegister: "Pre-registration",
    },
    hero: {
      eyebrow: "LANGUAGE CENTER · CASABLANCA",
      titleStart: "Learn a language.",
      titleEnd: "Achieve your goals.",
      description:
        "Build confidence, discover new opportunities, and learn at a pace that works for you.",
      primary: "Explore our courses",
      secondary: "Talk to our team",
      note: "Find a programme that fits your goals",
    },
    programs: {
      eyebrow: "FIND YOUR PATH",
      title: "Explore our programmes",
      description:
        "Discover language programmes for study, work, travel, and everyday communication.",
      all: "Explore programmes",
      details: "Explore programme",
      english: "English",
      englishDescription:
        "Develop practical communication skills for everyday and professional situations.",
      french: "French",
      frenchDescription:
        "Build confidence in conversation, writing, and day-to-day communication.",
      german: "German",
      germanDescription:
        "Take your first steps or continue building your German language skills.",
      level: "Levels and options depend on the centre",
    },
    benefits: {
      eyebrow: "THE LEARNING EXPERIENCE",
      title: "A learning experience built around you",
      description:
        "The right learning environment makes it easier to stay focused and keep making progress.",
      goals: "Learning goals",
      goalsText: "Choose a programme that matches what you want to achieve.",
      levels: "Different levels",
      levelsText: "Explore learning options suited to your current level.",
      guidance: "Personal guidance",
      guidanceText: "Get in touch with the centre when you need help choosing.",
      progress: "A clear next step",
      progressText: "Know what happens from your first request to enrolment.",
    },
    approach: {
      eyebrow: "MORE THAN A COURSE",
      title: "Make every learning step count",
      description:
        "Explore the programme, speak with the centre, and get the information you need before confirming your enrolment.",
      point1: "Understand the programme and its learning objectives.",
      point2: "Ask about levels, schedules, and available study formats.",
      point3: "Get support from the centre throughout the registration process.",
      cta: "Find your course",
      imageLabel: "A supportive learning environment",
    },
    steps: {
      eyebrow: "GETTING STARTED",
      title: "Your journey starts with three simple steps",
      description:
        "Sending a request is the first step. The centre team will help you with the next details.",
      one: "Choose a programme",
      oneText: "Explore the languages and courses that interest you.",
      two: "Send your request",
      twoText: "Share your contact details and the programme you prefer.",
      three: "Confirm with the centre",
      threeText: "The team will discuss your level, schedule, and payment.",
      cta: "Start your request",
    },
    faq: {
      eyebrow: "NEED TO KNOW MORE?",
      title: "Frequently asked questions",
      q1: "How do I choose the right level?",
      a1: "Explore the course information, then contact the centre. Its team can explain the available levels and help you decide on the next step.",
      q2: "Does a pre-registration confirm my place?",
      a2: "No. A pre-registration is a request for information. The centre will contact you to discuss availability, schedules, fees, and the confirmation process.",
      q3: "Can I ask about schedules and study formats?",
      a3: "Yes. Contact the centre to ask which schedules and study formats are currently available for the programme you are interested in.",
      q4: "How do I access the student portal?",
      a4: "Once the centre confirms your enrolment and activates your student access, you can sign in through the Student Portal.",
    },
    contact: {
      eyebrow: "LET'S TALK",
      title: "Not sure where to start?",
      description:
        "Tell the centre what you want to learn. Its team can help you explore the available options.",
      cta: "Contact the centre",
      portal: "Student portal",
    },
    footer: {
      tagline: "Learn. Progress. Achieve.",
      links: "Quick links",
      programmes: "Programmes",
      legal: "Legal",
      privacy: "Privacy policy",
      terms: "Terms and conditions",
      note: "Centre information and programmes are managed by each language centre.",
    },
  },
  fr: {
    nav: {
      home: "Accueil",
      courses: "Cours",
      about: "À propos",
      approach: "Notre approche",
      faq: "FAQ",
      contact: "Contact",
      portal: "Espace étudiant",
      preRegister: "Préinscription",
    },
    hero: {
      eyebrow: "CENTRE DE LANGUES · CASABLANCA",
      titleStart: "Apprenez une langue.",
      titleEnd: "Réalisez vos objectifs.",
      description:
        "Gagnez en confiance, découvrez de nouvelles opportunités et apprenez à votre rythme.",
      primary: "Découvrir nos cours",
      secondary: "Parler à notre équipe",
      note: "Trouvez un programme adapté à vos objectifs",
    },
    programs: {
      eyebrow: "TROUVEZ VOTRE PARCOURS",
      title: "Découvrez nos programmes",
      description:
        "Des programmes de langues pour les études, le travail, les voyages et la communication quotidienne.",
      all: "Découvrir les programmes",
      details: "Découvrir le programme",
      english: "Anglais",
      englishDescription:
        "Développez vos compétences de communication au quotidien et dans un contexte professionnel.",
      french: "Français",
      frenchDescription:
        "Gagnez en confiance à l’oral, à l’écrit et dans les échanges de tous les jours.",
      german: "Allemand",
      germanDescription:
        "Découvrez les bases ou continuez à développer vos compétences en allemand.",
      level: "Niveaux et options selon le centre",
    },
    benefits: {
      eyebrow: "L'EXPÉRIENCE D'APPRENTISSAGE",
      title: "Un apprentissage pensé pour vous",
      description:
        "Un environnement adapté vous aide à rester motivé et à progresser à votre rythme.",
      goals: "Vos objectifs",
      goalsText: "Choisissez un programme adapté à ce que vous souhaitez accomplir.",
      levels: "Plusieurs niveaux",
      levelsText: "Découvrez les options correspondant à votre niveau actuel.",
      guidance: "Un accompagnement",
      guidanceText: "Contactez le centre pour être conseillé dans votre choix.",
      progress: "Une prochaine étape claire",
      progressText: "Comprenez le parcours, de votre demande jusqu'à l'inscription.",
    },
    approach: {
      eyebrow: "BIEN PLUS QU'UN COURS",
      title: "Donnez du sens à chaque étape",
      description:
        "Découvrez le programme, échangez avec le centre et obtenez les informations nécessaires avant de confirmer votre inscription.",
      point1: "Comprenez le programme et ses objectifs pédagogiques.",
      point2: "Renseignez-vous sur les niveaux, horaires et formats disponibles.",
      point3: "Bénéficiez de l'accompagnement du centre pendant l'inscription.",
      cta: "Trouver un cours",
      imageLabel: "Un environnement d'apprentissage convivial",
    },
    steps: {
      eyebrow: "POUR COMMENCER",
      title: "Votre parcours débute en trois étapes",
      description:
        "La préinscription est une première demande. L'équipe du centre vous accompagnera pour la suite.",
      one: "Choisissez un programme",
      oneText: "Découvrez les langues et les cours qui vous intéressent.",
      two: "Envoyez votre demande",
      twoText: "Indiquez vos coordonnées et le programme souhaité.",
      three: "Confirmez avec le centre",
      threeText: "L'équipe échangera avec vous sur le niveau, les horaires et le paiement.",
      cta: "Envoyer une demande",
    },
    faq: {
      eyebrow: "BESOIN D'INFORMATIONS ?",
      title: "Questions fréquentes",
      q1: "Comment choisir le bon niveau ?",
      a1: "Consultez les informations du cours, puis contactez le centre. Son équipe pourra vous présenter les niveaux disponibles et vous orienter.",
      q2: "La préinscription confirme-t-elle ma place ?",
      a2: "Non. La préinscription est une demande d'information. Le centre vous contactera pour discuter des disponibilités, des horaires, des tarifs et de la confirmation.",
      q3: "Puis-je demander les horaires et les formats des cours ?",
      a3: "Oui. Contactez le centre pour connaître les horaires et les formats actuellement proposés pour le programme souhaité.",
      q4: "Comment accéder à l'espace étudiant ?",
      a4: "Après confirmation de votre inscription et activation de votre accès, vous pourrez vous connecter depuis l'espace étudiant.",
    },
    contact: {
      eyebrow: "ÉCHANGEONS",
      title: "Vous ne savez pas par où commencer ?",
      description:
        "Expliquez au centre ce que vous souhaitez apprendre. Son équipe pourra vous présenter les possibilités.",
      cta: "Contacter le centre",
      portal: "Espace étudiant",
    },
    footer: {
      tagline: "Apprendre. Progresser. Réussir.",
      links: "Liens rapides",
      programmes: "Programmes",
      legal: "Informations légales",
      privacy: "Politique de confidentialité",
      terms: "Conditions d'utilisation",
      note: "Les informations et les programmes sont gérés par chaque centre de langues.",
    },
  },
} as const;

const programs = [
  {
    key: "english",
    image:
      "https://images.unsplash.com/photo-1519682337058-a94d519337bc?auto=format&fit=crop&w=900&q=85",
    imageAlt: "Books and study materials on a desk",
    accent: "from-sky-100 to-blue-50",
    label: "EN",
  },
  {
    key: "french",
    image:
      "https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=format&fit=crop&w=900&q=85",
    imageAlt: "Paris city view",
    accent: "from-rose-100 to-orange-50",
    label: "FR",
  },
  {
    key: "german",
    image:
      "https://images.unsplash.com/photo-1467269204594-9661b134dd2b?auto=format&fit=crop&w=900&q=85",
    imageAlt: "European city architecture",
    accent: "from-amber-100 to-yellow-50",
    label: "DE",
  },
] as const;

function Icon({
  name,
  size = 20,
  className = "",
}: {
  name: IconName;
  size?: number;
  className?: string;
}) {
  const common = {
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.8,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    className,
    "aria-hidden": true as const,
  };

  const drawings: Record<IconName, ReactNode> = {
    arrow: <><path d="M4 12h15" /><path d="m13 5 7 7-7 7" /></>,
    book: <><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z" /><path d="M4 5.5v16" /><path d="M8 7h8" /><path d="M8 11h7" /></>,
    check: <path d="m5 12 4 4L19 6" />,
    chevron: <path d="m7 10 5 5 5-5" />,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a15 15 0 0 1 0 18" /><path d="M12 3a15 15 0 0 0 0 18" /></>,
    graduation: <><path d="m2 10 10-6 10 6-10 6z" /><path d="M6 12.5V17c3.5 2.5 8.5 2.5 12 0v-4.5" /><path d="M22 10v6" /></>,
    mail: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></>,
    map: <><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z" /><path d="M9 3v15" /><path d="M15 6v15" /></>,
    menu: <><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></>,
    phone: <><path d="M7 3h3l2 5-2 2a15 15 0 0 0 4 4l2-2 5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 5 5a2 2 0 0 1 2-2z" /></>,
    play: <><circle cx="12" cy="12" r="9" /><path d="m10 8 6 4-6 4z" /></>,
    sparkles: <><path d="m12 3 1.7 5.3L19 10l-5.3 1.7L12 17l-1.7-5.3L5 10l5.3-1.7z" /><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z" /></>,
    users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></>,
    x: <><path d="m6 6 12 12" /><path d="M18 6 6 18" /></>,
  };

  return <svg {...common}>{drawings[name]}</svg>;
}

function BrandMark() {
  return (
    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#f4a340] text-[#102d4e] shadow-[0_8px_20px_rgba(244,163,64,0.22)]">
      <Icon name="book" size={25} />
    </div>
  );
}

export default function HomePage() {
  const [locale, setLocale] = useState<Locale>("en");
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const t = copy[locale];

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const navLinks = [
    { label: t.nav.home, href: "#home" },
    { label: t.nav.courses, href: "#programs" },
    { label: t.nav.about, href: "#about" },
    { label: t.nav.approach, href: "#approach" },
    { label: t.nav.faq, href: "#faq" },
    { label: t.nav.contact, href: "#contact" },
  ];

  return (
    <main className="min-h-screen overflow-hidden bg-[#f8fafc] text-[#102b4c]">
      <header className="sticky top-0 z-50 border-b border-white/10 bg-[#092443] text-white shadow-[0_8px_30px_rgba(6,26,49,0.12)]">
        <div className="mx-auto flex max-w-[1440px] items-center justify-between gap-5 px-5 py-3 sm:px-8 lg:px-12">
          <a href="#home" className="flex shrink-0 items-center gap-3" aria-label="Language Center home">
            <BrandMark />
            <span className="leading-tight">
              <span className="block text-lg font-extrabold tracking-tight sm:text-xl">Language Center</span>
              <span className="block text-[10px] font-medium tracking-[0.16em] text-slate-300 sm:text-xs">
                {t.footer.tagline}
              </span>
            </span>
          </a>

          <nav className="hidden items-center gap-6 lg:flex" aria-label="Main navigation">
            {navLinks.map((link, index) => (
              <a
                key={link.href}
                href={link.href}
                className={`text-sm font-semibold transition-colors hover:text-[#ffb24f] ${index === 0 ? "text-[#ffb24f]" : "text-slate-100"}`}
              >
                {link.label}
              </a>
            ))}
          </nav>

          <div className="hidden items-center gap-3 md:flex">
            <div className="flex items-center gap-1 rounded-full border border-white/20 p-1">
              <Icon name="globe" size={16} />
              <button
                type="button"
                onClick={() => setLocale(locale === "en" ? "fr" : "en")}
                className="flex items-center gap-1 px-2 py-1 text-xs font-bold"
                aria-label={locale === "en" ? "Switch to French" : "Passer en anglais"}
              >
                {locale.toUpperCase()} <Icon name="chevron" size={13} />
              </button>
            </div>
            <a
              href="/student/login"
              className="inline-flex items-center gap-2 rounded-full border border-white/35 px-4 py-2.5 text-xs font-bold transition hover:bg-white/10"
            >
              <Icon name="users" size={15} />
              {t.nav.portal}
            </a>
            <a
              href="#contact"
              className="inline-flex items-center gap-2 rounded-full bg-[#f6a23b] px-5 py-3 text-xs font-extrabold text-[#172b43] shadow-lg shadow-orange-500/20 transition hover:-translate-y-0.5 hover:bg-[#ffb451]"
            >
              <Icon name="book" size={15} />
              {t.nav.preRegister}
            </a>
          </div>

          <div className="flex items-center gap-2 md:hidden">
            <button
              type="button"
              onClick={() => setLocale(locale === "en" ? "fr" : "en")}
              className="rounded-full border border-white/25 px-3 py-2 text-xs font-bold"
              aria-label="Change language"
            >
              {locale.toUpperCase()}
            </button>
            <button
              type="button"
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="rounded-xl border border-white/25 p-2"
              aria-label={mobileMenuOpen ? "Close menu" : "Open menu"}
              aria-expanded={mobileMenuOpen}
            >
              <Icon name={mobileMenuOpen ? "x" : "menu"} size={22} />
            </button>
          </div>
        </div>

        {mobileMenuOpen && (
          <nav className="border-t border-white/10 bg-[#092443] px-5 py-4 md:hidden" aria-label="Mobile navigation">
            <div className="mx-auto flex max-w-[1440px] flex-col gap-1">
              {navLinks.map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  onClick={() => setMobileMenuOpen(false)}
                  className="rounded-xl px-3 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                >
                  {link.label}
                </a>
              ))}
              <a href="/student/login" className="mt-2 rounded-xl border border-white/25 px-3 py-3 text-sm font-semibold">
                {t.nav.portal}
              </a>
              <a href="#contact" onClick={() => setMobileMenuOpen(false)} className="mt-2 rounded-xl bg-[#f6a23b] px-3 py-3 text-center text-sm font-extrabold text-[#172b43]">
                {t.nav.preRegister}
              </a>
            </div>
          </nav>
        )}
      </header>

      <section id="home" className="relative isolate">
        <div className="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_12%_20%,#e5f4f8_0,transparent_34%),linear-gradient(110deg,#f9fbfd_0%,#f3f7fb_54%,#e9f3f7_100%)]" />
        <div className="mx-auto grid max-w-[1440px] items-center gap-10 px-5 pb-14 pt-12 sm:px-8 lg:grid-cols-[0.92fr_1.08fr] lg:px-12 lg:pb-20 lg:pt-16">
          <div className="relative z-10 max-w-2xl">
            <span className="inline-flex items-center gap-2 rounded-full border border-[#9ddfe0] bg-[#dff6f4] px-4 py-2 text-[11px] font-extrabold uppercase tracking-[0.12em] text-[#087b83]">
              <Icon name="graduation" size={16} />
              {t.hero.eyebrow}
            </span>
            <h1 className="mt-6 text-[clamp(2.7rem,5.5vw,5.25rem)] font-black leading-[0.99] tracking-[-0.055em] text-[#0b2748]">
              {t.hero.titleStart}
              <span className="mt-2 block bg-gradient-to-r from-[#0e7c83] to-[#16a0a2] bg-clip-text text-transparent">
                {t.hero.titleEnd}
              </span>
            </h1>
            <p className="mt-6 max-w-xl text-base leading-8 text-slate-600 sm:text-lg">
              {t.hero.description}
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <a
                href="#programs"
                className="inline-flex items-center justify-center gap-3 rounded-full bg-[#0b2b4e] px-7 py-4 text-sm font-extrabold text-white shadow-xl shadow-[#0b2b4e]/15 transition hover:-translate-y-1 hover:bg-[#123d68]"
              >
                {t.hero.primary}
                <Icon name="arrow" size={18} />
              </a>
              <a
                href="#contact"
                className="inline-flex items-center justify-center gap-3 rounded-full border border-[#a9bacb] bg-white/80 px-7 py-4 text-sm font-bold text-[#123252] transition hover:border-[#0e7c83] hover:bg-white"
              >
                <Icon name="mail" size={18} />
                {t.hero.secondary}
              </a>
            </div>
            <p className="mt-5 flex items-center gap-2 text-xs font-semibold text-slate-500">
              <Icon name="check" size={15} className="text-[#0e8c85]" />
              {t.hero.note}
            </p>
          </div>

          <div className="relative mx-auto w-full max-w-2xl lg:ml-auto">
            <div className="absolute -left-5 top-12 h-40 w-40 rounded-full bg-[#1b9b9e]/15 blur-3xl" />
            <div className="absolute -bottom-5 right-5 h-40 w-40 rounded-full bg-[#f6a23b]/20 blur-3xl" />
            <div className="relative h-[330px] overflow-hidden rounded-[2rem] shadow-[0_28px_70px_rgba(16,43,76,0.2)] sm:h-[440px] lg:h-[510px]">
              <Image
                src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=1500&q=90"
                alt="Students learning together in a bright classroom"
                fill
                priority
                unoptimized
                sizes="(max-width: 1024px) 100vw, 55vw"
                className="object-cover"
              />
              <div className="absolute inset-0 bg-gradient-to-t from-[#082342]/65 via-transparent to-transparent" />
              <div className="absolute bottom-5 left-5 right-5 flex items-end justify-between gap-4 sm:bottom-7 sm:left-7 sm:right-7">
                <div className="max-w-xs text-white">
                  <span className="text-xs font-bold uppercase tracking-[0.17em] text-[#ffd18b]">
                    Language learning
                  </span>
                  <p className="mt-2 text-2xl font-extrabold leading-tight sm:text-3xl">
                    Your next chapter starts here.
                  </p>
                </div>
                <div className="hidden rounded-2xl border border-white/30 bg-white/15 p-4 text-white backdrop-blur-md sm:block">
                  <Icon name="sparkles" size={27} />
                </div>
              </div>
            </div>
            <div className="absolute -bottom-5 -left-3 hidden items-center gap-3 rounded-2xl border border-white/70 bg-white px-5 py-4 shadow-xl sm:flex lg:-left-8">
              <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#e1f5f2] text-[#0d8585]">
                <Icon name="book" size={22} />
              </span>
              <span>
                <span className="block text-sm font-extrabold text-[#102b4c]">Learn with purpose</span>
                <span className="block text-xs text-slate-500">One step at a time</span>
              </span>
            </div>
          </div>
        </div>
      </section>

      <section className="border-y border-slate-200/80 bg-white">
        <div className="mx-auto grid max-w-[1440px] grid-cols-2 gap-5 px-5 py-6 sm:px-8 md:grid-cols-4 lg:px-12">
          {[
            { icon: "graduation" as const, label: t.benefits.goals },
            { icon: "users" as const, label: t.benefits.levels },
            { icon: "sparkles" as const, label: t.benefits.guidance },
            { icon: "check" as const, label: t.benefits.progress },
          ].map((item) => (
            <div key={item.label} className="flex items-center gap-3">
              <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#e8f6f4] text-[#0c8585]">
                <Icon name={item.icon} size={21} />
              </span>
              <span className="text-sm font-bold text-[#173655]">{item.label}</span>
            </div>
          ))}
        </div>
      </section>

      <section id="programs" className="mx-auto max-w-[1440px] px-5 py-20 sm:px-8 lg:px-12 lg:py-24">
        <div className="mb-10 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
          <div className="max-w-2xl">
            <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">
              {t.programs.eyebrow}
            </span>
            <h2 className="mt-3 text-3xl font-black tracking-tight text-[#102b4c] sm:text-4xl">
              {t.programs.title}
            </h2>
            <p className="mt-3 max-w-xl leading-7 text-slate-600">{t.programs.description}</p>
          </div>
          <a href="/courses" className="inline-flex items-center gap-2 text-sm font-extrabold text-[#0b5f82] transition hover:gap-3">
            {t.programs.all} <Icon name="arrow" size={17} />
          </a>
        </div>

        {/* Preview content only: this catalog will be replaced by published courses from the Laravel API. */}
        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
          {programs.map((program) => {
            const title = t.programs[program.key];
            const description = t.programs[`${program.key}Description` as keyof typeof t.programs];
            return (
              <article key={program.key} className="group overflow-hidden rounded-[1.7rem] border border-slate-200 bg-white shadow-[0_12px_35px_rgba(16,43,76,0.06)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_22px_45px_rgba(16,43,76,0.12)]">
                <div className="relative h-48 overflow-hidden">
                  <Image
                    src={program.image}
                    alt={program.imageAlt}
                    fill
                    unoptimized
                    sizes="(max-width: 768px) 100vw, (max-width: 1280px) 50vw, 33vw"
                    className="object-cover transition duration-700 group-hover:scale-105"
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-[#092443]/45 to-transparent" />
                  <span className="absolute bottom-4 left-5 rounded-full border border-white/30 bg-white/90 px-3 py-1 text-xs font-extrabold text-[#173655] backdrop-blur">
                    {program.label}
                  </span>
                </div>
                <div className="p-6">
                  <div className="flex items-center justify-between gap-3">
                    <h3 className="text-2xl font-extrabold text-[#102b4c]">{title}</h3>
                    <span className="rounded-full bg-[#e5f6f3] px-3 py-1 text-[10px] font-extrabold uppercase tracking-wide text-[#0d777b]">
                      {locale === "en" ? "Programme" : "Programme"}
                    </span>
                  </div>
                  <p className="mt-3 min-h-[52px] text-sm leading-6 text-slate-600">{description}</p>
                  <p className="mt-4 flex items-center gap-2 text-xs font-semibold text-slate-500">
                    <Icon name="graduation" size={15} className="text-[#0d8585]" />
                    {t.programs.level}
                  </p>
                  <a href="/courses" className="mt-6 inline-flex items-center gap-2 text-sm font-extrabold text-[#0a6090] transition hover:gap-3">
                    {t.programs.details} <Icon name="arrow" size={16} />
                  </a>
                </div>
              </article>
            );
          })}
        </div>
      </section>

      <section id="about" className="bg-white py-20 lg:py-24">
        <div className="mx-auto max-w-[1440px] px-5 sm:px-8 lg:px-12">
          <div className="mx-auto mb-12 max-w-2xl text-center">
            <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">{t.benefits.eyebrow}</span>
            <h2 className="mt-3 text-3xl font-black tracking-tight text-[#102b4c] sm:text-4xl">{t.benefits.title}</h2>
            <p className="mt-4 leading-7 text-slate-600">{t.benefits.description}</p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {[
              { icon: "target", title: t.benefits.goals, body: t.benefits.goalsText },
              { icon: "graduation", title: t.benefits.levels, body: t.benefits.levelsText },
              { icon: "users", title: t.benefits.guidance, body: t.benefits.guidanceText },
              { icon: "check", title: t.benefits.progress, body: t.benefits.progressText },
            ].map((item) => (
              <div key={item.title} className="rounded-[1.5rem] border border-slate-200 bg-[#f8fafc] p-6 transition hover:border-[#9ad8d4] hover:bg-[#f2fbfa]">
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-[#0d8585] shadow-sm">
                  <Icon name={item.icon === "target" ? "sparkles" : item.icon as IconName} size={23} />
                </div>
                <h3 className="mt-5 text-lg font-extrabold text-[#173655]">{item.title}</h3>
                <p className="mt-2 text-sm leading-6 text-slate-600">{item.body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section id="approach" className="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-20 sm:px-8 lg:grid-cols-2 lg:px-12 lg:py-24">
        <div className="relative">
          <div className="absolute -bottom-5 -left-5 h-40 w-40 rounded-full bg-[#f7b35e]/20 blur-3xl" />
          <div className="relative h-[330px] overflow-hidden rounded-[2rem] shadow-xl sm:h-[450px]">
            <Image
              src="https://images.unsplash.com/photo-1524178232363-1fb2b075b655?auto=format&fit=crop&w=1400&q=90"
              alt="Teacher guiding students during a lesson"
              fill
              unoptimized
              sizes="(max-width: 1024px) 100vw, 50vw"
              className="object-cover"
            />
          </div>
          <div className="absolute -bottom-5 right-4 rounded-2xl border border-white bg-white px-5 py-4 shadow-xl sm:right-8">
            <span className="flex items-center gap-2 text-sm font-extrabold text-[#173655]">
              <Icon name="play" size={18} className="text-[#0d8585]" />
              {t.approach.imageLabel}
            </span>
          </div>
        </div>

        <div className="max-w-xl">
          <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">{t.approach.eyebrow}</span>
          <h2 className="mt-3 text-3xl font-black leading-tight tracking-tight text-[#102b4c] sm:text-4xl">{t.approach.title}</h2>
          <p className="mt-5 leading-8 text-slate-600">{t.approach.description}</p>
          <div className="mt-7 space-y-4">
            {[t.approach.point1, t.approach.point2, t.approach.point3].map((point) => (
              <div key={point} className="flex items-start gap-3">
                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#dcf4ef] text-[#0b827d]">
                  <Icon name="check" size={15} />
                </span>
                <p className="text-sm leading-6 text-slate-700">{point}</p>
              </div>
            ))}
          </div>
          <a href="#programs" className="mt-9 inline-flex items-center gap-3 rounded-full bg-[#0b2b4e] px-7 py-4 text-sm font-extrabold text-white transition hover:-translate-y-1 hover:bg-[#123d68]">
            {t.approach.cta} <Icon name="arrow" size={17} />
          </a>
        </div>
      </section>

      <section className="bg-[#092443] py-20 text-white lg:py-24">
        <div className="mx-auto max-w-[1440px] px-5 sm:px-8 lg:px-12">
          <div className="mx-auto mb-12 max-w-2xl text-center">
            <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#80d5d0]">{t.steps.eyebrow}</span>
            <h2 className="mt-3 text-3xl font-black tracking-tight sm:text-4xl">{t.steps.title}</h2>
            <p className="mt-4 leading-7 text-slate-300">{t.steps.description}</p>
          </div>
          <div className="grid gap-5 md:grid-cols-3">
            {[
              { number: "01", title: t.steps.one, body: t.steps.oneText, icon: "book" as const },
              { number: "02", title: t.steps.two, body: t.steps.twoText, icon: "mail" as const },
              { number: "03", title: t.steps.three, body: t.steps.threeText, icon: "check" as const },
            ].map((step) => (
              <div key={step.number} className="rounded-[1.5rem] border border-white/15 bg-white/[0.06] p-7">
                <div className="flex items-center justify-between">
                  <span className="text-4xl font-black text-[#f6a23b]">{step.number}</span>
                  <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-[#ffd18b]">
                    <Icon name={step.icon} size={23} />
                  </span>
                </div>
                <h3 className="mt-7 text-xl font-extrabold">{step.title}</h3>
                <p className="mt-3 text-sm leading-7 text-slate-300">{step.body}</p>
              </div>
            ))}
          </div>
          <div className="mt-10 flex flex-col items-center justify-between gap-5 rounded-[1.5rem] bg-gradient-to-r from-[#0f456b] to-[#0f6870] p-6 sm:flex-row sm:px-9">
            <div>
              <p className="text-xl font-extrabold">{t.contact.title}</p>
              <p className="mt-1 text-sm text-slate-200">{t.contact.description}</p>
            </div>
            <a href="#contact" className="inline-flex shrink-0 items-center gap-3 rounded-full bg-[#f6a23b] px-7 py-4 text-sm font-extrabold text-[#172b43] transition hover:-translate-y-1 hover:bg-[#ffb451]">
              {t.steps.cta} <Icon name="arrow" size={17} />
            </a>
          </div>
        </div>
      </section>

      <section id="faq" className="mx-auto max-w-[1000px] px-5 py-20 sm:px-8 lg:py-24">
        <div className="mb-10 text-center">
          <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">{t.faq.eyebrow}</span>
          <h2 className="mt-3 text-3xl font-black tracking-tight text-[#102b4c] sm:text-4xl">{t.faq.title}</h2>
        </div>
        <div className="space-y-3">
          {[
            [t.faq.q1, t.faq.a1],
            [t.faq.q2, t.faq.a2],
            [t.faq.q3, t.faq.a3],
            [t.faq.q4, t.faq.a4],
          ].map(([question, answer]) => (
            <details key={question} className="group rounded-2xl border border-slate-200 bg-white p-5 open:border-[#9ad8d4] open:bg-[#f7fcfb]">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-left font-bold text-[#173655] marker:hidden">
                {question}
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e7f5f3] text-[#0d8585] transition group-open:rotate-180">
                  <Icon name="chevron" size={17} />
                </span>
              </summary>
              <p className="max-w-3xl pt-4 text-sm leading-7 text-slate-600">{answer}</p>
            </details>
          ))}
        </div>
      </section>

      <section id="contact" className="px-5 pb-20 sm:px-8 lg:px-12">
        <div className="mx-auto max-w-[1440px] overflow-hidden rounded-[2rem] bg-[#e8f4f5]">
          <div className="grid items-center gap-8 p-8 sm:p-12 lg:grid-cols-[1fr_auto] lg:p-16">
            <div className="max-w-2xl">
              <span className="text-xs font-extrabold uppercase tracking-[0.2em] text-[#0e8988]">{t.contact.eyebrow}</span>
              <h2 className="mt-3 text-3xl font-black tracking-tight text-[#102b4c] sm:text-4xl">{t.contact.title}</h2>
              <p className="mt-4 leading-7 text-slate-600">{t.contact.description}</p>
            </div>
            <div className="flex flex-col gap-3 sm:flex-row lg:flex-col">
              <a href="/contact" className="inline-flex items-center justify-center gap-3 rounded-full bg-[#0b2b4e] px-7 py-4 text-sm font-extrabold text-white transition hover:bg-[#123d68]">
                <Icon name="mail" size={17} /> {t.contact.cta}
              </a>
              <a href="/student/login" className="inline-flex items-center justify-center gap-3 rounded-full border border-[#aabcc9] bg-white px-7 py-4 text-sm font-extrabold text-[#173655] transition hover:border-[#0e8988]">
                <Icon name="users" size={17} /> {t.contact.portal}
              </a>
            </div>
          </div>
        </div>
      </section>

      <footer className="bg-[#071d36] text-white">
        <div className="mx-auto grid max-w-[1440px] gap-10 px-5 py-14 sm:px-8 md:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1fr_1fr] lg:px-12">
          <div>
            <a href="#home" className="inline-flex items-center gap-3">
              <BrandMark />
              <span>
                <span className="block text-lg font-extrabold">Language Center</span>
                <span className="block text-xs text-slate-400">{t.footer.tagline}</span>
              </span>
            </a>
            <p className="mt-5 max-w-xs text-sm leading-7 text-slate-400">{t.footer.note}</p>
          </div>
          <div>
            <h3 className="font-extrabold">{t.footer.links}</h3>
            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <a href="#home" className="hover:text-white">{t.nav.home}</a>
              <a href="#programs" className="hover:text-white">{t.nav.courses}</a>
              <a href="#about" className="hover:text-white">{t.nav.about}</a>
              <a href="#contact" className="hover:text-white">{t.nav.contact}</a>
            </div>
          </div>
          <div>
            <h3 className="font-extrabold">{t.footer.programmes}</h3>
            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <a href="#programs" className="hover:text-white">English</a>
              <a href="#programs" className="hover:text-white">French</a>
              <a href="#programs" className="hover:text-white">German</a>
            </div>
          </div>
          <div>
            <h3 className="font-extrabold">{t.footer.legal}</h3>
            <div className="mt-5 flex flex-col gap-3 text-sm text-slate-400">
              <a href="/privacy" className="hover:text-white">{t.footer.privacy}</a>
              <a href="/terms" className="hover:text-white">{t.footer.terms}</a>
              <a href="/student/login" className="hover:text-white">{t.nav.portal}</a>
            </div>
          </div>
        </div>
        <div className="border-t border-white/10">
          <div className="mx-auto flex max-w-[1440px] flex-col justify-between gap-3 px-5 py-5 text-xs text-slate-500 sm:px-8 md:flex-row lg:px-12">
            <span>© {new Date().getFullYear()} Language Center. {locale === "en" ? "All rights reserved." : "Tous droits réservés."}</span>
            <span>Built for language learning.</span>
          </div>
        </div>
      </footer>
    </main>
  );
}

"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState, type FormEvent } from "react";

import DashboardLayout from "@/components/dashboard/dashboard-layout";
import LessonAssetViewer from "@/components/learning/lesson-asset-viewer";
import {
  AdminLearningApiError,
  archiveAdminCourse,
  getAdminCourseCurriculum,
  getAdminLessonContent,
  publishAdminCourse,
  restoreAdminCourse,
  triggerCourseRevalidation,
  unpublishAdminCourse,
  updateAdminCourseSettings,
  type AdminLessonContent,
  type CurriculumCourseOutcome,
  type CurriculumCourseSummary,
  type CurriculumResponse,
} from "@/lib/admin-learning";
import CurriculumBuilder from "./curriculum-builder";
import {
  apiGetMe,
  type AuthUser,
} from "@/lib/auth";
import {
  apiGetPlatformCourseDetails,
  type PlatformCourseDetails,
  type PlatformCourseStatus,
} from "@/lib/admin";
import type { LessonBlock, TipTapDocument } from "@/lib/learning";

interface CourseDetailsClientProps {
  centerSlug: string;
  courseSlug: string;
}

interface CourseForm {
  title: string;
  slug: string;
  short_description: string;
  description: string;
  prerequisites: string;
  target_audience: string;
  language_id: string;
  level_id: string;
  source_locale: string;
  study_format: "in_person" | "online" | "hybrid";
  duration_hours: string;
  cover_image_url: string;
  cover_image_alt: string;
  pricing_mode: "fixed" | "from" | "upon_request" | "free";
  price: string;
  currency: string;
  is_curriculum_public: boolean;
  meta_title: string;
  meta_description: string;
  outcomes: CurriculumCourseOutcome[];
}

type SettingsTab = "general" | "audience" | "pricing" | "seo";
type OutcomesLocale = "en" | "fr";
type AdminUiLocale = "en" | "fr";

type LessonPreviewState =
  | { status: "loading" }
  | { status: "loaded"; content: AdminLessonContent }
  | { status: "error"; message: string };

const statusStyles: Record<PlatformCourseStatus, string> = {
  draft: "border-amber-200 bg-amber-50 text-amber-800",
  published: "border-emerald-200 bg-emerald-50 text-emerald-800",
  archived: "border-slate-200 bg-slate-100 text-slate-700",
};

const COURSE_DETAILS_FR: Record<string, string> = {
  "Course image unavailable": "Image du cours indisponible",
  "No course image": "Aucune image de cours",
  "No content blocks in this revision.": "Aucun bloc de contenu dans cette révision.",
  Exercise: "Exercice",
  "Unavailable exercise": "Exercice indisponible",
  "Text content": "Contenu textuel",
  "Formatted text block": "Bloc de texte mis en forme",
  "Loading course workspace…": "Chargement de l'espace du cours…",
  "Access Restricted": "Accès restreint",
  "Sign in with a center administrator or platform administrator account to manage this course.": "Connectez-vous avec un compte administrateur de centre ou de plateforme pour gérer ce cours.",
  "Return to learning centers": "Retour aux centres de formation",
  Courses: "Cours",
  "Course details unavailable": "Détails du cours indisponibles",
  "This course may have been removed.": "Ce cours a peut-être été supprimé.",
  "Back to courses": "Retour aux cours",
  "No course description has been added.": "Aucune description n'a été ajoutée.",
  Language: "Langue",
  Level: "Niveau",
  Published: "Publié",
  Updated: "Mis à jour",
  "Edit Course": "Modifier le cours",
  "Editing Course": "Modification du cours",
  "Manage Content": "Gérer le contenu",
  "Draft Preview": "Aperçu du brouillon",
  "Public Page": "Page publique",
  "Publish the course to open its public page.": "Publiez le cours pour ouvrir sa page publique.",
  "Public page unavailable": "Page publique indisponible",
  Publish: "Publier",
  Unpublish: "Dépublier",
  "Restore to Draft": "Restaurer en brouillon",
  Archive: "Archiver",
  draft: "brouillon",
  published: "publié",
  archived: "archivé",
  "Archive this course? It will no longer appear as an active learning course.": "Archiver ce cours ? Il ne sera plus proposé comme cours actif.",
  "Course Settings": "Paramètres du cours",
  "Configure general details, learning objectives, pricing, visibility, and SEO metadata.": "Configurez les informations générales, les objectifs, le tarif, la visibilité et les métadonnées SEO.",
  "Unsaved changes": "Modifications non enregistrées",
  "All changes saved": "Toutes les modifications sont enregistrées",
  "Course settings sections": "Sections des paramètres du cours",
  "General Info": "Informations générales",
  "Objectives & Audience": "Objectifs et public",
  "Pricing & Visibility": "Tarif et visibilité",
  "SEO Metadata": "Métadonnées SEO",
  "Course title": "Titre du cours",
  "URL slug": "Identifiant URL",
  "Content locale": "Langue du contenu",
  "Study format": "Format d'étude",
  "In person": "En présentiel",
  Online: "En ligne",
  Hybrid: "Hybride",
  "Duration (hours)": "Durée (heures)",
  "Short description": "Description courte",
  "Course description": "Description du cours",
  "Cover image URL": "URL de l'image de couverture",
  "Clear the field to remove the image.": "Videz le champ pour supprimer l'image.",
  "Cover image alt text": "Texte alternatif de l'image",
  "Target audience": "Public cible",
  Prerequisites: "Prérequis",
  "Who is this course designed for?": "À qui s'adresse ce cours ?",
  "Required prior level or placement test...": "Niveau préalable ou test de placement requis…",
  "Learning Objectives / Outcomes": "Objectifs pédagogiques",
  "Editing language": "Langue modifiée",
  English: "Anglais",
  French: "Français",
  "Add Objective": "Ajouter un objectif",
  "No learning objectives have been added in English.": "Aucun objectif pédagogique n'a été ajouté en anglais.",
  "No learning objectives have been added in French.": "Aucun objectif pédagogique n'a été ajouté en français.",
  Highlight: "Mettre en avant",
  Remove: "Supprimer",
  "Pricing mode": "Mode de tarification",
  "Upon request": "Sur demande",
  "Fixed price": "Prix fixe",
  "Starting from": "À partir de",
  Free: "Gratuit",
  Price: "Prix",
  "Currency (3-letter code)": "Devise (code à 3 lettres)",
  "This course will be saved without a price.": "Ce cours sera enregistré sans prix.",
  "No fixed price will be stored for this course.": "Aucun prix fixe ne sera enregistré pour ce cours.",
  "Show published curriculum outline on the public course page": "Afficher le plan publié sur la page publique du cours",
  "Only published modules and published lessons are visible publicly; private files and draft content are never exposed.": "Seuls les modules et leçons publiés sont visibles publiquement ; les fichiers privés et brouillons ne sont jamais exposés.",
  "SEO Meta Title": "Titre SEO",
  "SEO Meta Description": "Description SEO",
  Cancel: "Annuler",
  Save: "Enregistrer",
  "Course changes were saved, but the public cache could not be refreshed:": "Les modifications sont enregistrées, mais le cache public n'a pas pu être actualisé :",
  "Loading curriculum…": "Chargement du programme…",
  "Unable to load curriculum": "Impossible de charger le programme",
  "An error occurred while loading modules and lessons.": "Une erreur s'est produite lors du chargement des modules et des leçons.",
  "Retry Curriculum Load": "Réessayer de charger le programme",
  "Loading lesson content…": "Chargement du contenu de la leçon…",
  Retry: "Réessayer",
  "Review the highlighted fields before saving.": "Vérifiez les champs signalés avant l'enregistrement.",
  "Enter a price greater than zero.": "Saisissez un prix supérieur à zéro.",
  "Enter a valid three-letter currency code.": "Saisissez un code de devise valide à trois lettres.",
  "Learning outcomes must be unique in the selected language.": "Les objectifs pédagogiques doivent être uniques dans la langue sélectionnée.",
  "Save or cancel the course edits before changing its status.": "Enregistrez ou annulez vos modifications avant de changer le statut du cours.",
  "This course status transition is not available.": "Cette transition de statut du cours n'est pas disponible.",
  "Course settings saved.": "Paramètres du cours enregistrés.",
  "Course published.": "Cours publié.",
  "Course archived.": "Cours archivé.",
  "Course restored to draft.": "Cours restauré en brouillon.",
  "Course moved to draft.": "Cours déplacé en brouillon.",
  "Sign in": "Se connecter",
  "Interface language": "Langue de l'interface",
  "Not set": "Non défini",
  "Unable to load course details.": "Impossible de charger les détails du cours.",
  "Unable to save course changes.": "Impossible d'enregistrer les modifications du cours.",
  "Unable to change course status.": "Impossible de modifier le statut du cours.",
  "Modules": "Modules",
  "Total Lessons": "Leçons au total",
  "Published Lessons": "Leçons publiées",
  "Draft Lessons": "Leçons en brouillon",
  "Exercises": "Exercices",
  "Assets": "Ressources",
  "Archived Lessons": "Leçons archivées",
  Objective: "Objectif",
  "Saving…": "Enregistrement…",
  "Save Changes": "Enregistrer les modifications",
  "Course statistics": "Statistiques du cours",
  "A positive price is required when pricing mode is fixed or starting from.": "Un prix supérieur à zéro est requis pour un tarif fixe ou à partir de.",
  "A valid three-letter currency code is required when pricing mode is fixed or starting from.": "Un code de devise valide à trois lettres est requis pour un tarif fixe ou à partir de.",
  "A free course cannot have a non-zero price.": "Un cours gratuit ne peut pas avoir de prix non nul.",
  "Duplicate learning outcomes are not allowed in the same language.": "Les objectifs pédagogiques ne peuvent pas être dupliqués dans la même langue.",
  "Outcome locale must match the selected editing locale 'en'.": "La langue de l'objectif doit correspondre à la langue sélectionnée (anglais).",
  "Outcome locale must match the selected editing locale 'fr'.": "La langue de l'objectif doit correspondre à la langue sélectionnée (français).",
  "Only draft courses can be published.": "Seuls les cours en brouillon peuvent être publiés.",
  "Only published courses can be unpublished.": "Seuls les cours publiés peuvent être dépubliés.",
  "Only active courses can be archived.": "Seuls les cours actifs peuvent être archivés.",
  "Only archived courses can be restored.": "Seuls les cours archivés peuvent être restaurés.",
  "Course title, slug, language, and level are required before publishing.": "Le titre, l'identifiant URL, la langue et le niveau sont requis avant publication.",
  "Sign in to revalidate course pages.": "Connectez-vous pour actualiser les pages du cours.",
  "You do not have permission to revalidate this center's course pages.": "Vous n'avez pas l'autorisation d'actualiser les pages des cours de ce centre.",
  "Could not verify course administration access.": "Impossible de vérifier l'accès administrateur au cours.",
  "Cross-origin revalidation requests are not allowed.": "Les demandes d'actualisation provenant d'une autre origine sont interdites.",
  "Same-origin revalidation requests are required.": "Une demande provenant de la même origine est requise pour l'actualisation.",
  "The given data was invalid.": "Les données saisies ne sont pas valides.",
};

function courseDetailsText(text: string, locale: AdminUiLocale): string {
  return locale === "fr" ? COURSE_DETAILS_FR[text] ?? text : text;
}

type CourseCatalogFields = Pick<
  CurriculumCourseSummary,
  | "title"
  | "slug"
  | "short_description"
  | "description"
  | "source_locale"
  | "study_format"
  | "duration_hours"
  | "cover_image_url"
  | "cover_image_alt"
  | "language"
  | "level"
>;

function courseAffectsCatalog(before: CourseCatalogFields, after: CourseCatalogFields): boolean {
  return before.title !== after.title ||
    before.slug !== after.slug ||
    (before.short_description || before.description || "") !== (after.short_description || after.description || "") ||
    before.source_locale !== after.source_locale ||
    before.study_format !== after.study_format ||
    before.duration_hours !== after.duration_hours ||
    before.cover_image_url !== after.cover_image_url ||
    before.cover_image_alt !== after.cover_image_alt ||
    before.language?.id !== after.language?.id ||
    before.language?.code !== after.language?.code ||
    before.language?.name !== after.language?.name ||
    before.level?.id !== after.level?.id ||
    before.level?.code !== after.level?.code ||
    before.level?.name !== after.level?.name;
}

function toCourseForm(course: Partial<CurriculumCourseSummary> & {
  title: string;
  slug: string;
  source_locale: string;
  study_format: "in_person" | "online" | "hybrid";
  duration_hours: number | null;
  cover_image_url: string | null;
  cover_image_alt: string | null;
  short_description: string | null;
  description: string | null;
  language: { id: number } | null;
  level: { id: number } | null;
}): CourseForm {
  return {
    title: course.title,
    slug: course.slug,
    short_description: course.short_description ?? "",
    description: course.description ?? "",
    prerequisites: course.prerequisites ?? "",
    target_audience: course.target_audience ?? "",
    language_id: course.language ? String(course.language.id) : "",
    level_id: course.level ? String(course.level.id) : "",
    source_locale: course.source_locale,
    study_format: course.study_format,
    duration_hours: course.duration_hours === null ? "" : String(course.duration_hours),
    cover_image_url: course.cover_image_url ?? "",
    cover_image_alt: course.cover_image_alt ?? "",
    pricing_mode: course.pricing_mode ?? "upon_request",
    price: course.price ?? "",
    currency: course.currency ?? "MAD",
    is_curriculum_public: course.is_curriculum_public ?? true,
    meta_title: course.meta_title ?? "",
    meta_description: course.meta_description ?? "",
    outcomes: (course.outcomes ?? []).map((o) => ({
      id: o.id,
      locale: o.locale ?? "en",
      content: o.content,
      is_highlight: Boolean(o.is_highlight),
      sort_order: o.sort_order,
    })),
  };
}

function safePublicImageUrl(value: string | null | undefined): string | null {
  if (!value?.trim()) return null;

  try {
    const url = new URL(value.trim());
    if (url.protocol !== "https:" || url.username || url.password) return null;
    return url.toString();
  } catch {
    return null;
  }
}

function CourseCover({
  src,
  alt,
  title,
  locale,
}: {
  src: string | null | undefined;
  alt: string;
  title: string;
  locale: AdminUiLocale;
}) {
  const [failedSource, setFailedSource] = useState<string | null>(null);
  const safeSrc = safePublicImageUrl(src);
  const showImage = safeSrc !== null && failedSource !== safeSrc;

  return (
    <div className="relative aspect-[16/10] overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-[#e7f2f4] to-[#e7edf3] sm:aspect-[4/3]">
      {showImage ? (
        // Course covers are public URLs; lesson asset previews use the protected asset viewer below.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={safeSrc}
          alt={alt || title}
          loading="eager"
          fetchPriority="high"
          onError={() => setFailedSource(safeSrc)}
          className="h-full w-full object-cover"
        />
      ) : (
        <div className="flex h-full flex-col items-center justify-center gap-2 px-5 text-center text-[#0e8988]">
          <svg viewBox="0 0 24 24" className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5z" />
            <path d="M8 8h8M8 12h6" />
          </svg>
          <span className="text-xs font-bold text-slate-600">
            {courseDetailsText(src ? "Course image unavailable" : "No course image", locale)}
          </span>
        </div>
      )}
    </div>
  );
}

function StatusBadge({ status, locale = "en" }: { status: PlatformCourseStatus; locale?: AdminUiLocale }) {
  return (
    <span className={`inline-flex rounded-full border px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wide ${statusStyles[status]}`}>
      {courseDetailsText(status, locale)}
    </span>
  );
}

function formatDate(value: string | null): string {
  if (!value) return "Not published";
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(date);
}

function formatRelativeDate(value: string | null): string {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function plainText(document: TipTapDocument | null): string {
  if (!document) return "";

  const visit = (node: { text?: string; content?: unknown[] }): string => {
    const ownText = typeof node.text === "string" ? node.text : "";
    const children = Array.isArray(node.content)
      ? node.content.map((child) => {
          if (!child || typeof child !== "object") return "";
          return visit(child as { text?: string; content?: unknown[] });
        }).filter(Boolean)
      : [];
    return [ownText, ...children].filter(Boolean).join(" ");
  };

  return (document.content ?? []).map(visit).filter(Boolean).join(" ").trim();
}

function FieldError({ message, id, locale = "en" }: { message?: string; id: string; locale?: AdminUiLocale }) {
  if (!message) return null;
  return <p id={id} className="mt-1 text-xs font-semibold text-red-700">{courseDetailsText(message, locale)}</p>;
}

function LessonBlocksPreview({
  blocks,
  centerSlug,
  courseSlug,
  lessonId,
  locale,
}: {
  blocks: LessonBlock[];
  centerSlug: string;
  courseSlug: string;
  lessonId: number;
  locale: AdminUiLocale;
}) {
  if (blocks.length === 0) {
    return <p className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">{courseDetailsText("No content blocks in this revision.", locale)}</p>;
  }

  return (
    <div className="space-y-3">
      {blocks.map((block) => {
        if (block.block_type === "media") {
          return block.asset ? (
            <LessonAssetViewer
              key={block.id}
              asset={block.asset}
              params={{ centerSlug, courseSlug, lessonId }}
              autoPreviewImages
              locale={locale}
            />
          ) : (
            <p key={block.id} className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
              This media block has no available asset.
            </p>
          );
        }

        if (block.block_type === "exercise") {
          return (
            <article key={block.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
              <div className="min-w-0">
              <p className="text-[10px] font-extrabold uppercase tracking-wide text-teal-700">{courseDetailsText("Exercise", locale)}</p>
                <h4 className="mt-1 truncate text-sm font-bold text-[#102b4c]">{block.exercise?.title ?? courseDetailsText("Unavailable exercise", locale)}</h4>
              </div>
              {block.exercise && <StatusBadge status={block.exercise.status} locale={locale} />}
            </article>
          );
        }

        const excerpt = plainText(block.content_json);
        return (
          <article key={block.id} className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-[10px] font-extrabold uppercase tracking-wide text-teal-700">{courseDetailsText("Text content", locale)}</p>
            <p className="mt-2 line-clamp-4 whitespace-pre-wrap text-xs leading-6 text-slate-600">
              {excerpt || courseDetailsText("Formatted text block", locale)}
            </p>
          </article>
        );
      })}
    </div>
  );
}

export default function CourseDetailsClient({
  centerSlug,
  courseSlug,
}: CourseDetailsClientProps) {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [authResolved, setAuthResolved] = useState(false);
  const [details, setDetails] = useState<PlatformCourseDetails | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [accessDeniedMessage, setAccessDeniedMessage] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [uiLocale, setUiLocale] = useState<AdminUiLocale>("en");
  const [settingsTab, setSettingsTab] = useState<SettingsTab>("general");
  const [outcomesLocale, setOutcomesLocale] = useState<OutcomesLocale>("en");
  const [form, setForm] = useState<CourseForm | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [isChangingStatus, setIsChangingStatus] = useState(false);
  const [saveMessage, setSaveMessage] = useState("");
  const [revalidationWarning, setRevalidationWarning] = useState("");
  const [lessonPreviews, setLessonPreviews] = useState<Record<number, LessonPreviewState>>({});
  const [openPreviews, setOpenPreviews] = useState<Record<number, boolean>>({});
  const [curriculum, setCurriculum] = useState<CurriculumResponse | null>(null);
  const [curriculumStatus, setCurriculumStatus] = useState<"loading" | "loaded" | "error">("loading");
  const [curriculumError, setCurriculumError] = useState<string | null>(null);
  const t = (text: string) => courseDetailsText(text, uiLocale);

  useEffect(() => {
    const locale = new URLSearchParams(window.location.search).get("locale");
    if (locale === "en" || locale === "fr") {
      setUiLocale(locale);
      setOutcomesLocale(locale);
    }
  }, []);

  const activeCourse = curriculum?.course ?? details?.course ?? null;
  const activeCenter = curriculum?.center ?? details?.center ?? null;
  const activeStats = curriculum?.statistics ?? details?.statistics ?? null;
  const languageOptions = curriculum?.language_options ?? details?.language_options ?? [];
  const levelOptions = curriculum?.level_options ?? details?.level_options ?? [];
  const displayedOutcomes = form?.outcomes
    .map((outcome, formIndex) => ({ outcome, formIndex }))
    .filter(({ outcome }) => (outcome.locale ?? "en") === outcomesLocale) ?? [];

  const savedForm = useMemo(
    () => (activeCourse ? toCourseForm(activeCourse) : null),
    [activeCourse]
  );
  const isDirty = Boolean(form && savedForm && JSON.stringify(form) !== JSON.stringify(savedForm));

  useEffect(() => {
    let active = true;
    void apiGetMe().then((authenticatedUser) => {
      if (!active) return;
      setUser(authenticatedUser);
    }).catch(() => {
      if (active) setUser(null);
    }).finally(() => {
      if (active) setAuthResolved(true);
    });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!authResolved) return;
    if (!user) {
      setIsLoading(false);
      return;
    }

    let active = true;
    // Phase B 4.2: Reset all stale course-specific state on navigation
    setIsLoading(true);
    setPageError("");
    setAccessDeniedMessage(null);
    setSaveMessage("");
    setRevalidationWarning("");
    setDetails(null);
    setCurriculum(null);
    setCurriculumStatus("loading");
    setCurriculumError(null);
    setLessonPreviews({});
    setOpenPreviews({});
    setIsEditing(false);
    setFieldErrors({});
    setForm(null);

    const loadPlatformDetails = user.is_platform_admin
      ? apiGetPlatformCourseDetails(centerSlug, courseSlug).catch((err: unknown) => {
          if (active) {
            setPageError(err instanceof Error ? err.message : "Unable to load course details.");
          }
          return null;
        })
      : Promise.resolve(null);

    const loadCurriculum = getAdminCourseCurriculum(centerSlug, courseSlug)
      .then((curr) => {
        if (!active) return null;
        setCurriculum(curr);
        setCurriculumStatus("loaded");
        setCurriculumError(null);
        return curr;
      })
      .catch((err: unknown) => {
        if (!active) return null;
        if (err instanceof AdminLearningApiError && (err.status === 401 || err.status === 403)) {
          setAccessDeniedMessage(err.message || "You do not have permission to manage this course.");
        }
        setCurriculumStatus("error");
        setCurriculumError(err instanceof Error ? err.message : "Could not load curriculum.");
        return null;
      });

    void Promise.all([loadPlatformDetails, loadCurriculum])
      .then(([platformResult, currResult]) => {
        if (!active) return;
        if (platformResult) {
          setDetails(platformResult);
        }
        const sourceCourse = currResult?.course ?? platformResult?.course;
        if (sourceCourse) {
          setForm(toCourseForm(sourceCourse));
        }
      })
      .finally(() => {
        if (active) setIsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [authResolved, centerSlug, courseSlug, user]);

  async function refreshCurriculum() {
    setCurriculumError(null);
    try {
      const curr = await getAdminCourseCurriculum(centerSlug, activeCourse?.slug ?? courseSlug);
      setCurriculum(curr);
      setCurriculumStatus("loaded");
      setForm((prev) => (isEditing ? prev : toCourseForm(curr.course)));
      const revalidation = await triggerCourseRevalidation(centerSlug, curr.course.slug);
      setRevalidationWarning(revalidation.ok ? "" : revalidation.message);
    } catch (err: unknown) {
      setCurriculumStatus("error");
      setCurriculumError(err instanceof Error ? err.message : "Failed to refresh curriculum.");
      throw err;
    }
  }

  useEffect(() => {
    if (!isDirty) return;
    const warnOnLeave = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warnOnLeave);
    return () => window.removeEventListener("beforeunload", warnOnLeave);
  }, [isDirty]);

  function updateField<K extends keyof CourseForm>(key: K, value: CourseForm[K]) {
    setForm((current) => current ? { ...current, [key]: value } : current);
    setFieldErrors((current) => ({ ...current, [key]: [] }));
    setPageError("");
    setSaveMessage("");
  }

  function formPayload(nextForm: CourseForm, selectedOutcomesLocale: OutcomesLocale): Record<string, unknown> {
    return {
      title: nextForm.title.trim(),
      slug: nextForm.slug.trim(),
      short_description: nextForm.short_description.trim() || null,
      description: nextForm.description.trim() || null,
      prerequisites: nextForm.prerequisites.trim() || null,
      target_audience: nextForm.target_audience.trim() || null,
      language_id: Number(nextForm.language_id),
      level_id: Number(nextForm.level_id),
      source_locale: nextForm.source_locale.trim(),
      study_format: nextForm.study_format,
      duration_hours: nextForm.duration_hours.trim() ? Number(nextForm.duration_hours) : null,
      cover_image_url: nextForm.cover_image_url.trim() || null,
      cover_image_alt: nextForm.cover_image_alt.trim() || null,
      pricing_mode: nextForm.pricing_mode,
      price: nextForm.pricing_mode === "fixed" || nextForm.pricing_mode === "from"
        ? (nextForm.price.trim() ? Number(nextForm.price) : null)
        : null,
      currency: nextForm.currency.trim() || "MAD",
      is_curriculum_public: nextForm.is_curriculum_public,
      meta_title: nextForm.meta_title.trim() || null,
      meta_description: nextForm.meta_description.trim() || null,
      outcomes_locale: selectedOutcomesLocale,
      outcomes: nextForm.outcomes
        .filter((outcome) => (outcome.locale ?? "en") === selectedOutcomesLocale)
        .filter((outcome) => outcome.content.trim() !== "")
        .map((outcome) => ({
          ...(outcome.id === undefined ? {} : { id: outcome.id }),
          locale: selectedOutcomesLocale,
          content: outcome.content.trim(),
          is_highlight: Boolean(outcome.is_highlight),
        })),
    };
  }

  async function handleSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!form || !activeCourse || isSaving) return;

    const validationErrors: Record<string, string[]> = {};
    if (form.pricing_mode === "fixed" || form.pricing_mode === "from") {
      const price = Number(form.price);
      if (!form.price.trim() || !Number.isFinite(price) || price <= 0) {
        validationErrors.price = ["Enter a price greater than zero."];
      }
      if (!/^[A-Za-z]{3}$/.test(form.currency.trim())) {
        validationErrors.currency = ["Enter a valid three-letter currency code."];
      }
    }

    const selectedOutcomes = form.outcomes.filter(
      (outcome) => (outcome.locale ?? "en") === outcomesLocale && outcome.content.trim() !== ""
    );
    const normalizedOutcomes = selectedOutcomes.map((outcome) => outcome.content.trim().toLowerCase());
    if (new Set(normalizedOutcomes).size !== normalizedOutcomes.length) {
      validationErrors.outcomes = ["Learning outcomes must be unique in the selected language."];
    }

    if (Object.keys(validationErrors).length > 0) {
      setFieldErrors(validationErrors);
      setPageError(t("Review the highlighted fields before saving."));
      return;
    }

    setIsSaving(true);
    setPageError("");
    setFieldErrors({});
    setSaveMessage("");

    try {
      const updated = await updateAdminCourseSettings(
        centerSlug,
        activeCourse.slug,
        formPayload(form, outcomesLocale)
      );
      setCurriculum((current) => current ? { ...current, course: updated } : current);
      setForm(toCourseForm(updated));
      setIsEditing(false);
      setSaveMessage("Course settings saved.");
      const revalidation = await triggerCourseRevalidation(centerSlug, updated.slug, {
        oldSlug: activeCourse.slug === updated.slug ? undefined : activeCourse.slug,
        invalidateListing:
          activeCourse.status === "published" &&
          updated.status === "published" &&
          courseAffectsCatalog(activeCourse, updated),
      });
      setRevalidationWarning(revalidation.ok ? "" : revalidation.message);
      if (updated.slug !== courseSlug) {
        router.replace(`/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(updated.slug)}`);
      }
    } catch (error: unknown) {
      if (error instanceof AdminLearningApiError) {
        setPageError(courseDetailsText(error.message, uiLocale));
        setFieldErrors(error.errors ?? {});
      } else {
        setPageError(error instanceof Error ? error.message : t("Unable to save course changes."));
      }
    } finally {
      setIsSaving(false);
    }
  }

  async function handleStatusChange(status: PlatformCourseStatus) {
    if (!activeCourse || isChangingStatus || isSaving || isEditing || isDirty) {
      if (isEditing || isDirty) setPageError(t("Save or cancel the course edits before changing its status."));
      return;
    }
    if (status === "archived" && !window.confirm(t("Archive this course? It will no longer appear as an active learning course."))) return;

    setIsChangingStatus(true);
    setPageError("");
    setSaveMessage("");
    setRevalidationWarning("");
    try {
      const updated = status === "published" && activeCourse.status === "draft"
        ? await publishAdminCourse(centerSlug, activeCourse.slug)
        : status === "draft" && activeCourse.status === "published"
          ? await unpublishAdminCourse(centerSlug, activeCourse.slug)
          : status === "archived" && activeCourse.status !== "archived"
            ? await archiveAdminCourse(centerSlug, activeCourse.slug)
            : status === "draft" && activeCourse.status === "archived"
              ? await restoreAdminCourse(centerSlug, activeCourse.slug)
              : null;

      if (!updated) {
        setPageError(t("This course status transition is not available."));
        return;
      }

      setCurriculum((current) => current ? { ...current, course: updated } : current);
      setForm(toCourseForm(updated));
      setSaveMessage(status === "published"
        ? "Course published."
        : status === "archived"
          ? "Course archived."
          : activeCourse.status === "archived"
            ? "Course restored to draft."
            : "Course moved to draft.");
      const revalidation = await triggerCourseRevalidation(centerSlug, updated.slug, {
        invalidateListing: activeCourse.status === "published" || updated.status === "published",
      });
      setRevalidationWarning(revalidation.ok ? "" : revalidation.message);
    } catch (error: unknown) {
      setPageError(error instanceof Error ? error.message : t("Unable to change course status."));
    } finally {
      setIsChangingStatus(false);
    }
  }

  async function loadLessonPreview(lessonId: number) {
    if (lessonPreviews[lessonId]) return;
    setLessonPreviews((current) => ({ ...current, [lessonId]: { status: "loading" } }));
    try {
      const content = await getAdminLessonContent(centerSlug, details?.course.slug ?? courseSlug, lessonId);
      setLessonPreviews((current) => ({ ...current, [lessonId]: { status: "loaded", content } }));
    } catch (error: unknown) {
      setLessonPreviews((current) => ({
        ...current,
        [lessonId]: {
          status: "error",
          message: error instanceof AdminLearningApiError || error instanceof Error
            ? error.message
            : "Unable to load lesson content.",
        },
      }));
    }
  }

  if (!authResolved || isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm font-bold text-slate-500">{t("Loading course workspace…")}</p>
      </div>
    );
  }

  if (!user || accessDeniedMessage) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md rounded-2xl border border-red-200 bg-white p-8 text-center">
          <h1 className="text-xl font-black text-slate-900">{t("Access Restricted")}</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">
            {accessDeniedMessage
              ? accessDeniedMessage
              : t("Sign in with a center administrator or platform administrator account to manage this course.")}
          </p>
          {!user && (
            <Link
              href={`/login?redirect=${encodeURIComponent(`/admin/learning/centers/${centerSlug}/courses/${courseSlug}`)}`}
              className="mt-5 inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-[#102b4c]"
            >
              {t("Sign in")}
            </Link>
          )}
          <Link href="/admin/learning" className="mt-5 inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-bold text-white">{t("Return to learning centers")}</Link>
        </div>
      </main>
    );
  }

  const course = activeCourse;
  const courseListHref = `/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses`;

  return (
    <DashboardLayout user={user} showCenterSwitcher={false}>
      <div className="mx-auto max-w-7xl space-y-6 pb-12">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Link href={courseListHref} className="inline-flex min-h-10 items-center gap-2 text-xs font-bold text-[#0e8988] hover:underline">
            <span aria-hidden="true">←</span> {activeCenter?.name ?? "Courses"}
          </Link>
          <div className="flex items-center gap-2">
            <label htmlFor="course-ui-language" className="text-xs font-semibold text-slate-500">{t("Interface language")}</label>
            <select
              id="course-ui-language"
              value={uiLocale}
              onChange={(event) => {
                const nextLocale = event.target.value as AdminUiLocale;
                setUiLocale(nextLocale);
                const query = new URLSearchParams(window.location.search);
                query.set("locale", nextLocale);
                router.replace(`${window.location.pathname}?${query.toString()}`, { scroll: false });
              }}
              className="min-h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs font-bold text-slate-700"
            >
              <option value="en">{t("English")}</option>
              <option value="fr">{t("French")}</option>
            </select>
            <span className="text-xs text-slate-500">{activeCenter?.slug ?? centerSlug}</span>
          </div>
        </div>

        {pageError && (
          <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {t(pageError)}
          </div>
        )}
        {saveMessage && (
          <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
            {t(saveMessage)}
          </div>
        )}
        {revalidationWarning && (
          <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
            {t("Course changes were saved, but the public cache could not be refreshed:")} {t(revalidationWarning)}
          </div>
        )}

        {!course ? (
          <section className="rounded-2xl border border-slate-200 bg-white p-8 text-center">
            <h1 className="text-lg font-black text-[#102b4c]">{t("Course details unavailable")}</h1>
            <p className="mt-2 text-sm text-slate-600">{t(pageError) || t("This course may have been removed.")}</p>
            <Link href={courseListHref} className="mt-5 inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-bold text-white">{t("Back to courses")}</Link>
          </section>
        ) : (
          <>
            <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 lg:grid-cols-[minmax(230px,0.72fr)_minmax(0,1.6fr)] lg:items-center">
              <CourseCover
                src={isEditing && form ? form.cover_image_url : course.cover_image_url}
                alt={isEditing && form ? form.cover_image_alt : course.cover_image_alt ?? course.title}
                title={course.title}
                locale={uiLocale}
              />

              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge status={course.status} locale={uiLocale} />
                  {course.language && <span className="rounded-full bg-teal-50 px-2.5 py-1 text-[10px] font-bold text-teal-800">{course.language.name}</span>}
                  {course.level && <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-700">{course.level.name}</span>}
                </div>
                <h1 className="mt-3 break-words text-2xl font-black leading-tight text-[#092443] sm:text-3xl">{course.title}</h1>
                <p className="mt-1 break-all text-xs text-slate-500">{course.slug}</p>
                <p className="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600">
                  {course.short_description || course.description || t("No course description has been added.")}
                </p>
                <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-slate-500">
                  <span>{t("Language")}: <strong className="text-slate-700">{course.language?.name ?? t("Not set")}</strong></span>
                  <span>{t("Level")}: <strong className="text-slate-700">{course.level?.name ?? t("Not set")}</strong></span>
                  <span>{t("Published")}: <strong className="text-slate-700">{formatDate(course.published_at)}</strong></span>
                  <span>{t("Updated")}: <strong className="text-slate-700">{formatRelativeDate(course.updated_at)}</strong></span>
                </div>
                <div className="mt-5 flex flex-wrap gap-2">
                  {!isEditing ? (
                    <button type="button" onClick={() => { setForm(toCourseForm(course)); setIsEditing(true); setPageError(""); }} className="inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-extrabold text-white hover:bg-[#123b64]">{t("Edit Course")}</button>
                  ) : (
                    <a href="#course-edit" className="inline-flex min-h-10 items-center rounded-xl bg-[#092443] px-4 text-xs font-extrabold text-white">{t("Editing Course")}</a>
                  )}
                  <a href="#course-content" className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-extrabold text-[#102b4c] hover:border-teal-300 hover:text-teal-800">{t("Manage Content")}</a>
                  <Link
                    href={`/admin/learning/centers/${encodeURIComponent(centerSlug)}/courses/${encodeURIComponent(course.slug)}/preview`}
                    className="inline-flex min-h-10 items-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-xs font-extrabold text-indigo-800 hover:bg-indigo-100"
                  >
                    {t("Draft Preview")}
                  </Link>
                  {course.status === "published" ? (
                    <Link href={`/courses/${encodeURIComponent(course.slug)}`} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-extrabold text-[#102b4c] hover:border-teal-300 hover:text-teal-800">{t("Public Page")}</Link>
                  ) : (
                    <button type="button" disabled title={t("Publish the course to open its public page.")} className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-400">{t("Public page unavailable")}</button>
                  )}
                  {course.status === "draft" && <button type="button" disabled={isChangingStatus || isSaving || isEditing} onClick={() => void handleStatusChange("published")} className="inline-flex min-h-10 items-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-xs font-extrabold text-emerald-800 disabled:opacity-50">{t("Publish")}</button>}
                  {course.status === "published" && <button type="button" disabled={isChangingStatus || isSaving || isEditing} onClick={() => void handleStatusChange("draft")} className="inline-flex min-h-10 items-center rounded-xl border border-amber-200 bg-amber-50 px-4 text-xs font-extrabold text-amber-800 disabled:opacity-50">{t("Unpublish")}</button>}
                  {course.status === "archived" ? (
                    <button type="button" disabled={isChangingStatus || isSaving || isEditing} onClick={() => void handleStatusChange("draft")} className="inline-flex min-h-10 items-center rounded-xl border border-slate-300 bg-slate-100 px-4 text-xs font-extrabold text-slate-700 disabled:opacity-50">{t("Restore to Draft")}</button>
                  ) : (
                    <button type="button" disabled={isChangingStatus || isSaving || isEditing} onClick={() => void handleStatusChange("archived")} className="inline-flex min-h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 hover:border-red-200 hover:text-red-700 disabled:opacity-50">{t("Archive")}</button>
                  )}
                </div>
              </div>
            </section>

            {isEditing && form && (
              <section id="course-edit" className="scroll-mt-6 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm sm:p-6">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h2 className="text-lg font-black text-[#102b4c]">{t("Course Settings")}</h2>
                    <p className="mt-1 text-xs leading-5 text-slate-500">{t("Configure general details, learning objectives, pricing, visibility, and SEO metadata.")}</p>
                  </div>
                  <span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${isDirty ? "bg-amber-50 text-amber-800" : "bg-emerald-50 text-emerald-800"}`}>
                    {isDirty ? t("Unsaved changes") : t("All changes saved")}
                  </span>
                </div>

                {/* Settings Tabs */}
                <div role="tablist" aria-label={t("Course settings sections")} className="mb-5 flex flex-wrap gap-2 border-b border-slate-100 pb-3">
                  {(
                    [
                      ["general", t("General Info")],
                      ["audience", t("Objectives & Audience")],
                      ["pricing", t("Pricing & Visibility")],
                      ["seo", t("SEO Metadata")],
                    ] as [SettingsTab, string][]
                  ).map(([tabKey, tabLabel]) => (
                    <button
                      key={tabKey}
                      type="button"
                      role="tab"
                      aria-selected={settingsTab === tabKey}
                      onClick={() => setSettingsTab(tabKey)}
                      className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
                        settingsTab === tabKey
                          ? "bg-[#092443] text-white"
                          : "border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100"
                      }`}
                    >
                      {tabLabel}
                    </button>
                  ))}
                </div>

                <form onSubmit={(event) => void handleSave(event)} className="space-y-5">
                  {settingsTab === "general" && (
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <label htmlFor="course-title" className="text-xs font-bold text-slate-700">{t("Course title")}</label>
                      <input id="course-title" value={form.title} onChange={(event) => updateField("title", event.target.value)} required maxLength={255} aria-invalid={Boolean(fieldErrors.title?.length)} aria-describedby={fieldErrors.title?.length ? "course-title-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-title-error" message={fieldErrors.title?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-slug" className="text-xs font-bold text-slate-700">{t("URL slug")}</label>
                      <input id="course-slug" value={form.slug} onChange={(event) => updateField("slug", event.target.value)} required maxLength={150} aria-invalid={Boolean(fieldErrors.slug?.length)} aria-describedby={fieldErrors.slug?.length ? "course-slug-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-slug-error" message={fieldErrors.slug?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-language" className="text-xs font-bold text-slate-700">{t("Language")}</label>
                      <select id="course-language" value={form.language_id} onChange={(event) => updateField("language_id", event.target.value)} required aria-invalid={Boolean(fieldErrors.language_id?.length)} aria-describedby={fieldErrors.language_id?.length ? "course-language-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400">
                        {languageOptions.map((language) => <option key={language.id} value={language.id}>{language.name} ({language.code})</option>)}
                      </select>
                      <FieldError id="course-language-error" message={fieldErrors.language_id?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-level" className="text-xs font-bold text-slate-700">{t("Level")}</label>
                      <select id="course-level" value={form.level_id} onChange={(event) => updateField("level_id", event.target.value)} required aria-invalid={Boolean(fieldErrors.level_id?.length)} aria-describedby={fieldErrors.level_id?.length ? "course-level-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400">
                        {levelOptions.map((level) => <option key={level.id} value={level.id}>{level.name} ({level.code})</option>)}
                      </select>
                      <FieldError id="course-level-error" message={fieldErrors.level_id?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-locale" className="text-xs font-bold text-slate-700">{t("Content locale")}</label>
                      <input id="course-locale" value={form.source_locale} onChange={(event) => updateField("source_locale", event.target.value)} required maxLength={35} aria-invalid={Boolean(fieldErrors.source_locale?.length)} aria-describedby={fieldErrors.source_locale?.length ? "course-locale-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-locale-error" message={fieldErrors.source_locale?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-study-format" className="text-xs font-bold text-slate-700">{t("Study format")}</label>
                      <select id="course-study-format" value={form.study_format} onChange={(event) => updateField("study_format", event.target.value as CourseForm["study_format"])} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500">
                        <option value="in_person">{t("In person")}</option>
                        <option value="online">{t("Online")}</option>
                        <option value="hybrid">{t("Hybrid")}</option>
                      </select>
                    </div>
                    <div>
                      <label htmlFor="course-duration" className="text-xs font-bold text-slate-700">{t("Duration (hours)")}</label>
                      <input id="course-duration" type="number" min="0" max="65535" value={form.duration_hours} onChange={(event) => updateField("duration_hours", event.target.value)} aria-invalid={Boolean(fieldErrors.duration_hours?.length)} aria-describedby={fieldErrors.duration_hours?.length ? "course-duration-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-duration-error" message={fieldErrors.duration_hours?.[0]} locale={uiLocale} />
                    </div>
                    <div className="md:col-span-2">
                      <label htmlFor="course-short-description" className="text-xs font-bold text-slate-700">{t("Short description")}</label>
                      <textarea id="course-short-description" value={form.short_description} onChange={(event) => updateField("short_description", event.target.value)} rows={2} maxLength={500} aria-invalid={Boolean(fieldErrors.short_description?.length)} aria-describedby={fieldErrors.short_description?.length ? "course-short-description-error" : undefined} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-short-description-error" message={fieldErrors.short_description?.[0]} locale={uiLocale} />
                    </div>
                    <div className="md:col-span-2">
                      <label htmlFor="course-description" className="text-xs font-bold text-slate-700">{t("Course description")}</label>
                      <textarea id="course-description" value={form.description} onChange={(event) => updateField("description", event.target.value)} rows={5} maxLength={50000} aria-invalid={Boolean(fieldErrors.description?.length)} aria-describedby={fieldErrors.description?.length ? "course-description-error" : undefined} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm leading-6 outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-description-error" message={fieldErrors.description?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-cover-url" className="text-xs font-bold text-slate-700">{t("Cover image URL")}</label>
                      <input id="course-cover-url" type="url" value={form.cover_image_url} onChange={(event) => updateField("cover_image_url", event.target.value)} placeholder="https://…" aria-invalid={Boolean(fieldErrors.cover_image_url?.length)} aria-describedby={fieldErrors.cover_image_url?.length ? "course-cover-url-error" : "course-cover-url-help"} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <p id="course-cover-url-help" className="mt-1 text-[10px] text-slate-500">{t("Clear the field to remove the image.")}</p>
                      <FieldError id="course-cover-url-error" message={fieldErrors.cover_image_url?.[0]} locale={uiLocale} />
                    </div>
                    <div>
                      <label htmlFor="course-cover-alt" className="text-xs font-bold text-slate-700">{t("Cover image alt text")}</label>
                      <input id="course-cover-alt" value={form.cover_image_alt} onChange={(event) => updateField("cover_image_alt", event.target.value)} maxLength={255} aria-invalid={Boolean(fieldErrors.cover_image_alt?.length)} aria-describedby={fieldErrors.cover_image_alt?.length ? "course-cover-alt-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                      <FieldError id="course-cover-alt-error" message={fieldErrors.cover_image_alt?.[0]} locale={uiLocale} />
                    </div>
                  </div>
                  )}

                  {settingsTab === "audience" && (
                  <div className="space-y-4">
                    <div>
                      <label htmlFor="course-target-audience" className="text-xs font-bold text-slate-700">{t("Target audience")}</label>
                      <textarea id="course-target-audience" rows={3} maxLength={10000} value={form.target_audience} onChange={(e) => updateField("target_audience", e.target.value)} placeholder={t("Who is this course designed for?")} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    <div>
                      <label htmlFor="course-prerequisites" className="text-xs font-bold text-slate-700">{t("Prerequisites")}</label>
                      <textarea id="course-prerequisites" rows={3} maxLength={10000} value={form.prerequisites} onChange={(e) => updateField("prerequisites", e.target.value)} placeholder={t("Required prior level or placement test...")} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-bold text-slate-700">{t("Learning Objectives / Outcomes")}</span>
                        <label className="flex items-center gap-2 text-xs font-semibold text-slate-600">
                          {t("Editing language")}
                          <select
                            value={outcomesLocale}
                            onChange={(event) => setOutcomesLocale(event.target.value as OutcomesLocale)}
                            className="min-h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs font-bold text-slate-800"
                          >
                            <option value="en">{t("English")}</option>
                            <option value="fr">{t("French")}</option>
                          </select>
                        </label>
                        <button
                          type="button"
                          onClick={() => updateField("outcomes", [...form.outcomes, { locale: outcomesLocale, content: "", is_highlight: false }])}
                          className="rounded-lg border border-teal-300 px-2.5 py-1 text-xs font-bold text-teal-800 hover:bg-teal-50"
                        >
                          + {t("Add Objective")}
                        </button>
                      </div>
                      {displayedOutcomes.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-slate-200 p-4 text-xs text-slate-500">{t(outcomesLocale === "en" ? "No learning objectives have been added in English." : "No learning objectives have been added in French.")}</p>
                      ) : (
                        <div className="space-y-2">
                          {displayedOutcomes.map(({ outcome, formIndex }, visibleIndex) => (
                            <div key={outcome.id ?? `${outcome.locale}-${formIndex}`} className="flex flex-wrap items-center gap-2">
                              <input
                                type="text"
                                maxLength={500}
                                value={outcome.content}
                                onChange={(e) => {
                                  const next = [...form.outcomes];
                                  next[formIndex] = { ...next[formIndex], content: e.target.value };
                                  updateField("outcomes", next);
                                }}
                                placeholder={`${t("Objective")} ${visibleIndex + 1}`}
                                className="min-w-[200px] flex-1 rounded-xl border border-slate-200 px-3 py-2 text-xs outline-none focus:border-teal-500"
                              />
                              <label className="flex items-center gap-1.5 text-xs text-slate-700">
                                <input
                                  type="checkbox"
                                  checked={Boolean(outcome.is_highlight)}
                                  onChange={(e) => {
                                    const next = [...form.outcomes];
                                    next[formIndex] = { ...next[formIndex], is_highlight: e.target.checked };
                                    updateField("outcomes", next);
                                  }}
                                />
                                {t("Highlight")}
                              </label>
                              <button
                                type="button"
                                onClick={() => updateField("outcomes", form.outcomes.filter((_, index) => index !== formIndex))}
                                className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50"
                              >
                                {t("Remove")}
                              </button>
                            </div>
                          ))}
                        </div>
                      )}
                      <FieldError id="course-outcomes-error" message={fieldErrors.outcomes?.[0]} locale={uiLocale} />
                    </div>
                  </div>
                  )}

                  {settingsTab === "pricing" && (
                  <div className="grid gap-4 md:grid-cols-2">
                    <div>
                      <label htmlFor="course-pricing-mode" className="text-xs font-bold text-slate-700">{t("Pricing mode")}</label>
                      <select id="course-pricing-mode" value={form.pricing_mode} onChange={(e) => updateField("pricing_mode", e.target.value as CourseForm["pricing_mode"])} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-teal-500">
                        <option value="upon_request">{t("Upon request")}</option>
                        <option value="fixed">{t("Fixed price")}</option>
                        <option value="from">{t("Starting from")}</option>
                        <option value="free">{t("Free")}</option>
                      </select>
                    </div>
                    {(form.pricing_mode === "fixed" || form.pricing_mode === "from") && (
                      <>
                        <div>
                          <label htmlFor="course-price" className="text-xs font-bold text-slate-700">{t("Price")}</label>
                          <input id="course-price" type="number" step="0.01" min="0.01" value={form.price} onChange={(e) => updateField("price", e.target.value)} aria-invalid={Boolean(fieldErrors.price?.length)} aria-describedby={fieldErrors.price?.length ? "course-price-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                          <FieldError id="course-price-error" message={fieldErrors.price?.[0]} locale={uiLocale} />
                        </div>
                        <div>
                          <label htmlFor="course-currency" className="text-xs font-bold text-slate-700">{t("Currency (3-letter code)")}</label>
                          <input id="course-currency" maxLength={3} minLength={3} value={form.currency} onChange={(e) => updateField("currency", e.target.value.toUpperCase())} aria-invalid={Boolean(fieldErrors.currency?.length)} aria-describedby={fieldErrors.currency?.length ? "course-currency-error" : undefined} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm uppercase outline-none focus:border-teal-500 aria-[invalid=true]:border-red-400" />
                          <FieldError id="course-currency-error" message={fieldErrors.currency?.[0]} locale={uiLocale} />
                        </div>
                      </>
                    )}
                    {form.pricing_mode === "free" && <p className="text-xs text-slate-600">{t("This course will be saved without a price.")}</p>}
                    {form.pricing_mode === "upon_request" && <p className="text-xs text-slate-600">{t("No fixed price will be stored for this course.")}</p>}
                    <div className="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                      <label className="flex items-center gap-2.5 text-xs font-bold text-slate-800">
                        <input
                          type="checkbox"
                          checked={form.is_curriculum_public}
                          onChange={(e) => updateField("is_curriculum_public", e.target.checked)}
                        />
                        {t("Show published curriculum outline on the public course page")}
                      </label>
                      <p className="mt-1 text-[11px] text-slate-500">
                        {t("Only published modules and published lessons are visible publicly; private files and draft content are never exposed.")}
                      </p>
                    </div>
                  </div>
                  )}

                  {settingsTab === "seo" && (
                  <div className="space-y-4">
                    <div>
                      <label htmlFor="course-meta-title" className="text-xs font-bold text-slate-700">{t("SEO Meta Title")}</label>
                      <input id="course-meta-title" maxLength={255} value={form.meta_title} onChange={(e) => updateField("meta_title", e.target.value)} placeholder={form.title} className="mt-1 min-h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                    <div>
                      <label htmlFor="course-meta-description" className="text-xs font-bold text-slate-700">{t("SEO Meta Description")}</label>
                      <textarea id="course-meta-description" rows={3} maxLength={500} value={form.meta_description} onChange={(e) => updateField("meta_description", e.target.value)} placeholder={form.short_description} className="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-teal-500" />
                    </div>
                  </div>
                  )}

                  <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" disabled={isSaving} onClick={() => { setForm(toCourseForm(course)); setIsEditing(false); setFieldErrors({}); setPageError(""); }} className="min-h-10 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700 disabled:opacity-50">{t("Cancel")}</button>
                    <button type="submit" disabled={isSaving || !isDirty} className="min-h-10 rounded-xl bg-[#0e8988] px-5 text-xs font-extrabold text-white disabled:cursor-not-allowed disabled:opacity-50">{isSaving ? t("Saving…") : t("Save Changes")}</button>
                  </div>
                </form>
              </section>
            )}

            {activeStats && (
              <section aria-label={t("Course statistics")} className="space-y-3">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {[
                    ["Modules", activeStats.modules],
                    ["Total Lessons", activeStats.lessons],
                    ["Published Lessons", activeStats.published_lessons],
                    ["Draft Lessons", activeStats.draft_lessons],
                  ].map(([label, value]) => (
                    <div key={label} className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                      <p className="text-xl font-black tabular-nums text-[#092443]">{value}</p>
                      <p className="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">{t(String(label))}</p>
                    </div>
                  ))}
                </div>
                <div className="flex flex-wrap gap-x-6 gap-y-2 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                  {[
                    ["Exercises", activeStats.exercises],
                    ["Assets", activeStats.assets],
                    ["Archived Lessons", activeStats.archived_lessons],
                  ].map(([label, value]) => (
                    <p key={label} className="text-xs text-slate-600">
                      <span className="font-black tabular-nums text-[#092443]">{value}</span>
                      <span className="ml-1.5">{t(String(label))}</span>
                    </p>
                  ))}
                </div>
              </section>
            )}

            {curriculumStatus === "loading" ? (
              <section className="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                <p role="status" className="text-sm font-bold text-slate-500">{t("Loading curriculum…")}</p>
              </section>
            ) : curriculumStatus === "error" ? (
              <section role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-center space-y-3">
                <h2 className="text-base font-black text-rose-900">{t("Unable to load curriculum")}</h2>
                <p className="text-xs text-rose-800">{t(curriculumError || "An error occurred while loading modules and lessons.")}</p>
                <button
                  type="button"
                  onClick={() => {
                    setCurriculumStatus("loading");
                    void refreshCurriculum().catch(() => undefined);
                  }}
                  className="inline-flex min-h-10 items-center rounded-xl bg-rose-700 px-4 text-xs font-bold text-white hover:bg-rose-800"
                >
                  {t("Retry Curriculum Load")}
                </button>
              </section>
            ) : (
            <CurriculumBuilder
              key={`${centerSlug}:${course.id}`}
              centerSlug={centerSlug}
              courseSlug={course.slug}
              locale={uiLocale}
              modules={curriculum?.modules ?? []}
              onRefresh={refreshCurriculum}
              openPreviews={openPreviews}
              onTogglePreview={(lessonId) => {
                const nextOpen = !openPreviews[lessonId];
                setOpenPreviews((prev) => ({ ...prev, [lessonId]: nextOpen }));
                if (nextOpen) {
                  void loadLessonPreview(lessonId);
                }
              }}
              renderLessonPreview={(lessonId) => {
                const preview = lessonPreviews[lessonId];
                if (!preview || preview.status === "loading") {
                  return <p role="status" className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">{t("Loading lesson content…")}</p>;
                }
                if (preview.status === "error") {
                  return (
                    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-800">
                      <p>{preview.message}</p>
                      <button
                        type="button"
                        onClick={() => {
                          setLessonPreviews((current) => {
                            const next = { ...current };
                            delete next[lessonId];
                            return next;
                          });
                          void loadLessonPreview(lessonId);
                        }}
                        className="mt-2 font-bold underline"
                      >
                        Retry
                      </button>
                    </div>
                  );
                }
                const revision = preview.content.draft_revision ?? preview.content.published_revision;
                return (
                  <div className="space-y-3">
                    {revision && (
                      <p className="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                        {revision.status} revision · v{revision.version_number}
                      </p>
                    )}
                    <LessonBlocksPreview
                      blocks={revision?.blocks ?? []}
                      centerSlug={centerSlug}
                      courseSlug={course.slug}
                      lessonId={lessonId}
                      locale={uiLocale}
                    />
                  </div>
                );
              }}
            />
            )}
          </>
        )}
      </div>
    </DashboardLayout>
  );
}

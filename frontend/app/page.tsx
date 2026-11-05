import type { Metadata } from "next";
import HomePage from "@/components/home-page";

export const metadata: Metadata = {
  title: "Language Center | Learn. Progress. Achieve.",
  description:
    "Explore English, French, and other language programmes. Find a course that fits your goals and contact the language centre to get started.",
  keywords: [
    "language centre",
    "language courses",
    "English courses",
    "French courses",
    "Casablanca",
  ],
  openGraph: {
    title: "Language Center | Learn. Progress. Achieve.",
    description:
      "Discover language programmes for study, work, travel, and everyday communication.",
    type: "website",
  },
};

export default function Home() {
  return <HomePage />;
}

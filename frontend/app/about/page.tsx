
import type { Metadata } from "next";
import AboutPage from "@/components/about-page";

export const metadata: Metadata = {
  title: "About Us | Language Center",
  description:
    "Discover our story, educational values, mission and vision. Learn more about our approach to language learning.",
  keywords: [
    "about language center",
    "language education",
    "language institute Casablanca",
    "language learning",
  ],
  openGraph: {
    title: "About Us | Language Center",
    description:
      "Discover our story and the values behind our language learning experience.",
    type: "website",
  },
};

export default function About() {
  return <AboutPage />;
}

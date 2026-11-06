
import type { NextConfig } from "next";

const remotePatterns: {
  protocol: "http" | "https";
  hostname: string;
  port?: string;
  pathname: string;
}[] = [];

// Optional: allow images from the configured Laravel media host.
if (process.env.MEDIA_BASE_URL) {
  const mediaUrl = new URL(process.env.MEDIA_BASE_URL);

  if (
    mediaUrl.protocol !== "https:" &&
    !(process.env.NODE_ENV === "development" &&
      mediaUrl.protocol === "http:")
  ) {
    throw new Error(
      "MEDIA_BASE_URL must use HTTPS outside development."
    );
  }

  const basePath = mediaUrl.pathname.replace(/\/+$/, "");

  remotePatterns.push({
    protocol: mediaUrl.protocol.slice(0, -1) as "http" | "https",
    hostname: mediaUrl.hostname,
    port: mediaUrl.port,
    pathname: `${basePath}/**`,
  });
}

const nextConfig: NextConfig = {
  images: {
    remotePatterns,
  },

  cacheComponents: true,
  partialPrefetching: true,

  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;


import type { NextConfig } from "next";

const remotePatterns: {
  protocol: "http" | "https";
  hostname: string;
  port?: string;
  pathname: string;
}[] = [];

function getLaravelApiOrigin(): string {
  const isDevelopment = process.env.NODE_ENV === "development";
  const configuredUrl = process.env.LARAVEL_API_URL?.trim();
  if (!configuredUrl && !isDevelopment) {
    throw new Error("LARAVEL_API_URL must be configured when building or running Next.js outside development.");
  }

  const rawUrl = configuredUrl || "http://127.0.0.1:8000";
  let apiUrl: URL;
  try {
    apiUrl = new URL(rawUrl);
  } catch {
    throw new Error("LARAVEL_API_URL must be a valid absolute HTTP(S) URL.");
  }

  if (
    (apiUrl.protocol !== "https:" && apiUrl.protocol !== "http:") ||
    apiUrl.username ||
    apiUrl.password ||
    apiUrl.pathname !== "/" ||
    apiUrl.search ||
    apiUrl.hash
  ) {
    throw new Error("LARAVEL_API_URL must contain only an HTTP(S) origin without credentials, path, query, or fragment.");
  }

  if (apiUrl.protocol === "http:") {
    const localHosts = new Set(["localhost", "127.0.0.1", "::1", "[::1]"]);
    if (!isDevelopment || !localHosts.has(apiUrl.hostname.toLowerCase())) {
      throw new Error("LARAVEL_API_URL may use HTTP only for a local development host.");
    }
  }

  return apiUrl.origin.replace(/\/+$/, "");
}

const laravelApiOrigin = getLaravelApiOrigin();

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
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${laravelApiOrigin}/api/:path*`,
      },
    ];
  },

  images: {
    remotePatterns,
  },

  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          {
            key: "Content-Security-Policy",
            value: "frame-src 'self' https://*.cloudflarestream.com https://www.youtube-nocookie.com https://drive.google.com;",
          },
        ],
      },
    ];
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

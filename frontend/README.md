This is a [Next.js](https://nextjs.org) project bootstrapped with [`create-next-app`](https://nextjs.org/docs/app/api-reference/cli/create-next-app).

## Getting Started

First, run the development server:

```bash
npm run dev
# or
yarn dev
# or
pnpm dev
# or
bun dev
```

Open [http://localhost:3000](http://localhost:3000) with your browser to see the result.

You can start editing the page by modifying `app/page.tsx`. The page auto-updates as you edit the file.

This project uses [`next/font`](https://nextjs.org/docs/app/building-your-application/optimizing/fonts) to automatically optimize and load [Geist](https://vercel.com/font), a new font family for Vercel.

## Learn More

To learn more about Next.js, take a look at the following resources:

- [Next.js Documentation](https://nextjs.org/docs) - learn about Next.js features and API.
- [Learn Next.js](https://nextjs.org/learn) - an interactive Next.js tutorial.

You can check out [the Next.js GitHub repository](https://github.com/vercel/next.js) - your feedback and contributions are welcome!

## External lesson videos

Lesson videos can use the existing Cloudflare Stream upload or validated HTTPS links from YouTube and Google Drive. The backend extracts provider IDs and creates iframe URLs; it never fetches user-submitted URLs.

- YouTube embeds can fail when a video is private, unavailable, restricted, or has embedding disabled. The player provides a link to open the video on YouTube.
- Google Drive playback depends on the file owner’s sharing permissions. The owner must grant the intended students access. This milestone does not use Google OAuth or verify Drive permissions, so a syntactically valid link does not guarantee playback.
- The `Content-Security-Policy` in `next.config.ts` restricts frames to the Cloudflare Stream, YouTube privacy-enhanced, and Google Drive hosts used by the player. Preserve this `frame-src` allowlist when setting headers in a reverse proxy or CDN; do not replace it with `frame-src *`.
- Cloudflare upload and signed playback still require the existing `services.cloudflare_stream` configuration in Laravel. YouTube and Google Drive do not require application API keys for this link-based integration.

## Deploy on Vercel

The easiest way to deploy your Next.js app is to use the [Vercel Platform](https://vercel.com/new?utm_medium=default-template&filter=next.js&utm_source=create-next-app&utm_campaign=create-next-app-readme) from the creators of Next.js.

Check out our [Next.js deployment documentation](https://nextjs.org/docs/app/building-your-application/deploying) for more details.

# Cloudflare Stream webhook setup

Webhook delivery is optional. The admin status endpoint polls Cloudflare and remains the local development fallback.

References: [Cloudflare Stream webhooks](https://developers.cloudflare.com/stream/manage-video-library/using-webhooks/), [Direct Creator Uploads](https://developers.cloudflare.com/stream/uploading-videos/direct-creator-uploads/), and [resumable TUS uploads](https://developers.cloudflare.com/stream/uploading-videos/resumable-uploads/).

Cloudflare allows one Stream webhook subscription per account. Before changing it, inspect the current subscription with the account's Cloudflare API credentials and coordinate ownership with any service already using it. This application never creates or overwrites the account subscription automatically.

For production, use the public HTTPS endpoint:

```text
https://<backend-domain>/api/webhooks/cloudflare/stream
```

After the account owner confirms that this endpoint should receive the account's Stream notifications, configure the subscription in Cloudflare. Store the returned webhook signing secret only on the backend as `CLOUDFLARE_STREAM_WEBHOOK_SECRET`; do not add it to a frontend environment variable. The handler verifies `Webhook-Signature` before reading the event, then fetches canonical video state from Cloudflare before changing an asset.

Cloudflare cannot deliver webhooks to localhost or private IP addresses. For a local end-to-end webhook check, start an optional Cloudflare Quick Tunnel to the backend, use its public HTTPS URL temporarily, and remove or restore the account subscription afterward. Polling works without a tunnel or webhook subscription.

Configure the upload and playback credentials and `CLOUDFLARE_STREAM_ALLOWED_ORIGINS` separately in the backend environment. Use exact frontend origins, including the scheme and optional port, separated by commas. Local development defaults to `http://localhost:3000`.

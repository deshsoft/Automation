# Social Publisher

Write one post (text, photo or video) and publish it to many Facebook Pages,
Instagram business accounts, YouTube channels and TikTok accounts at once,
right now or at a scheduled time.

Built with Laravel 13 and MySQL. It runs on ordinary cPanel shared hosting:
no Redis, Docker or Node.js is needed on the server.

## How it works

1. **Accounts** page: connect accounts with the official login of each platform.
   - One Facebook login adds every Page you choose plus the Instagram business account linked to each Page.
   - YouTube and TikTok: connect once per channel or account.
2. **New post**: tick the accounts, write the caption, attach a photo or video, then choose *Post now* or *Schedule*.
3. A background queue publishes to each account. A gap between accounts (default 1 minute) keeps
   Facebook from treating identical posts on many Pages as spam.
4. The post page shows the result for every account, with a **View post** link, the exact error message
   on failure, and a **Retry failed** button.

**Compose options** (only the options for the selected platforms are shown, next to a live preview):

- A different caption for each platform
- **Video thumbnail** (JPG/PNG/WebP up to 20 MB, automatically shrunk to a JPG under YouTube's 2 MB limit): Facebook and Instagram use it as the cover, YouTube sets it as the custom thumbnail
  (needs a phone-verified channel; if YouTube rejects it, the video is still published and the post page shows why). TikTok does not accept one.
- **Share a link** (Facebook Pages only): paste the link of a public post, article or video and every selected Page shares it
- **Facebook share mode**: post on one main Page, and the other selected Pages share that post
  (all likes and comments gather on one post). The default is a separate post on every Page.
- Location (Facebook and Instagram), given as the numeric ID of the place's Facebook Page
- Instagram: tag people and invite up to 3 collaborators
- YouTube: title, tags, visibility (public, unlisted or private)
- TikTok: who can watch, and whether comments, Duet and Stitch are allowed

Facebook Groups are not supported: Meta shut down the Groups API in April 2024.
Facebook does not let apps tag people in Page posts or search for places without special approval.

| Platform | Text only | Photo | Video | Notes |
|---|---|---|---|---|
| Facebook Page | ✅ | ✅ | ✅ | Personal profiles cannot be automated (Meta removed that API) |
| Instagram | ❌ | ✅ JPG only | ✅ as Reel | Business or Creator account linked to a Facebook Page |
| YouTube | ❌ | ❌ | ✅ | Uploaded in 8 MB chunks, category "News & Politics" by default |
| TikTok | ❌ | ✅ | ✅ | Posts are private until TikTok audits your app |

## Go Live on several Facebook Pages

**● Go Live** creates a live video on every selected Page and shows each Page's server and stream key.
Then you stream once:

- **Computer:** OBS Studio with the free [Multiple RTMP outputs](https://github.com/sorayuki/obs-multi-rtmp/releases) plugin
  (one target per Page)
- **Phone:** Larix Broadcaster (one connection per Page, all enabled)

**End live on all Pages** stops them all. Each Page needs about 4 Mbps of upload speed.

**Share mode** saves bandwidth: only one main Page broadcasts. After you start streaming, click
**Share to other Pages** and the other Pages share the main Page's live video.
This needs the `publish_video` permission in the Meta app. After adding it, reconnect Facebook once on the Accounts page.

## Download videos from YouTube and Facebook

**Download video** (sidebar): paste up to 10 YouTube or Facebook links (one per line), choose a quality
(Best, 1080p, 720p, 480p or MP3 audio only) and click **Download**. The page refreshes by itself; when a video
is ready, click **Save**. Files are deleted from the server after `DOWNLOAD_KEEP_DAYS` (default 3) days.
Only download videos you own or have permission to use.

It uses the free [yt-dlp](https://github.com/yt-dlp/yt-dlp) program, and two optional helpers:

- **ffmpeg** joins the separate video and audio streams of HD videos and makes MP3s.
  Without it, YouTube videos often come only in 360p.
- **Deno** lets yt-dlp read every YouTube format.

Install:

- **Mac:** `brew install yt-dlp ffmpeg deno` (found automatically)
- **cPanel / Linux:** `php artisan downloads:install --ffmpeg --deno`
  (puts all three in `storage/app/bin`; the scheduler updates yt-dlp every week)

If a download fails with *"Sign in to confirm you're not a bot"* (YouTube often blocks shared-hosting IPs)
or a Facebook video needs a login, export a `cookies.txt` from your browser with the
"Get cookies.txt LOCALLY" extension, upload it outside `public_html`, and set `YTDLP_COOKIES=/home/USER/cookies.txt`.

## Run it locally (Mac)

```bash
php artisan serve --port=8090        # the dashboard: http://localhost:8090
php artisan schedule:work            # runs scheduled posts and the queue every minute
```

Create a login if you need another one:

```bash
php artisan app:create-user
```

Run the tests (SQLite in memory by default, or against MySQL like production):

```bash
php artisan test
DB_CONNECTION=mysql DB_DATABASE=social_automation_test php artisan test
```

> Instagram and TikTok download the photo or video from your server, so they need a public
> `https://` address. On your Mac, use a free [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/)
> (`cloudflared tunnel --url http://localhost:8090`) and set `APP_URL` in `.env` to the tunnel address.
> Facebook Pages and YouTube work without it (the file is uploaded directly).

## Getting the free API keys

Each platform needs a free developer app. In every app, register these redirect URLs
(replace the domain with your `APP_URL`):

```
https://your-domain.com/connect/meta/callback
https://your-domain.com/connect/google/callback
https://your-domain.com/connect/tiktok/callback
```

Then put the keys in `.env`.

### Facebook Pages and Instagram → `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`

1. Go to <https://developers.facebook.com/apps> → **Create app**. Pick the use cases
   **Manage everything on your Page** and **Manage messaging & content on Instagram**
   (or an app of type **Business**).
2. In the login product settings (*Facebook Login* or *Facebook Login for Business*), add the redirect URL above to
   **Valid OAuth Redirect URIs**. If your app uses *Facebook Login for Business*, create a **Configuration**
   (user access token) with the permissions listed in step 4, and put its ID in `FACEBOOK_CONFIG_ID`.
3. Copy the **App ID** and **App Secret** from *App settings → Basic*.
4. While the app is in **Development mode**, it works for everyone who has a role in the app (you).
   That is enough for your own Pages. To let other people connect their Pages (SaaS), submit
   *App Review* for `pages_manage_posts`, `publish_video`, `pages_read_engagement`, `pages_show_list`,
   `business_management`, `instagram_basic` and `instagram_content_publish`.
5. Instagram: switch each Instagram account to a **Professional (Business or Creator)** account and link it to a Facebook Page.

### YouTube → `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`

1. <https://console.cloud.google.com> → create a project → **APIs & Services → Library** → enable **YouTube Data API v3**.
2. **OAuth consent screen**: choose *External*, fill in the app name and your email, and add yourself as a test user.
3. **Credentials → Create credentials → OAuth client ID → Web application**, and add the Google redirect URL.
4. **Important:** while the consent screen is in *Testing*, Google expires the connection after **7 days**.
   Click **Publish app** (switch to *In production*). For personal use you can skip verification.
   You will see an "unverified app" warning; click *Advanced → continue*.
5. Limits: the free quota allows about **6 uploads per day in total** for the whole project.
   Request more under *IAM & Admin → Quotas* (free). Until Google audits the app, uploaded videos may be locked to **private**.
   Request the audit at <https://support.google.com/youtube/contact/yt_api_form>.

### TikTok → `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`

1. <https://developers.tiktok.com> → **Manage apps → Connect an app**.
2. Add the **Login Kit** and **Content Posting API** products. Turn on **Direct Post**. Add the TikTok redirect URL.
3. Under *URL properties*, **verify your domain**, because TikTok downloads media from `APP_URL`.
4. Request the scopes `user.info.basic` and `video.publish`, then submit the app for review.
5. Until TikTok audits the app, posts are published as **private (only me)**. The app detects this and
   posts publicly as soon as TikTok allows it.

## Deploying to cPanel

Requirements: **PHP 8.3 or newer**, MySQL, SSH or Terminal access (recommended), and Cron Jobs.

1. On your Mac, build the frontend: `npm run build` (creates `public/build`).
2. Upload the project (without `node_modules`) to a folder outside `public_html`, e.g. `/home/USER/social`.
3. Point the domain or subdomain **document root** to `/home/USER/social/public`
   (cPanel → Domains). If you cannot change it, symlink `public_html` to `public`.
4. In cPanel, create a MySQL database and user, then copy `.env.example` to `.env` and fill in:
   `APP_URL=https://your-domain.com`, `APP_ENV=production`, `APP_DEBUG=false`, the `DB_*` values and the API keys.
5. In Terminal:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   php artisan app:create-user
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. **Cron Jobs** → add one job that runs every minute:
   ```
   * * * * * cd /home/USER/social && php artisan schedule:run >> /dev/null 2>&1
   ```
   This single cron job publishes scheduled posts and processes the publishing queue.
7. **Upload size**: in cPanel → *MultiPHP INI Editor*, raise `upload_max_filesize` and `post_max_size`
   (e.g. `512M`) and `max_execution_time` (e.g. `300`). Set `MAX_UPLOAD_MB` in `.env` to the same size.
8. Turn on **SSL** (AutoSSL / Let's Encrypt in cPanel). Instagram, TikTok and Facebook require `https`.

Uploaded media is deleted automatically 7 days after a post finishes (`posts:prune-media`) to save disk space.

## Code map

| Path | Purpose |
|---|---|
| `app/Services/Connectors` | OAuth login and token refresh for Meta, Google and TikTok |
| `app/Services/Publishing` | One publisher per platform, plus `PostDispatcher`, which queues the jobs |
| `app/Jobs/PublishPostTarget.php` | Publishes one post to one account; retries network errors, polls Instagram and TikTok processing |
| `app/Services/Downloading` | `VideoDownloader`, which runs yt-dlp; `app/Jobs/DownloadVideo.php` queues it |
| `app/Console/Commands` | `posts:publish-due`, `posts:prune-media`, `downloads:install`, `downloads:prune`, `app:create-user` |
| `routes/console.php` | The schedule that the cron job runs |
| `tests/Feature` | 131 tests; every platform API is faked |

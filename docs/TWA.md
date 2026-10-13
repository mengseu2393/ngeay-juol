# Android app (Trusted Web Activity)

The Android app is the mobile shell (`/m`) packaged as a **Trusted Web Activity**:
a thin Android wrapper that opens the site full-screen in Chrome, with no URL
bar, a real splash screen and a Play Store listing. There is no separate app
code — every change to `/m` ships to the app the moment it is deployed.

iPhone has no equivalent; there the app stays an installed PWA
(Share → Add to Home Screen, which `/m` explains in its install card).

## What the site already provides

| Requirement | Where |
|---|---|
| HTTPS, a service worker, a manifest with 192/512 + maskable icons | `public/sw.js`, `ManifestController` (`/manifest.json`) |
| `start_url` inside the app (`/m` once `MOBILE_SHELL_ENABLED=true`) and a stable `id` (`/login`) | `ManifestController` |
| Digital Asset Links — proves the app and the site belong together, without it Android shows a URL bar | `/.well-known/assetlinks.json` (route in `routes/web.php`), driven by `TWA_PACKAGE_NAME` / `TWA_SHA256_FINGERPRINTS` |

Turn `/m` on for everyone (`MOBILE_SHELL_ENABLED=true`) before publishing: the
app starts at the manifest's `start_url`.

## Decisions needed before building

1. **Package name** — permanent once published, e.g. `app.ngeayjuol.twa`.
2. **Play Console account** that will own the listing (one-time USD 25 fee).
3. **Who keeps the upload key.** Use Play App Signing: Google holds the app
   signing key; you keep only the upload key (a `.keystore` file + passwords —
   store them in a password manager, losing them means contacting Google support).
4. **Production domain** the app opens (must be the HTTPS domain serving `/m`).

## Building (Bubblewrap)

On a machine with Node 18+ and a JDK (Bubblewrap can download both):

```bash
npm i -g @bubblewrap/cli
mkdir ngeay-juol-android && cd ngeay-juol-android
bubblewrap init --manifest https://YOUR-DOMAIN/manifest.json
#   package id      → the package name decided above
#   start URL       → /m
#   status bar /    → #059669 (brand green, matches the /m top bar)
#   navigation bar
#   splash bg       → #f0fdf4
#   signing key     → create the upload key here, or point to the existing one
bubblewrap build          # → app-release-bundle.aab (upload to Play) + app-release-signed.apk (sideload test)
```

Keep the generated project (`twa-manifest.json`, the keystore reference) in a
private repository — `bubblewrap update` regenerates the Android project from it.

## Publishing the Asset Links

After the first upload to Play Console, copy **both** SHA-256 fingerprints
(Setup → App integrity → App signing: the *app signing key* and the *upload
key*) into the server's `.env`:

```dotenv
TWA_PACKAGE_NAME=app.ngeayjuol.twa
TWA_SHA256_FINGERPRINTS=AB:CD:...:01,12:34:...:EF
```

Check it:

```bash
curl https://YOUR-DOMAIN/.well-known/assetlinks.json
# Google's own verifier:
curl "https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://YOUR-DOMAIN&relation=delegate_permission/common.handle_all_urls"
```

If the app shows a URL bar at the top, the Asset Links don't match: wrong
package name, a missing fingerprint (usually the Play App Signing one), or a
cached old file (nginx serves `/.well-known` through Laravel — no static file
may shadow it).

## Updating

Web changes need nothing: deploy the site. Rebuild the app only to change its
name, icon, colours, package-level settings or the Android target SDK
(Play requires a recent one each year): `bubblewrap update && bubblewrap build`,
bump the version, upload the new `.aab`.

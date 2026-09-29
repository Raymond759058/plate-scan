# PlateScan — Vehicle License Plate Scanner & Registration

A self-contained PHP 8 application for scanning vehicle license plates (camera or
photo upload), registering vehicles, and browsing the registry — with registration
fees converted into the owner's currency using live exchange rates.

Built with **PHP 8+, PDO, MySQL (InnoDB/utf8mb4), HTML5, native CSS3 and vanilla
JavaScript**. No frameworks (no Laravel/CodeIgniter, no React/Vue/Angular, no
Bootstrap/jQuery) — every line is plain code you can read top to bottom.

---

## Contents

- [Features](#features)
- [Project structure](#project-structure)
- [Requirements](#requirements)
- [Local setup (XAMPP / Apache / PHP built-in server)](#local-setup-xampp--apache--php-built-in-server)
- [Database setup](#database-setup)
- [Configuration (`includes/config.php`)](#configuration-includesconfigphp)
- [License plate scanner (OCR) setup](#license-plate-scanner-ocr-setup)
- [Deploying to iFastNet shared hosting](#deploying-to-ifastnet-shared-hosting)
- [Security notes](#security-notes)
- [API summary](#api-summary)
- [Troubleshooting](#troubleshooting)

---

## Features

- **Camera & upload plate scanner** — live camera preview via `navigator.mediaDevices.getUserMedia`,
  a capture button, drag-and-drop / click-to-browse photo upload, a bounding-box overlay drawn on the
  captured frame, and a confidence score. The scanned plate auto-fills the registration form
  (still editable). Everything runs over `fetch`/AJAX — no page reloads.
- **Manual vehicle registration** — owner, phone, make, model, colour and body type are always
  typed by the user; only the plate is machine-read (and can be corrected by hand).
- **Live exchange-rate fee converter** — the fee is fixed in USD and converted to the owner's chosen
  currency using [open.er-api.com](https://www.exchangerate-api.com/docs/free) as the primary source
  and [api.frankfurter.dev](https://frankfurter.dev/) as a fallback. Rates are cached in MySQL for one
  hour; if both APIs are unreachable, the most recent cached rate is used so the app keeps working.
- **Registry search** — search by plate, owner or make/model, filter by body type and date range,
  paginated results, fee shown in both USD and the currency actually paid.
- **English / Chinese UI** — a small `i18n.js` dictionary switch, saved in `localStorage`.
- **Security by default** — PDO prepared statements everywhere, strict server-side validation,
  output escaping, CSRF tokens on every POST, per-IP rate limiting (30 req/min/endpoint), a `.htaccess`
  HTTPS redirect, and error messages that never leak stack traces or credentials.

---

## Project structure

```
/
├── api/
│   ├── register.php   → validate + store a vehicle registration
│   ├── rates.php      → cached/live exchange rates
│   ├── scan.php       → OCR: image or camera frame → plate text
│   └── vehicles.php   → search + paginate the registry
├── assets/
│   ├── css/style.css  → the entire stylesheet (no frameworks)
│   └── js/
│       ├── app.js           → camera, upload, scan, fee calc, forms, search
│       ├── i18n.js           → translation dictionary + language switch
│       └── ocr-fallback.js   → in-browser OCR (Tesseract.js) used only if the server can't OCR
├── includes/
│   ├── config.php     → all settings (edit this, or see config.local.php below)
│   ├── db.php         → PDO connection helper
│   ├── helpers.php    → security, validation, rate limiting, HTTP client, exchange-rate logic
│   ├── layout.php     → shared HTML header/footer
│   └── schema.sql     → database schema
├── .htaccess
├── index.php     → scanner + registration form
├── vehicles.php  → registry search
├── manual.php    → user manual + API docs
└── README.md
```

---

## Requirements

- PHP **8.0+** with the **pdo_mysql**, **curl** (recommended) and **gd** (recommended) extensions.
- MySQL 5.7+ / MariaDB 10.3+ (InnoDB).
- Apache with `mod_rewrite` and `mod_headers` (for `.htaccess`), or any web server that can be
  configured similarly — the app itself doesn't require Apache.
- A camera + a browser that supports `getUserMedia` for live scanning (Chrome, Edge, Firefox, Safari
  15+). **Camera access requires HTTPS** (or `http://localhost` during development) — this is a
  browser security rule, not something the app can bypass.

No Composer packages and no npm build step are required; everything ships ready to run.

---

## Local setup (XAMPP / Apache / PHP built-in server)

### Option A — XAMPP / Apache

1. Copy the project folder into `htdocs/plate-scan` (XAMPP) or your Apache vhost root.
2. Start Apache and MySQL from the XAMPP control panel.
3. Create the database and import the schema (see [Database setup](#database-setup)).
4. Edit `includes/config.php` (or create `includes/config.local.php`, see below) with your local
   MySQL credentials.
5. Visit `http://localhost/plate-scan/` (camera scanning needs `localhost` or HTTPS — see above).

### Option B — PHP's built-in server (fastest for trying it out)

```bash
cd plate-scan
php -S localhost:8080
```

Then open `http://localhost:8080/`. This is fine for development; use real Apache/Nginx (or
iFastNet, below) for anything real.

### Keeping your settings across updates

Instead of editing `includes/config.php` directly, create **`includes/config.local.php`**:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'plate_scan');
define('DB_USER', 'root');
define('DB_PASS', '');
```

`config.php` loads this file first and only fills in values you *haven't* already defined, so your
local settings survive re-uploading `config.php` from a fresh copy of the project. This file is
already blocked from public access by `.htaccess` and matches `*.local.php`, which you should also
add to your own VCS ignore list.

---

## Database setup

1. Create an empty database (utf8mb4):

   ```sql
   CREATE DATABASE plate_scan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Import the schema:

   ```bash
   mysql -u youruser -p plate_scan < includes/schema.sql
   ```

   This creates three InnoDB tables:

   | Table         | Purpose                                                              |
   |---------------|-----------------------------------------------------------------------|
   | `vehicles`    | one row per registered vehicle; `plate_number` is unique              |
   | `rate_cache`  | the last fetched USD exchange rates, with a timestamp (1 h TTL)       |
   | `rate_limits` | per-IP, per-endpoint request counters for the 30 req/min rate limit   |

   Indexes are already defined on `plate_number`, `owner_name`, `make`+`model`, `body_type`,
   `created_at` (vehicles) and `ip_address` (rate_limits) for fast search and lookups.

3. Point `includes/config.php` (or `config.local.php`) at that database — see below.

---

## Configuration (`includes/config.php`)

All settings are plain `define()` constants with sensible defaults; open the file for full comments.
The ones you're most likely to change:

| Constant                | Default                  | Meaning                                                    |
|--------------------------|---------------------------|--------------------------------------------------------------|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | `localhost` / `plate_scan` / `root` / `` | MySQL connection |
| `REGISTRATION_FEE_USD`   | `50.00`                  | The flat registration fee, in US dollars                   |
| `PER_PAGE`               | `10`                     | Rows per page in the registry search                       |
| `RATES_TTL`              | `3600`                   | How long a cached exchange rate is reused, in seconds       |
| `RATE_LIMIT_MAX` / `RATE_LIMIT_WINDOW` | `30` / `60` | API rate limit: 30 requests per 60 seconds per IP per endpoint |
| `CURRENCIES`             | 17 common currencies      | Currencies offered in the registration form                |
| `BODY_TYPES`             | Sedan, SUV, Hatchback, Pickup, Van, Motorcycle, Lorry | Vehicle body types |
| `OCR_PROVIDER`           | `tesseract,ocrspace`     | Server-side OCR engines to try, in order — see next section |

---

## License plate scanner (OCR) setup

`api/scan.php` can read plates using any combination of these, tried **in the order listed in
`OCR_PROVIDER`** (a comma-separated string). The first engine that returns a confident plate wins.

| Engine | Constant(s) | Notes |
|---|---|---|
| `tesseract` | `TESSERACT_PATH` | Uses the local `tesseract` CLI via `exec()`. Free, no API key, but `exec()` and a Tesseract install are usually **not available on shared hosting** — check with your host, or use one of the API-based engines below. |
| `ocrspace` | `OCR_SPACE_KEY` | Free tier at [ocr.space/ocrapi](https://ocr.space/ocrapi) (the shipped `helloworld` key is a shared demo key — get your own for real use; 1 MB image limit, images are auto-compressed to fit). |
| `platerecognizer` | `PLATE_RECOGNIZER_TOKEN`, `PLATE_RECOGNIZER_REGIONS` | The most accurate option, purpose-built for plates. Needs a token from [platerecognizer.com](https://platerecognizer.com/). |
| `browser` | — | Skips server OCR entirely. |

**If every configured server engine is unavailable** (e.g. shared hosting with no `exec()` and no
API keys set), `api/scan.php` answers `OCR_UNAVAILABLE`, and the browser automatically falls back to
**Tesseract.js** (`assets/js/ocr-fallback.js`), which downloads a small OCR engine from jsDelivr and
reads the plate on the visitor's own device, then sends the recognised words to `api/scan.php` (as
`client_words`) so the server applies the same plate-picking logic either way. This means the
scanner works even with zero configuration, just more slowly on the first scan (the browser has to
download the OCR engine once).

For the best accuracy in production, get a free `ocrspace` key (takes a minute, no credit card) and
put it in `config.local.php`:

```php
define('OCR_SPACE_KEY', 'your-real-key-here');
define('OCR_PROVIDER', 'tesseract,ocrspace'); // tries the local binary first if present, then the API
```

---

## Deploying to iFastNet shared hosting

1. **Create the MySQL database** in the iFastNet / vDeck control panel (Databases → MySQL Databases).
   Note the host, database name, username and password it gives you — iFastNet database hostnames
   are *not* always `localhost`.
2. **Import the schema** using phpMyAdmin (Databases → phpMyAdmin) by opening `includes/schema.sql`
   in the SQL tab, or via the `mysql` CLI if you have shell/SSH access.
3. **Upload the files** with an SFTP client (FileZilla, etc.) into your domain's web root
   (usually `public_html/`, or a subfolder if this isn't the primary site).
4. **Configure the database connection.** Create `includes/config.local.php` on the server with the
   real credentials from step 1 (see [Local setup](#keeping-your-settings-across-updates) for the
   format). Never commit real credentials to `config.php` itself.
5. **Enable HTTPS.** iFastNet includes free SSL (Let's Encrypt via AutoSSL/cPanel, or similar) —
   turn it on in the control panel. `.htaccess` already redirects HTTP → HTTPS; camera scanning
   *requires* HTTPS in the browser, so this step isn't optional.
6. **Check PHP version & extensions.** In the control panel's "PHP Selector" / "MultiPHP", choose
   PHP 8.0+ and make sure `pdo_mysql` and `curl` are enabled (they usually are by default). `exec()`
   is commonly disabled on shared hosting — that's fine, the app falls back automatically as
   described above.
7. **Set your OCR provider.** Shared hosting rarely allows a local Tesseract binary, so set
   `OCR_PROVIDER` to `ocrspace` (with your key) or `platerecognizer`, or simply leave the defaults —
   the browser fallback will handle scanning either way.
8. **Verify OPcache.** If the PHP Selector offers OPcache, turn it on — it's a pure performance win
   for repeated requests on shared hosting; the app needs no special support for it.
9. Visit your domain and confirm: the scanner loads, a test scan returns a plate, registering a
   vehicle succeeds, and the registry page lists it.

---

## Security notes

- Every database query uses **PDO prepared statements** with bound parameters — no string-built SQL
  anywhere in the codebase.
- All user-supplied text is validated server-side with explicit regular expressions (plate format,
  phone format, name/make/model/colour character sets, currency and body-type allow-lists) before it
  ever reaches the database, and is escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` (the
  `e()` helper) wherever it's echoed into HTML.
- Every `POST` endpoint requires a per-session **CSRF token** (`X-CSRF-Token` header, checked with
  `hash_equals()`), issued via a `csrf-token` `<meta>` tag on each page.
- **Rate limiting** (30 requests/minute per IP per endpoint, stored in MySQL) protects the OCR and
  registration endpoints from abuse.
- PHP's own error display is switched off (`display_errors=0`); exceptions and fatal errors are
  caught, logged privately to `includes/error.log` (blocked from the web by `.htaccess`), and the
  visitor only ever sees a generic, translated error message — no stack traces, file paths, or
  database credentials are ever returned in a response.
- `.htaccess` forces HTTPS, blocks direct access to `includes/`, and denies `.sql`, `.log`, `.env`,
  `.ini`, `.bak`, `.sh` and dotfiles.
- Phone numbers are stored in full but **masked** (`+60••••789`) in the public registry response.

---

## API summary

All endpoints are under `/api/`, always respond with `Content-Type: application/json`, and follow
one shape:

```jsonc
// success
{ "status": "success", "data": { /* ... */ } }
// error
{ "status": "error", "error": { "code": "SOME_CODE", "message": "Human-readable message" } }
```

| Method | Endpoint            | Purpose                                   | Auth (CSRF) |
|--------|----------------------|--------------------------------------------|:-----------:|
| `POST` | `/api/scan.php`      | Read a plate from an uploaded/captured image (or browser-OCR words) | ✔ |
| `POST` | `/api/register.php`  | Validate & store a vehicle registration    | ✔ |
| `GET`  | `/api/rates.php`     | Cached/live USD exchange rates              | – |
| `GET`  | `/api/vehicles.php`  | Search & paginate the registry              | – |

Full request/response examples, all field-level validation error keys, and the complete error-code
table are on the in-app **Manual & API** page (`manual.php`) — that page is generated from the same
source of truth as the code, so it never goes stale.

---

## Troubleshooting

**Camera permission is blocked / no camera prompt appears.**
Browsers only allow camera access on secure origins (`https://` or `http://localhost`). Check the
site is served over HTTPS, then check the browser's own per-site permission (the padlock/site-info
icon in the address bar) hasn't been set to "Block" for camera. Use **Upload photo** as a fallback —
it works everywhere, no camera permission needed.

**Scans return `OCR_UNAVAILABLE`.**
No server-side OCR engine is configured or working (no `tesseract` binary, no `OCR_SPACE_KEY`, no
`PLATE_RECOGNIZER_TOKEN`). This isn't an error state for visitors — the browser automatically
switches to the in-browser Tesseract.js engine. To get faster server-side scanning instead, set
`OCR_SPACE_KEY` (free) in `config.local.php`.

**Scans return `PLATE_UNREADABLE` or `NO_PLATE_DETECTED` a lot.**
Usually the plate isn't filling enough of the frame, there's glare, or the photo is blurry/dark. The
in-app guidance ("Adjust lighting or reposition the camera") is shown to the visitor for this reason
— ask them to move closer and retake the shot square-on to the plate.

**"cURL limits" / exchange rates never update / register fails with `RATES_UNAVAILABLE`.**
Some shared hosts restrict outbound connections or disable `curl`. `helpers.php`'s `http_request()`
automatically falls back to PHP's `file_get_contents()` with `allow_url_fopen` if `curl` isn't
available — check that setting is `On` in your PHP configuration (PHP Selector → Options). If your
host blocks outbound HTTP entirely, rates will keep serving the last successfully cached value
(`"source": "stale"`) rather than failing outright, for up to `RATES_RETRY_AFTER` seconds between
retries.

**SSL configuration / mixed-content warnings.**
Make sure every asset loads over `https://` (the app itself only ever uses relative URLs, so this is
almost always an SSL certificate issue on the host, not the app). Confirm the certificate covers the
exact domain/subdomain you're visiting, and that `.htaccess`'s HTTP→HTTPS redirect is active.

**"Too many requests" (`RATE_LIMITED`, HTTP 429).**
By default each IP can make 30 requests/minute to each endpoint. This resets automatically after the
`retry_after` seconds given in the response; raise `RATE_LIMIT_MAX` in `config.php` if you have a
legitimate reason to allow more (e.g. a kiosk shared by many staff behind one IP).

**Duplicate plate on registration (`PLATE_EXISTS`, HTTP 409).**
`plate_number` is a unique column by design — each plate can only be registered once. Search the
registry for the existing entry rather than re-registering it.

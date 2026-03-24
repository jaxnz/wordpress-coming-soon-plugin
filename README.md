# Simple Coming Soon Mode

Custom coming soon screen you can toggle on/off, including logo, headline, and supporting text controls.

## Features
- Toggle coming soon mode while letting admins view the live site.
- Upload/select a logo from the media library.
- Set a headline and rich text message (links/paragraphs supported).
- Optional contact form on the coming soon page.
- Built-in spam protection for contact submissions (honeypot, timing checks, rate limiting).
- Optional Cloudflare Turnstile support for stronger bot protection.
- Mailgun delivery support for contact submissions, including `To`, `CC`, and `BCC` recipients.
- SEO metadata support for the coming soon page (title, description, Open Graph/Twitter tags, JSON-LD).
- Optional indexable mode (HTTP `200`) so search engines can index the coming soon page when desired.
- Export and import the plugin's saved settings as JSON, including passwords and API keys.
- Lightweight inline front-end styling; no dependency on your theme.

## Installation
1. Create a zip (optional): run `./package.sh` to generate `simple-coming-soon-mode.zip` in this folder.
2. Upload the zip via Plugins > Add New (or copy the folder to `wp-content/plugins/simple-coming-soon-mode`).
3. Activate **Simple Coming Soon Mode** in the WordPress Plugins screen.

## Usage
1. Go to `Settings > Coming Soon Mode`.
2. Toggle **Enable Coming Soon**, choose a logo, add your headline and message.
3. (Optional) Enable **Contact Form** and configure Mailgun:
   - Mailgun Domain (example: `mg.example.com`)
   - Mailgun API Key (`key-...`)
   - From Name / From Email
   - To / CC / BCC recipients (comma-separated)
4. (Optional) Configure **Spam Protection**:
   - Built-in protections are enabled automatically for the contact form.
   - For stronger filtering, create a Cloudflare Turnstile widget (`Cloudflare Dashboard > Turnstile > Add Site`) and paste the **Site Key** and **Secret Key** into the plugin settings, then enable Turnstile.
5. (Optional) Configure **SEO & Crawling**:
   - Add an SEO title/description for search and social previews.
   - Enable **Allow Search Indexing** if you want Google to index the coming soon page itself (this switches the response from HTTP `503` to HTTP `200`).
6. (Optional) Use **Export & Import** to download the plugin settings as JSON or restore them later.
7. Save changes. Visitors see the coming soon page; admins can still browse the site normally.

## Export / Import Notes
- The export contains the plugin's full saved settings, including the access password, Mailgun API key, and Turnstile keys.
- Importing replaces the current plugin settings with the values from the export file.
- The selected logo is stored as a WordPress attachment ID. If you import onto a different site, the logo will only restore if that attachment ID exists there too.

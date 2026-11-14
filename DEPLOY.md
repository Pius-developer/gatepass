# Deploying Gatepass to gatepass.ejikepius.dev (DirectAdmin)

## 1. Create the subdomain
**Subdomain Management → Add Subdomain**: `gatepass`, Document Root: **Default** (`/domains/gatepass.ejikepius.dev/public_html`).

In Cloudflare, add an `A` record for `gatepass` pointing to the server IP (orange cloud on), like the other subdomains.

## 2. Upload
1. File Manager → `/domains/gatepass.ejikepius.dev/`
2. Delete the empty `public_html` DirectAdmin created (the zip contains its own).
3. Upload `gatepass-deploy.zip` here and **Extract**. You should see `public_html`, `src`, `views`, `bin`, `data` side by side.
4. Delete the zip.

## 3. PHP
PHP 8.1+ (8.3 recommended) with **pdo_sqlite**, **curl**, **gd** and **mbstring**.
`data` and `public_html/uploads` must be writable (755).

## 4. Settings: `config.local.php`
Copy `config.local.example.php` to `config.local.php` (next to `src`, **not** inside `public_html`) and fill in:

- **`app_url`**: `https://gatepass.ejikepius.dev`
- **Email**: create `tickets@ejikepius.dev` in **E-mail Manager**, then put its password in the `mail` section.
  (You can also reuse `hello@ejikepius.dev`: change `username`, `from_email` and `reply_to`.)
- **Paystack** (optional): Paystack dashboard → **Settings → API Keys & Webhooks**. Paste the **test** keys first.
  Set the **Test Webhook URL** to `https://gatepass.ejikepius.dev/paystack/webhook`.
  With no keys, Gatepass uses its built-in demo checkout (no real money), which is fine for showing it off.

Test card for Paystack test mode: `4084 0840 8408 4081`, any future expiry, CVV `408`, PIN `0000`, OTP `123456`.

## 5. The cron job (reminders + releasing unpaid holds)
**Advanced Features → Cron Jobs → Create**:
- Minute `*/15`, Hour `*`, Day of month `*`, Month `*`, Day of week `*`
- Command:
  ```
  /usr/local/bin/php /home/ejikepiu/domains/gatepass.ejikepius.dev/bin/remind.php >/dev/null 2>&1
  ```
  (If your username isn't `ejikepiu`, use yours. The File Manager path bar shows it.)

**No cron jobs on your plan?** Set `cron_token` in `config.local.php` to a long random string,
then use cron-job.org to open `https://gatepass.ejikepius.dev/cron/<token>` every 15 minutes.

## 6. Check it
- Open https://gatepass.ejikepius.dev, sign up as `ejike_pius`, create an event with a free ticket and a paid one
- Get a free ticket with your own email: the email should arrive with QR codes and an `event.ics` file.
  Open the `.ics` on your phone and check "Add to calendar" works
- Buy a paid ticket (demo or Paystack test card)
- On your phone, open **Manage → Open scanner**, tap **Start camera** and scan the QR from the email.
  First scan: green "Welcome in". Second scan: amber "Already used"
- Don't run `bin/seed.php` on the live site: it's for local demo data only

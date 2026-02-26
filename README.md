# PHP + MySQL API

This backend serves the frontend routes under `/api/*` using PHP and MySQL (via PDO).

## Setup

1. Create a MySQL database (example: `prime`)
2. Copy env file and set credentials:

```bash
cp api/.env.example api/.env
```

Configure both:
- MySQL connection (`DB_*`)
- SMTP email delivery (`SMTP_*`) if you want confirmation emails sent after registration

3. Option A (recommended for first run): let the API auto-create tables from `api/schema.sql`
   - keep `PRIME_AUTO_MIGRATE=1` in `api/.env`

4. Option B (manual schema import):

```bash
mysql -u root -p prime < api/schema.sql
```

## Run

From the project root:

```bash
php -S 127.0.0.1:8000 api/router.php
```

The React app in `/Users/user/projects/prime/ui` proxies `/api/*` to `http://127.0.0.1:8000` in development.

## Notes

- `PRIME_SEED_DEFAULTS=1` seeds sample `resources` and `gallery` rows when empty.
- Registrations are stored in MySQL (`registrations` table), including the new `title` field.
- When `SMTP_ENABLED=1`, a styled HTML confirmation email is sent to the registrant after a successful save.
- The email includes a Training-section encouragement CTA and links to `TRAINING_URL` when set.
- If `TRAINING_URL` is not set, it falls back to `RESOURCES_URL`, then `FRONTEND_URL#training`.
- Stripe Checkout can be enabled for the Give Online modal with `STRIPE_ENABLED=1` and `STRIPE_SECRET_KEY`.
- The frontend requests a checkout session from `POST /api/payments/stripe/checkout-session`, and the API returns a Stripe-hosted checkout URL.
- Configure Stripe webhooks to `POST /api/payments/stripe/webhook` and set `STRIPE_WEBHOOK_SECRET` to keep `stripe_payments` statuses updated (paid/expired/etc.).

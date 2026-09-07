# CECT System

Final Year Project — **Campus Event & Club Ticketing (CECT) System**.

A web application for campus event discovery, registration/ticketing, organizer workflows, payments (ToyyibPay sandbox), Google sign-in, and email notifications.

## Features

- User registration, login, and Google OAuth
- Event browsing, booking, and payment flow (ToyyibPay)
- Organizer applications and event management
- Admin tools for oversight
- Email notifications (SMTP / Brevo)

## Tech stack

- PHP (XAMPP)
- MySQL (PDO)
- Composer (`google/apiclient`)
- PHPMailer
- Tailwind / Alpine-style front-end pages

## Screenshots

_Add screenshots of the home page, event booking, and admin dashboard here._

## Setup (local XAMPP)

1. Clone this repository into your XAMPP `htdocs` folder.
2. Install PHP dependencies:

   ```bash
   composer install
   ```

3. Copy environment template and fill in your secrets:

   ```bash
   cp .env.example .env
   ```

4. Create the MySQL database and import the schema:

   - Create database `cect_db` (or the name you set in `.env`)
   - Import [`includes/database.sql`](includes/database.sql)

5. Point your browser to:

   `http://localhost/cect_system/`

## Credentials & secrets

All API keys, OAuth secrets, SMTP passwords, and database credentials live in **`.env` only**.

- `.env` is gitignored and must never be committed
- Use [`.env.example`](.env.example) as a safe template for employers/reviewers
- Prefer ToyyibPay **sandbox** keys for demos

## Author

IKMAL NAZRIN BIN AZIZ

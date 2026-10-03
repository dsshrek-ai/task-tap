# Setup Guide

Task Tap is backed by **MyDataWorld** — the same shared database and single
sign-on as My Apps Hub. Every request needs a MyDataWorld login with an
`app_access` grant for `task-tap`. Maps / Calendar / default-reminder settings
live in the Hub's shared `user_preferences` table, so they're shared with your
other apps.

## 1. My Apps Hub (shared preferences + registration)

From the Hub repo (`my-apps-hub`, branch `feature/shared-preferences`):

1. In **phpMyAdmin** (MyDataWorld database, **SQL** tab) run, from the Hub's
   `api/schema.sql`, the **"SHARED: user_preferences"** block and the
   **"NEW APP: Task Tap"** block.
2. Upload the Hub's updated `api/api.php` to `seniorfamily.org/my-apps-hub-api/`
   (adds the `getPreferences` / `setPreference` actions).

## 2. Create Task Tap's tables

Still in phpMyAdmin's SQL tab, run everything in [`api/schema.sql`](api/schema.sql)
(seven `tt_*` tables).

## 3. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   `DB_NAME`, `DB_USER`, `DB_PASS` (same as your other MyDataWorld apps).
2. Upload `api/api.php`, `api/config.php`, and the `api/uploads/` folder
   (with its `.htaccess`) to `seniorfamily.org/task-tap-api/`, so the endpoint
   is `https://seniorfamily.org/task-tap-api/api.php`.
3. Make sure `task-tap-api/uploads/` is writable by PHP (File Manager →
   permissions 755 is usually enough). Attachments are stored there and are
   only ever served through the API to the task's owner.
4. Attachments can be up to 10 MB, but your host's PHP limits may be lower.
   If uploads of larger photos fail with "larger than the server allows",
   raise `upload_max_filesize` and `post_max_size` (cPanel → *Select PHP
   Version* / *MultiPHP INI Editor*).

## 4. Publish

Push this folder to `dsshrek-ai/task-tap`, then Settings → Pages → Deploy
from a branch → `main` / `(root)`. The app is at
`https://dsshrek-ai.github.io/task-tap/`.

## 5. Grant yourself access

```sql
INSERT INTO app_access (user_id, app_id)
SELECT u.id, a.id FROM users u JOIN apps a ON a.app_key = 'task-tap'
WHERE u.username = 'you@example.com'
ON DUPLICATE KEY UPDATE user_id = user_id;
```

Grant anyone you'll share tasks with as well — importing a transfer code
needs a Task Tap login.

## How things work

- **Reminders.** A web page can't pop up an alert while it's closed, so the
  reliable alert comes from your phone's calendar: **Add to Calendar** puts
  the task's reminder on the calendar event (Apple Calendar via an `.ics` with
  an alarm; Google Calendar uses your Google default notification). While
  Task Tap is open it also shows due reminders at the top of the Tasks list
  with Snooze (15 min / 1 hour / tomorrow 9 AM / custom) and Dismiss.
- **Repeats.** Completing a repeating task keeps that occurrence as completed
  and creates the next one, dated from the **due date** (due Oct 1, done
  Oct 2, every 3 months → next due Jan 1). The checklist comes forward
  unchecked; the reminder, repeat rule, and attachments carry over.
- **Sharing.** *Share Task* makes a 4-character code (no 0/O/1/I/L) that
  works once, for 5 minutes. It snapshots the task at that moment, attachments
  included; the recipient's copy is completely their own.
- **Preferences.** Settings can apply to *All my apps* (scope `global`) or
  *Only Task Tap* (scope `task-tap`). Lookup order: app → global → default.
  "Remember my choice" in the Maps/Calendar prompt saves globally.
- **Shared helper.** `js/mdw-prefs.js` is a copy of
  `my-apps-hub/shared/mdw-prefs.js` — Life Tempo and the Member App can
  include the same file to get the same Maps/Calendar choosers and settings.

# Task Tap

A simple personal app for tasks and appointments — what to do, when, what to
bring, who to contact, and where to go.

- **Tasks** — grouped Overdue / Today / Tomorrow / This Week / Later / Undated,
  pinned first, with one-tap Call, Text, Add to Calendar, and Navigate.
- **Task detail** — big action buttons (only the ones that apply), checklist,
  notes, attachments (photos, PDFs, documents), reminder, repeat, and
  **Share Task** with a 4-character code.
- **Add / Edit** — date optional; start / end / leave-by times; contact, phone,
  address; reorderable checklist; reminder (at time, 15 min … 1 day before, or
  custom); repeat (daily / weekly / monthly / yearly, or custom intervals,
  weekdays, day of month, end date, max times).
- **Completed** — history, with Reopen.
- **Import** — type a code to get your own copy of someone's task.
- **Settings** — preferred maps app, calendar, and default reminder, for all
  your apps or just Task Tap.

Built on the MyDataWorld stack: static HTML/CSS/JS on GitHub Pages, one PHP API
(`api/api.php`) on seniorfamily.org, shared MySQL tables prefixed `tt_`, My
Apps Hub single sign-on, and the Hub's shared `user_preferences`. See
[SETUP.md](SETUP.md).

Based on `Personal_Task_Appointment_App_Design.md`. Choices where the design
left room:

- An **Overdue** group sits above Today so past-due tasks aren't lost.
- "This Week" runs through Sunday.
- A reminder on an all-day task is relative to 9:00 AM that day.
- Transfer codes snapshot the task when created (the design's recommended
  option), so later edits by the sender don't change what's imported.

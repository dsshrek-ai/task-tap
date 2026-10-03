// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/task-tap-api/api.php",
  APP_KEY: "task-tap",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// The browser's time zone rides along on every request so task times and
// reminders are worked out in the user's own local time.
function browserTimeZone() {
  try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; }
}

// ---- Login (My Apps Hub SSO token, or a manual fallback) ----

const TOKEN_KEY = 'ttToken';
function getToken() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
function saveToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch (e) { /* private mode */ } }
function authHeaders() {
  const t = getToken();
  return t ? { Authorization: `Bearer ${t}` } : {};
}

async function readJson(res) {
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  let data = null;
  try { data = await res.json(); } catch (e) { /* not JSON */ }
  if (!res.ok) {
    const err = new Error((data && data.error) || `Request failed: ${res.status}`);
    err.userMessage = data && data.error;
    throw err;
  }
  return data;
}

async function fetchData(action, params) {
  if (!isConfigured()) throw new Error('not-configured');
  const qs = new URLSearchParams({ action, tz: browserTimeZone(), ...(params || {}) });
  return readJson(await fetch(`${CONFIG.API_URL}?${qs}`, { headers: authHeaders() }));
}

async function postAction(action, payload) {
  if (!isConfigured()) throw new Error('not-configured');
  return readJson(await fetch(CONFIG.API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain', ...authHeaders() },
    body: JSON.stringify({ action, tz: browserTimeZone(), ...(payload || {}) }),
  }));
}

async function uploadFile(taskId, file) {
  const form = new FormData();
  form.append('action', 'uploadAttachment');
  form.append('tz', browserTimeZone());
  form.append('taskId', String(taskId));
  form.append('file', file);
  return readJson(await fetch(CONFIG.API_URL, { method: 'POST', headers: authHeaders(), body: form }));
}

// Attachments are only served with the login header, so fetch the file and
// hand the browser a temporary blob URL to open.
async function openAttachment(id, fileName) {
  const win = window.open('', '_blank'); // opened now, inside the click, so it isn't blocked
  try {
    const res = await fetch(`${CONFIG.API_URL}?action=attachment&id=${id}`, { headers: authHeaders() });
    if (!res.ok) throw new Error('fetch failed');
    const url = URL.createObjectURL(await res.blob());
    if (win) {
      win.location.href = url;
    } else {
      const a = document.createElement('a');
      a.href = url; a.download = fileName; document.body.appendChild(a); a.click(); a.remove();
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) {
    if (win) win.close();
    throw e;
  }
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

function renderGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `<div class="card"><p class="note">Your login isn't set up for Task Tap yet. Ask to be granted the app in My Apps Hub, or open it again from there.</p></div>`;
    return;
  }
  container.innerHTML = `
    <div class="card">
      <h3>Log In</h3>
      <p class="note">Normally you won't see this — open Task Tap from My Apps Hub and it logs you in automatically.</p>
      <label for="g-email">Email</label>
      <input type="email" id="g-email" autocomplete="username">
      <label for="g-pw">Password</label>
      <input type="password" id="g-pw" autocomplete="current-password">
      <p><button type="button" class="btn" id="g-btn">Log In</button></p>
      <div id="g-msg"></div>
    </div>`;
  const email = document.getElementById('g-email');
  const pw = document.getElementById('g-pw');
  const msg = document.getElementById('g-msg');
  const btn = document.getElementById('g-btn');
  async function attempt() {
    if (!email.value.trim() || !pw.value) return;
    btn.disabled = true;
    msg.innerHTML = '';
    try {
      const res = await fetch(CONFIG.API_URL, {
        method: 'POST', headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ action: 'login', username: email.value.trim(), password: pw.value }),
      });
      const data = await res.json();
      if (!res.ok || !data.token) {
        msg.innerHTML = `<p class="note">${escapeHtml(data.error || 'Incorrect email or password.')}</p>`;
        btn.disabled = false;
        return;
      }
      saveToken(data.token);
      window.location.reload();
    } catch (e) {
      msg.innerHTML = '<p class="note">Something went wrong. Please try again.</p>';
      btn.disabled = false;
    }
  }
  btn.addEventListener('click', attempt);
  pw.addEventListener('keydown', ev => { if (ev.key === 'Enter') attempt(); });
}

function handleError(container, err) {
  console.error(err);
  const m = err && err.message;
  if (m === 'not-authorized') renderGate(container, getToken() ? 'denied' : 'no-token');
  else if (m === 'not-configured') container.innerHTML = `<p class="note">App isn't configured yet.</p>`;
  else if (err && err.userMessage) container.innerHTML = `<p class="note">${escapeHtml(err.userMessage)}</p>`;
  else container.innerHTML = `<p class="note">Couldn't load right now — check your connection and refresh.</p>`;
}

function showStatus(msg) {
  const el = document.getElementById('status');
  if (!el) return;
  el.textContent = msg || '';
  el.style.display = msg ? 'block' : 'none';
}

function errorText(err, fallback) {
  if (err && err.message === 'not-authorized') return 'Your login has expired — open Task Tap again from My Apps Hub.';
  return (err && err.userMessage) || fallback;
}

let _ssoDone = null;
function captureSso() {
  if (_ssoDone) return _ssoDone;
  _ssoDone = (async () => {
    const params = new URLSearchParams(window.location.search);
    const token = params.get('token');
    if (!token) return;
    params.delete('token');
    const rest = params.toString();
    window.history.replaceState({}, document.title, window.location.pathname + (rest ? '?' + rest : ''));
    saveToken(token);
  })();
  return _ssoDone;
}

// Shared preferences (maps/calendar/default reminder) come from the Hub.
function initPrefs() {
  MdwPrefs.init({ appKey: CONFIG.APP_KEY, getToken });
  return MdwPrefs.load();
}

// ---- Dates and times ----

function todayYmd(offsetDays) {
  const d = new Date();
  d.setDate(d.getDate() + (offsetDays || 0));
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function parseYmd(ymd) {
  const [y, m, d] = String(ymd).split('-').map(Number);
  return new Date(y, m - 1, d);
}

// "20:00" -> "8:00 PM"
function fmtTime(hm) {
  if (!hm) return '';
  const [h, m] = hm.split(':').map(Number);
  return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
}

function fmtDate(ymd, opts) {
  return parseYmd(ymd).toLocaleDateString(undefined, opts || { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

// "2026-10-14 08:00" -> "Wed, Oct 14 · 8:00 AM"
function fmtLocalDateTime(s) {
  if (!s) return '';
  const [d, t] = s.split(' ');
  return `${fmtDate(d, { weekday: 'short', month: 'short', day: 'numeric' })} · ${fmtTime(t)}`;
}

const REMINDER_CHOICES = [
  { value: '0', label: 'At time of task' },
  { value: '15', label: '15 minutes before' },
  { value: '30', label: '30 minutes before' },
  { value: '60', label: '1 hour before' },
  { value: '120', label: '2 hours before' },
  { value: '1440', label: '1 day before' },
];

function reminderLabel(r) {
  if (!r) return 'None';
  if (r.OffsetMinutes === null) return fmtLocalDateTime(r.At);
  const c = REMINDER_CHOICES.find(x => Number(x.value) === r.OffsetMinutes);
  return c ? c.label : `${r.OffsetMinutes} minutes before`;
}

const WEEKDAY_SHORT = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function repeatLabel(r) {
  if (!r) return 'None';
  const unit = { Daily: 'day', Weekly: 'week', Monthly: 'month', Yearly: 'year' }[r.Frequency];
  let s = r.Interval === 1 ? { Daily: 'Daily', Weekly: 'Weekly', Monthly: 'Monthly', Yearly: 'Yearly' }[r.Frequency]
                           : `Every ${r.Interval} ${unit}s`;
  if (r.Frequency === 'Weekly' && r.DaysOfWeek.length) s += ' on ' + r.DaysOfWeek.map(d => WEEKDAY_SHORT[d]).join(', ');
  if (r.Frequency === 'Monthly' && r.DayOfMonth) s += ` on day ${r.DayOfMonth}`;
  if (r.EndDate) s += `, until ${fmtDate(r.EndDate, { month: 'short', day: 'numeric', year: 'numeric' })}`;
  if (r.MaxOccurrences) s += `, ${r.MaxOccurrences} times`;
  return s;
}

// Digits only (keeping a leading +) for tel:/sms: links.
function dialable(phone) {
  return String(phone || '').replace(/[^\d+]/g, '');
}

// The calendar event for a task, in the shape MdwPrefs.addToCalendar wants.
function calendarEventFor(t) {
  const parts = [t.Notes];
  if (t.LeaveByTime) parts.unshift(`Leave by ${fmtTime(t.LeaveByTime)}`);
  if (t.ContactName || t.PhoneNumber) parts.push([t.ContactName, t.PhoneNumber].filter(Boolean).join(' · '));
  return {
    uid: `task-${t.Id}@task-tap`,
    title: t.Title,
    notes: parts.filter(Boolean).join('\n'),
    location: t.Address,
    date: t.Date,
    startTime: t.StartTime,
    endTime: t.EndTime,
    reminderMinutes: t.Reminder && t.Reminder.OffsetMinutes !== null ? t.Reminder.OffsetMinutes : null,
  };
}

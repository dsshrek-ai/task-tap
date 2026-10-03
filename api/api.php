<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Expose-Headers: Content-Disposition');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// Prepared SELECT -> all rows.
function q(string $sql, string $types = '', array $params = []): array {
  $stmt = db()->prepare($sql);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

// Prepared INSERT/UPDATE/DELETE -> insert id (0 when none).
function exec1(string $sql, string $types = '', array $params = []): int {
  $stmt = db()->prepare($sql);
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

// ---- Auth: shared MyDataWorld login + an app_access grant for 'task-tap' ----

const APP_KEY = 'task-tap';
const APP_NAME = 'Task Tap';

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $rows = q(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()',
    's', [$m[1]]
  );
  if (!$rows) {
    fail('Session expired or invalid -- please log in again', 401);
  }
  return $rows[0];
}

function hasAppAccess(array $user): bool {
  return (bool)q(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id WHERE aa.user_id = ? AND a.app_key = ?',
    'is', [(int)$user['id'], APP_KEY]
  );
}

function logAppUsage(int $userId): void {
  try {
    exec1(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1',
      'is', [$userId, APP_KEY]
    );
  } catch (mysqli_sql_exception $e) {
    // best-effort
  }
}

function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess($user)) {
    fail('Not authorized for ' . APP_NAME, 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

// ---- Dates and time zone ----
// The browser sends its IANA time zone (tz) with every request; task dates
// and times are the user's local wall clock, reminder/completion stamps are
// stored in UTC and converted for display.

function utc(): DateTimeZone {
  static $z = null;
  return $z ?? ($z = new DateTimeZone('UTC'));
}

function requestTz(string $tz): DateTimeZone {
  $tz = trim($tz);
  return in_array($tz, DateTimeZone::listIdentifiers(), true) ? new DateTimeZone($tz) : new DateTimeZone('America/Denver');
}

function nowUtc(): string {
  return gmdate('Y-m-d H:i:s');
}

function validDate(?string $s): bool {
  if ($s === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
    return false;
  }
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  return checkdate($m, $d, $y);
}

function cleanTime($v): ?string {
  $v = trim((string)($v ?? ''));
  if ($v === '') {
    return null;
  }
  if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:\d\d)?$/', $v, $m)) {
    fail('Times must look like 14:30');
  }
  return $m[1] . ':' . $m[2] . ':00';
}

function hm(?string $time): ?string {
  return $time === null ? null : substr($time, 0, 5);
}

// Local "Y-m-d H:i" (in $tz) -> UTC "Y-m-d H:i:s".
function localToUtc(string $local, DateTimeZone $tz): string {
  return (new DateTime($local, $tz))->setTimezone(utc())->format('Y-m-d H:i:s');
}

function utcToLocal(?string $utc, DateTimeZone $tz, string $format = 'Y-m-d H:i'): ?string {
  return $utc === null ? null : (new DateTime($utc, utc()))->setTimezone($tz)->format($format);
}

function addDays(string $date, int $days): string {
  return (new DateTime($date, utc()))->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
}

function daysBetween(string $a, string $b): int {
  return (int)(new DateTime($a, utc()))->diff(new DateTime($b, utc()))->format('%r%a');
}

// ---- Tasks ----

function strOrNull($v, int $max): ?string {
  $v = trim((string)($v ?? ''));
  return $v === '' ? null : mb_substr($v, 0, $max);
}

function ownTask(int $userId, int $id): array {
  $rows = q('SELECT * FROM tt_tasks WHERE id = ? AND user_id = ?', 'ii', [$id, $userId]);
  if (!$rows) {
    fail('Task not found', 404);
  }
  return $rows[0];
}

function formatAddress(array $t): string {
  $line2 = trim(implode(' ', array_filter([
    trim(implode(', ', array_filter([$t['city'] ?? null, $t['state'] ?? null]))),
    $t['postal_code'] ?? null,
  ])));
  return trim(implode(', ', array_filter([$t['address1'] ?? null, $t['address2'] ?? null, $line2 ?: null])));
}

function reminderFor(int $taskId, DateTimeZone $tz): ?array {
  $rows = q('SELECT * FROM tt_reminders WHERE task_id = ? ORDER BY id DESC LIMIT 1', 'i', [$taskId]);
  if (!$rows) {
    return null;
  }
  $r = $rows[0];
  return [
    'OffsetMinutes' => $r['offset_minutes'] !== null ? (int)$r['offset_minutes'] : null,
    'At' => utcToLocal($r['reminder_at'], $tz),
    'SnoozedUntil' => utcToLocal($r['snoozed_until'], $tz),
    'Dismissed' => (int)$r['is_dismissed'] === 1,
  ];
}

function repeatFor(int $taskId): ?array {
  $rows = q('SELECT * FROM tt_repeat_rules WHERE task_id = ? AND is_active = 1', 'i', [$taskId]);
  if (!$rows) {
    return null;
  }
  $r = $rows[0];
  return [
    'Frequency' => $r['frequency'],
    'Interval' => (int)$r['interval_value'],
    'DaysOfWeek' => $r['days_of_week'] ? array_map('intval', explode(',', $r['days_of_week'])) : [],
    'DayOfMonth' => $r['day_of_month'] !== null ? (int)$r['day_of_month'] : null,
    'EndDate' => $r['end_date'],
    'MaxOccurrences' => $r['max_occurrences'] !== null ? (int)$r['max_occurrences'] : null,
  ];
}

function taskJson(array $t, DateTimeZone $tz, bool $detail): array {
  $id = (int)$t['id'];
  $out = [
    'Id' => $id,
    'Title' => $t['title'],
    'Notes' => (string)($t['notes'] ?? ''),
    'Date' => $t['task_date'],
    'StartTime' => hm($t['start_time']),
    'EndTime' => hm($t['end_time']),
    'LeaveByTime' => hm($t['leave_by_time']),
    'ContactName' => (string)($t['contact_name'] ?? ''),
    'PhoneNumber' => (string)($t['phone_number'] ?? ''),
    'Address1' => (string)($t['address1'] ?? ''),
    'Address2' => (string)($t['address2'] ?? ''),
    'City' => (string)($t['city'] ?? ''),
    'State' => (string)($t['state'] ?? ''),
    'PostalCode' => (string)($t['postal_code'] ?? ''),
    'Address' => formatAddress($t),
    'Pinned' => (int)$t['is_pinned'] === 1,
    'Completed' => (int)$t['is_completed'] === 1,
    'CompletedAt' => utcToLocal($t['completed_at'], $tz),
    'CalendarAdded' => (int)$t['calendar_added'] === 1,
    'SeriesId' => $t['series_id'] !== null ? (int)$t['series_id'] : null,
    'Reminder' => reminderFor($id, $tz),
    'Repeat' => repeatFor($id),
  ];
  $counts = q('SELECT COUNT(*) AS n, COALESCE(SUM(is_completed), 0) AS done FROM tt_checklist_items WHERE task_id = ?', 'i', [$id])[0];
  $out['ChecklistTotal'] = (int)$counts['n'];
  $out['ChecklistDone'] = (int)$counts['done'];
  $out['AttachmentCount'] = (int)q('SELECT COUNT(*) AS n FROM tt_attachments WHERE task_id = ?', 'i', [$id])[0]['n'];
  if ($detail) {
    $out['Checklist'] = array_map(fn($c) => [
      'Id' => (int)$c['id'],
      'Description' => $c['description'],
      'Done' => (int)$c['is_completed'] === 1,
    ], q('SELECT * FROM tt_checklist_items WHERE task_id = ? ORDER BY sort_order, id', 'i', [$id]));
    $out['Attachments'] = array_map(fn($a) => [
      'Id' => (int)$a['id'],
      'FileName' => $a['file_name'],
      'FileType' => (string)($a['file_type'] ?? ''),
      'FileSize' => (int)$a['file_size'],
    ], q('SELECT * FROM tt_attachments WHERE task_id = ? ORDER BY id', 'i', [$id]));
  }
  return $out;
}

function listTasks(int $userId, DateTimeZone $tz, bool $completed): array {
  $rows = $completed
    ? q('SELECT * FROM tt_tasks WHERE user_id = ? AND is_completed = 1 ORDER BY completed_at DESC, id DESC LIMIT 300', 'i', [$userId])
    : q('SELECT * FROM tt_tasks WHERE user_id = ? AND is_completed = 0
         ORDER BY task_date IS NULL, task_date, is_pinned DESC, start_time IS NULL, start_time, id', 'i', [$userId]);
  return array_map(fn($t) => taskJson($t, $tz, false), $rows);
}

// Writes the task's single reminder: offset minutes before its start (or
// 9:00 AM on an all-day task), or a custom local date/time. NULL clears it.
function writeReminder(int $taskId, ?array $reminder, DateTimeZone $tz): void {
  exec1('DELETE FROM tt_reminders WHERE task_id = ?', 'i', [$taskId]);
  if (!$reminder) {
    return;
  }
  $task = q('SELECT task_date, start_time FROM tt_tasks WHERE id = ?', 'i', [$taskId])[0];
  $offset = $reminder['offsetMinutes'] ?? null;
  $custom = trim((string)($reminder['customAt'] ?? ''));
  if ($offset !== null && $offset !== '') {
    $offset = (int)$offset;
    if ($offset < 0 || $offset > 60 * 24 * 60) {
      fail('Reminder offset is out of range');
    }
    if (!$task['task_date']) {
      fail('A "before the task" reminder needs a date -- use a custom reminder time for an undated task');
    }
    $base = $task['task_date'] . ' ' . ($task['start_time'] ? substr($task['start_time'], 0, 5) : '09:00');
    $at = (new DateTime($base, $tz))->modify("-{$offset} minutes")->setTimezone(utc())->format('Y-m-d H:i:s');
    exec1('INSERT INTO tt_reminders (task_id, reminder_at, offset_minutes) VALUES (?, ?, ?)', 'isi', [$taskId, $at, $offset]);
    return;
  }
  if ($custom !== '') {
    $custom = str_replace('T', ' ', $custom);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $custom) || !validDate(substr($custom, 0, 10))) {
      fail('Custom reminder must be a date and time');
    }
    exec1('INSERT INTO tt_reminders (task_id, reminder_at, offset_minutes) VALUES (?, ?, NULL)', 'is', [$taskId, localToUtc($custom, $tz)]);
  }
}

const FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Yearly'];

function writeRepeat(int $taskId, ?array $repeat, ?string $taskDate, ?string $anchor = null): void {
  if (!$repeat || ($repeat['frequency'] ?? 'None') === 'None') {
    exec1('DELETE FROM tt_repeat_rules WHERE task_id = ?', 'i', [$taskId]);
    return;
  }
  $freq = (string)$repeat['frequency'];
  if (!in_array($freq, FREQUENCIES, true)) {
    fail('Unknown repeat frequency');
  }
  if (!$taskDate) {
    fail('A repeating task needs a date');
  }
  $interval = max(1, min(366, (int)($repeat['interval'] ?? 1)));
  $days = array_values(array_unique(array_filter(array_map('intval', (array)($repeat['daysOfWeek'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
  sort($days);
  $daysVal = ($freq === 'Weekly' && $days) ? implode(',', $days) : null;
  $dom = isset($repeat['dayOfMonth']) && $repeat['dayOfMonth'] !== '' && $repeat['dayOfMonth'] !== null
    ? max(1, min(31, (int)$repeat['dayOfMonth'])) : null;
  $domVal = $freq === 'Monthly' ? $dom : null;
  $end = trim((string)($repeat['endDate'] ?? ''));
  $endVal = null;
  if ($end !== '') {
    if (!validDate($end)) {
      fail('Repeat end date is not a valid date');
    }
    $endVal = $end;
  }
  $max = isset($repeat['maxOccurrences']) && $repeat['maxOccurrences'] !== '' && $repeat['maxOccurrences'] !== null
    ? max(1, (int)$repeat['maxOccurrences']) : null;
  $existing = q('SELECT anchor_date FROM tt_repeat_rules WHERE task_id = ?', 'i', [$taskId]);
  $anchorVal = $anchor ?? ($existing ? $existing[0]['anchor_date'] : $taskDate);
  exec1(
    'INSERT INTO tt_repeat_rules (task_id, frequency, interval_value, days_of_week, day_of_month, end_date, max_occurrences, anchor_date, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE frequency = VALUES(frequency), interval_value = VALUES(interval_value),
       days_of_week = VALUES(days_of_week), day_of_month = VALUES(day_of_month), end_date = VALUES(end_date),
       max_occurrences = VALUES(max_occurrences), is_active = 1',
    'isisisis', [$taskId, $freq, $interval, $daysVal, $domVal, $endVal, $max, $anchorVal]
  );
}

// Replaces the checklist with the given items, keeping existing rows (and
// their checked state) when their id comes back.
function writeChecklist(int $taskId, array $items): void {
  $existing = [];
  foreach (q('SELECT id FROM tt_checklist_items WHERE task_id = ?', 'i', [$taskId]) as $r) {
    $existing[(int)$r['id']] = true;
  }
  $keep = [];
  $order = 0;
  foreach ($items as $item) {
    $desc = strOrNull($item['description'] ?? '', 300);
    if ($desc === null) {
      continue;
    }
    $order += 10;
    $done = !empty($item['done']) ? 1 : 0;
    $id = (int)($item['id'] ?? 0);
    if ($id && isset($existing[$id])) {
      exec1(
        'UPDATE tt_checklist_items SET description = ?, sort_order = ?, is_completed = ?,
           completed_at = IF(? = 1, COALESCE(completed_at, UTC_TIMESTAMP()), NULL) WHERE id = ?',
        'siiii', [$desc, $order, $done, $done, $id]
      );
      $keep[] = $id;
    } else {
      $keep[] = exec1(
        'INSERT INTO tt_checklist_items (task_id, description, sort_order, is_completed, completed_at)
         VALUES (?, ?, ?, ?, IF(? = 1, UTC_TIMESTAMP(), NULL))',
        'isiii', [$taskId, $desc, $order, $done, $done]
      );
    }
  }
  if ($keep) {
    $in = implode(',', array_map('intval', $keep));
    exec1("DELETE FROM tt_checklist_items WHERE task_id = ? AND id NOT IN ($in)", 'i', [$taskId]);
  } else {
    exec1('DELETE FROM tt_checklist_items WHERE task_id = ?', 'i', [$taskId]);
  }
}

function saveTask(int $userId, array $b, DateTimeZone $tz): int {
  $id = (int)($b['id'] ?? 0);
  $title = strOrNull($b['title'] ?? '', 200);
  if ($title === null) {
    fail('Give the task a title');
  }
  $date = trim((string)($b['date'] ?? ''));
  if ($date !== '' && !validDate($date)) {
    fail('Date is not a valid date');
  }
  $dateVal = $date === '' ? null : $date;
  $fields = [
    $title,
    strOrNull($b['notes'] ?? '', 10000),
    $dateVal,
    cleanTime($b['startTime'] ?? null),
    cleanTime($b['endTime'] ?? null),
    cleanTime($b['leaveByTime'] ?? null),
    strOrNull($b['contactName'] ?? '', 150),
    strOrNull($b['phoneNumber'] ?? '', 40),
    strOrNull($b['address1'] ?? '', 200),
    strOrNull($b['address2'] ?? '', 200),
    strOrNull($b['city'] ?? '', 100),
    strOrNull($b['state'] ?? '', 50),
    strOrNull($b['postalCode'] ?? '', 20),
    !empty($b['pinned']) ? 1 : 0,
  ];

  $conn = db();
  $conn->begin_transaction();
  if ($id > 0) {
    ownTask($userId, $id);
    exec1(
      'UPDATE tt_tasks SET title = ?, notes = ?, task_date = ?, start_time = ?, end_time = ?, leave_by_time = ?,
         contact_name = ?, phone_number = ?, address1 = ?, address2 = ?, city = ?, state = ?, postal_code = ?,
         is_pinned = ? WHERE id = ? AND user_id = ?',
      'sssssssssssssiii', array_merge($fields, [$id, $userId])
    );
  } else {
    $id = exec1(
      'INSERT INTO tt_tasks (title, notes, task_date, start_time, end_time, leave_by_time, contact_name, phone_number,
         address1, address2, city, state, postal_code, is_pinned, user_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      'sssssssssssssii', array_merge($fields, [$userId])
    );
  }
  if (array_key_exists('checklist', $b)) {
    writeChecklist($id, (array)$b['checklist']);
  }
  if (array_key_exists('reminder', $b)) {
    writeReminder($id, $b['reminder'] ? (array)$b['reminder'] : null, $tz);
  }
  if (array_key_exists('repeat', $b)) {
    writeRepeat($id, $b['repeat'] ? (array)$b['repeat'] : null, $dateVal);
  }
  $conn->commit();
  return $id;
}

// ---- Repeat: next occurrence ----

function mondayOf(string $date): string {
  $n = (int)(new DateTime($date, utc()))->format('N');
  return addDays($date, -($n - 1));
}

function clampDay(int $y, int $m, int $d): string {
  $last = (int)(new DateTime(sprintf('%04d-%02d-01', $y, $m), utc()))->format('t');
  return sprintf('%04d-%02d-%02d', $y, $m, min($d, $last));
}

function nextOccurrenceDate(array $rule, string $current): ?string {
  $interval = max(1, (int)$rule['interval_value']);
  $anchor = $rule['anchor_date'];
  switch ($rule['frequency']) {
    case 'Daily':
      $next = addDays($current, $interval);
      break;
    case 'Weekly':
      if (!$rule['days_of_week']) {
        $next = addDays($current, 7 * $interval);
        break;
      }
      $days = array_map('intval', explode(',', $rule['days_of_week']));
      $next = null;
      $anchorMonday = mondayOf($anchor);
      for ($i = 1; $i <= 7 * $interval + 7; $i++) {
        $d = addDays($current, $i);
        $weekIndex = intdiv(daysBetween($anchorMonday, mondayOf($d)), 7);
        if (in_array((int)(new DateTime($d, utc()))->format('N'), $days, true) && $weekIndex % $interval === 0) {
          $next = $d;
          break;
        }
      }
      break;
    case 'Monthly':
      [$y, $m] = array_map('intval', explode('-', $current));
      $day = $rule['day_of_month'] !== null ? (int)$rule['day_of_month'] : (int)substr($anchor, 8, 2);
      $m += $interval;
      $y += intdiv($m - 1, 12);
      $m = ($m - 1) % 12 + 1;
      $next = clampDay($y, $m, $day);
      break;
    case 'Yearly':
      $y = (int)substr($current, 0, 4) + $interval;
      $next = clampDay($y, (int)substr($anchor, 5, 2), (int)substr($anchor, 8, 2));
      break;
    default:
      return null;
  }
  if ($next === null || ($rule['end_date'] && $next > $rule['end_date'])) {
    return null;
  }
  return $next;
}

// ---- Attachments (files) ----

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'pdf', 'txt', 'rtf',
                            'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx'];

function uploadDir(): string {
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
  }
  // Belt and braces: never serve these directly, only through the API.
  if (!file_exists($dir . '/.htaccess')) {
    file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
  }
  return $dir;
}

function newStoredName(): string {
  return bin2hex(random_bytes(16));
}

// Copies a stored file under a fresh name; returns the new name or null if
// the source is gone.
function copyStoredFile(string $storedName): ?string {
  $dir = uploadDir();
  $src = $dir . '/' . basename($storedName);
  if (!is_file($src)) {
    return null;
  }
  $new = newStoredName();
  return copy($src, $dir . '/' . $new) ? $new : null;
}

function deleteStoredFile(string $storedName): void {
  $path = uploadDir() . '/' . basename($storedName);
  if (is_file($path)) {
    unlink($path);
  }
}

function deleteTaskFiles(int $taskId): void {
  foreach (q('SELECT stored_name FROM tt_attachments WHERE task_id = ?', 'i', [$taskId]) as $a) {
    deleteStoredFile($a['stored_name']);
  }
}

function uploadAttachment(int $userId, int $taskId): array {
  ownTask($userId, $taskId);
  $f = $_FILES['file'] ?? null;
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    fail($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
      ? 'That file is larger than the server allows'
      : 'No file was received');
  }
  if ($f['size'] > MAX_UPLOAD_BYTES) {
    fail('Files can be up to 10 MB');
  }
  $name = mb_substr(basename((string)$f['name']), 0, 255);
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
    fail('That kind of file isn\'t supported -- use a photo, PDF, or document');
  }
  $stored = newStoredName();
  if (!move_uploaded_file($f['tmp_name'], uploadDir() . '/' . $stored)) {
    fail('Couldn\'t save the file', 500);
  }
  $type = mb_substr((string)($f['type'] ?? ''), 0, 100);
  $size = (int)$f['size'];
  $id = exec1('INSERT INTO tt_attachments (task_id, file_name, file_type, file_size, stored_name) VALUES (?, ?, ?, ?, ?)',
              'issis', [$taskId, $name, $type, $size, $stored]);
  return ['Id' => $id, 'FileName' => $name, 'FileType' => $type, 'FileSize' => $size];
}

function copyAttachmentsTo(int $fromTaskId, int $toTaskId): void {
  foreach (q('SELECT * FROM tt_attachments WHERE task_id = ? ORDER BY id', 'i', [$fromTaskId]) as $a) {
    $copy = copyStoredFile($a['stored_name']);
    if ($copy === null) {
      continue;
    }
    $size = (int)$a['file_size'];
    exec1('INSERT INTO tt_attachments (task_id, file_name, file_type, file_size, stored_name) VALUES (?, ?, ?, ?, ?)',
          'issis', [$toTaskId, $a['file_name'], $a['file_type'], $size, $copy]);
  }
}

// ---- Complete / repeat ----

const TASK_COPY_COLUMNS = 'title, notes, start_time, end_time, leave_by_time, contact_name, phone_number,
  address1, address2, city, state, postal_code, is_pinned';

function completeTask(int $userId, int $taskId, DateTimeZone $tz): ?int {
  $task = ownTask($userId, $taskId);
  if ((int)$task['is_completed'] === 1) {
    return null;
  }
  $conn = db();
  $conn->begin_transaction();
  exec1('UPDATE tt_tasks SET is_completed = 1, completed_at = UTC_TIMESTAMP() WHERE id = ?', 'i', [$taskId]);
  exec1('UPDATE tt_reminders SET is_dismissed = 1 WHERE task_id = ?', 'i', [$taskId]);

  $nextId = null;
  $rules = q('SELECT * FROM tt_repeat_rules WHERE task_id = ? AND is_active = 1', 'i', [$taskId]);
  if ($rules && $task['task_date']) {
    $rule = $rules[0];
    $seriesId = $task['series_id'] !== null ? (int)$task['series_id'] : $taskId;
    if ($task['series_id'] === null) {
      exec1('UPDATE tt_tasks SET series_id = ? WHERE id = ?', 'ii', [$seriesId, $taskId]);
    }
    $count = (int)q('SELECT COUNT(*) AS n FROM tt_tasks WHERE series_id = ?', 'i', [$seriesId])[0]['n'];
    $nextDate = nextOccurrenceDate($rule, $task['task_date']);
    $underMax = $rule['max_occurrences'] === null || $count < (int)$rule['max_occurrences'];
    if ($nextDate !== null && $underMax) {
      $nextId = exec1(
        'INSERT INTO tt_tasks (user_id, parent_task_id, series_id, task_date, ' . TASK_COPY_COLUMNS . ')
         SELECT user_id, id, ?, ?, ' . TASK_COPY_COLUMNS . ' FROM tt_tasks WHERE id = ?',
        'isi', [$seriesId, $nextDate, $taskId]
      );
      // Checklist carries forward as an unchecked template.
      exec1('INSERT INTO tt_checklist_items (task_id, description, sort_order)
             SELECT ?, description, sort_order FROM tt_checklist_items WHERE task_id = ? ORDER BY sort_order, id',
            'ii', [$nextId, $taskId]);
      // Reminder: same offset before the new date, or a custom time shifted
      // by the same number of days the task moved.
      $rem = q('SELECT * FROM tt_reminders WHERE task_id = ? ORDER BY id DESC LIMIT 1', 'i', [$taskId]);
      if ($rem) {
        if ($rem[0]['offset_minutes'] !== null) {
          writeReminder($nextId, ['offsetMinutes' => (int)$rem[0]['offset_minutes']], $tz);
        } else {
          $shift = daysBetween($task['task_date'], $nextDate);
          $local = (new DateTime($rem[0]['reminder_at'], utc()))->setTimezone($tz)->modify("+{$shift} days")->format('Y-m-d H:i');
          writeReminder($nextId, ['customAt' => $local], $tz);
        }
      }
      writeRepeat($nextId, [
        'frequency' => $rule['frequency'],
        'interval' => (int)$rule['interval_value'],
        'daysOfWeek' => $rule['days_of_week'] ? explode(',', $rule['days_of_week']) : [],
        'dayOfMonth' => $rule['day_of_month'],
        'endDate' => $rule['end_date'],
        'maxOccurrences' => $rule['max_occurrences'],
      ], $nextDate, $rule['anchor_date']);
      copyAttachmentsTo($taskId, $nextId);
    }
  }
  $conn->commit();
  return $nextId;
}

// ---- Reminders due now ----

function dueReminders(int $userId, DateTimeZone $tz): array {
  $rows = q(
    'SELECT t.id, t.title, t.task_date, t.start_time, r.reminder_at, r.snoozed_until
     FROM tt_reminders r JOIN tt_tasks t ON t.id = r.task_id
     WHERE t.user_id = ? AND t.is_completed = 0 AND r.is_dismissed = 0
       AND COALESCE(r.snoozed_until, r.reminder_at) <= UTC_TIMESTAMP()
     ORDER BY COALESCE(r.snoozed_until, r.reminder_at)',
    'i', [$userId]
  );
  return array_map(fn($r) => [
    'TaskId' => (int)$r['id'],
    'Title' => $r['title'],
    'Date' => $r['task_date'],
    'StartTime' => hm($r['start_time']),
    'DueAt' => utcToLocal($r['snoozed_until'] ?? $r['reminder_at'], $tz),
  ], $rows);
}

function snoozeReminder(int $userId, int $taskId, string $how, DateTimeZone $tz, string $customAt): void {
  ownTask($userId, $taskId);
  if ($how === 'tomorrow') {
    $until = localToUtc((new DateTime('tomorrow', $tz))->format('Y-m-d') . ' 09:00', $tz);
  } elseif ($how === 'custom') {
    $customAt = str_replace('T', ' ', trim($customAt));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $customAt)) {
      fail('Pick a date and time to snooze until');
    }
    $until = localToUtc($customAt, $tz);
  } else {
    $minutes = (int)$how;
    if ($minutes <= 0 || $minutes > 60 * 24 * 30) {
      fail('Unknown snooze length');
    }
    $until = gmdate('Y-m-d H:i:s', time() + $minutes * 60);
  }
  exec1('UPDATE tt_reminders SET snoozed_until = ?, is_dismissed = 0 WHERE task_id = ?', 'si', [$until, $taskId]);
}

// ---- Transfer codes ----

const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L
const CODE_LIFETIME_SECONDS = 300;

// Expired, unredeemed packages still hold copies of attachment files --
// remove those copies (and the packages) so they don't pile up.
function cleanupExpiredTransfers(): void {
  $rows = q(
    'SELECT p.id, p.payload FROM tt_transfer_packages p JOIN tt_transfer_codes c ON c.id = p.transfer_code_id
     WHERE c.is_redeemed = 0 AND c.expires_at < UTC_TIMESTAMP()'
  );
  foreach ($rows as $r) {
    $payload = json_decode($r['payload'], true) ?: [];
    foreach ($payload['attachments'] ?? [] as $a) {
      deleteStoredFile((string)$a['stored_name']);
    }
    exec1('DELETE FROM tt_transfer_packages WHERE id = ?', 'i', [(int)$r['id']]);
  }
}

function createTransfer(int $userId, int $taskId, DateTimeZone $tz): array {
  $task = ownTask($userId, $taskId);
  cleanupExpiredTransfers();

  $code = null;
  for ($attempt = 0; $attempt < 50 && $code === null; $attempt++) {
    $candidate = '';
    for ($i = 0; $i < 4; $i++) {
      $candidate .= CODE_ALPHABET[random_int(0, strlen(CODE_ALPHABET) - 1)];
    }
    $clash = q('SELECT 1 FROM tt_transfer_codes WHERE transfer_code = ? AND is_redeemed = 0 AND expires_at > UTC_TIMESTAMP()',
               's', [$candidate]);
    if (!$clash) {
      $code = $candidate;
    }
  }
  if ($code === null) {
    fail('Couldn\'t make a code right now -- try again', 503);
  }

  // The snapshot: everything the recipient should get, none of the sender's
  // own state (owner, completed, calendar-added, audit fields).
  $copyCols = ['title', 'notes', 'task_date', 'start_time', 'end_time', 'leave_by_time', 'contact_name',
               'phone_number', 'address1', 'address2', 'city', 'state', 'postal_code', 'is_pinned'];
  $payload = ['task' => array_intersect_key($task, array_flip($copyCols))];
  $payload['checklist'] = array_column(
    q('SELECT description FROM tt_checklist_items WHERE task_id = ? ORDER BY sort_order, id', 'i', [$taskId]), 'description');
  $rem = q('SELECT offset_minutes, reminder_at FROM tt_reminders WHERE task_id = ? ORDER BY id DESC LIMIT 1', 'i', [$taskId]);
  $payload['reminder'] = $rem
    ? ($rem[0]['offset_minutes'] !== null
        ? ['offsetMinutes' => (int)$rem[0]['offset_minutes']]
        : ['customAt' => utcToLocal($rem[0]['reminder_at'], $tz)])
    : null;
  $rule = q('SELECT * FROM tt_repeat_rules WHERE task_id = ? AND is_active = 1', 'i', [$taskId]);
  $payload['repeat'] = $rule ? [
    'frequency' => $rule[0]['frequency'],
    'interval' => (int)$rule[0]['interval_value'],
    'daysOfWeek' => $rule[0]['days_of_week'] ? explode(',', $rule[0]['days_of_week']) : [],
    'dayOfMonth' => $rule[0]['day_of_month'],
    'endDate' => $rule[0]['end_date'],
    'maxOccurrences' => $rule[0]['max_occurrences'],
  ] : null;
  $payload['attachments'] = [];
  foreach (q('SELECT * FROM tt_attachments WHERE task_id = ? ORDER BY id', 'i', [$taskId]) as $a) {
    $copy = copyStoredFile($a['stored_name']);
    if ($copy !== null) {
      $payload['attachments'][] = ['file_name' => $a['file_name'], 'file_type' => $a['file_type'],
                                   'file_size' => (int)$a['file_size'], 'stored_name' => $copy];
    }
  }

  $now = nowUtc();
  $expires = gmdate('Y-m-d H:i:s', time() + CODE_LIFETIME_SECONDS);
  $conn = db();
  $conn->begin_transaction();
  $codeId = exec1(
    'INSERT INTO tt_transfer_codes (source_task_id, source_user_id, transfer_code, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
    'iisss', [$taskId, $userId, $code, $now, $expires]
  );
  $json = json_encode($payload);
  exec1('INSERT INTO tt_transfer_packages (transfer_code_id, payload, created_at, expires_at) VALUES (?, ?, ?, ?)',
        'isss', [$codeId, $json, $now, $expires]);
  $conn->commit();
  return ['Code' => $code, 'ExpiresInSeconds' => CODE_LIFETIME_SECONDS];
}

function redeemTransfer(int $userId, string $code, DateTimeZone $tz): int {
  $code = strtoupper(preg_replace('/\s+/', '', $code));
  $invalid = 'That transfer code is invalid or has expired.';
  if (!preg_match('/^[A-Z0-9]{4}$/', $code)) {
    fail($invalid);
  }
  $conn = db();
  $conn->begin_transaction();
  $rows = q(
    'SELECT c.id, p.payload FROM tt_transfer_codes c JOIN tt_transfer_packages p ON p.transfer_code_id = c.id
     WHERE c.transfer_code = ? AND c.is_redeemed = 0 AND c.expires_at > UTC_TIMESTAMP()
     ORDER BY c.id DESC LIMIT 1 FOR UPDATE',
    's', [$code]
  );
  if (!$rows) {
    $conn->rollback();
    sleep(1); // makes guessing 4-character codes impractically slow
    fail($invalid, 404);
  }
  $codeId = (int)$rows[0]['id'];
  $payload = json_decode($rows[0]['payload'], true) ?: [];
  $t = $payload['task'] ?? [];

  $cols = ['title', 'notes', 'task_date', 'start_time', 'end_time', 'leave_by_time', 'contact_name',
           'phone_number', 'address1', 'address2', 'city', 'state', 'postal_code'];
  $values = array_map(fn($c) => $t[$c] ?? null, $cols);
  $pinned = !empty($t['is_pinned']) ? 1 : 0;
  $newId = exec1(
    'INSERT INTO tt_tasks (user_id, ' . implode(', ', $cols) . ', is_pinned) VALUES (?' . str_repeat(', ?', count($cols)) . ', ?)',
    'i' . str_repeat('s', count($cols)) . 'i', array_merge([$userId], $values, [$pinned])
  );
  $order = 0;
  foreach ($payload['checklist'] ?? [] as $desc) {
    $order += 10;
    exec1('INSERT INTO tt_checklist_items (task_id, description, sort_order) VALUES (?, ?, ?)', 'isi', [$newId, (string)$desc, $order]);
  }
  if (!empty($payload['reminder']) && !(isset($payload['reminder']['offsetMinutes']) && empty($t['task_date']))) {
    writeReminder($newId, $payload['reminder'], $tz);
  }
  if (!empty($payload['repeat']) && !empty($t['task_date'])) {
    writeRepeat($newId, $payload['repeat'], $t['task_date']);
  }
  foreach ($payload['attachments'] ?? [] as $a) {
    $size = (int)$a['file_size'];
    exec1('INSERT INTO tt_attachments (task_id, file_name, file_type, file_size, stored_name) VALUES (?, ?, ?, ?, ?)',
          'issis', [$newId, $a['file_name'], $a['file_type'], $size, $a['stored_name']]);
  }
  exec1('UPDATE tt_transfer_codes SET is_redeemed = 1, redeemed_at = UTC_TIMESTAMP(), redeemed_by_user_id = ? WHERE id = ?',
        'ii', [$userId, $codeId]);
  // The files now belong to the new task; the package is no longer needed.
  exec1('DELETE FROM tt_transfer_packages WHERE transfer_code_id = ?', 'i', [$codeId]);
  $conn->commit();
  return $newId;
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
$body = ($method === 'POST' && !$isMultipart) ? jsonBody() : $_POST;
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');
$tz = requestTz((string)($method === 'GET' ? ($_GET['tz'] ?? '') : ($body['tz'] ?? '')));

switch ($action) {

  case 'tasks': {
    $user = requireMember();
    respond(['ok' => true, 'Tasks' => listTasks((int)$user['id'], $tz, false),
             'Reminders' => dueReminders((int)$user['id'], $tz)]);
  }

  case 'completedTasks': {
    $user = requireMember();
    respond(['ok' => true, 'Tasks' => listTasks((int)$user['id'], $tz, true)]);
  }

  case 'task': {
    $user = requireMember();
    $task = ownTask((int)$user['id'], (int)($_GET['id'] ?? 0));
    respond(['ok' => true, 'Task' => taskJson($task, $tz, true)]);
  }

  case 'saveTask': {
    $user = requireMember();
    $id = saveTask((int)$user['id'], $body, $tz);
    respond(['ok' => true, 'Id' => $id]);
  }

  case 'setPinned': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    ownTask((int)$user['id'], $id);
    $pinned = !empty($body['pinned']) ? 1 : 0;
    exec1('UPDATE tt_tasks SET is_pinned = ? WHERE id = ?', 'ii', [$pinned, $id]);
    respond(['ok' => true]);
  }

  case 'toggleChecklistItem': {
    $user = requireMember();
    $itemId = (int)($body['id'] ?? 0);
    $rows = q('SELECT c.task_id FROM tt_checklist_items c JOIN tt_tasks t ON t.id = c.task_id WHERE c.id = ? AND t.user_id = ?',
              'ii', [$itemId, (int)$user['id']]);
    if (!$rows) {
      fail('Checklist item not found', 404);
    }
    $done = !empty($body['done']) ? 1 : 0;
    exec1('UPDATE tt_checklist_items SET is_completed = ?, completed_at = IF(? = 1, UTC_TIMESTAMP(), NULL) WHERE id = ?',
          'iii', [$done, $done, $itemId]);
    respond(['ok' => true]);
  }

  case 'completeTask': {
    $user = requireMember();
    $nextId = completeTask((int)$user['id'], (int)($body['id'] ?? 0), $tz);
    respond(['ok' => true, 'NextTaskId' => $nextId]);
  }

  case 'reopenTask': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    ownTask((int)$user['id'], $id);
    exec1('UPDATE tt_tasks SET is_completed = 0, completed_at = NULL WHERE id = ?', 'i', [$id]);
    respond(['ok' => true]);
  }

  case 'deleteTask': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    ownTask((int)$user['id'], $id);
    deleteTaskFiles($id);
    exec1('DELETE FROM tt_tasks WHERE id = ? AND user_id = ?', 'ii', [$id, (int)$user['id']]);
    respond(['ok' => true]);
  }

  case 'setCalendarAdded': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    ownTask((int)$user['id'], $id);
    exec1('UPDATE tt_tasks SET calendar_added = 1 WHERE id = ?', 'i', [$id]);
    respond(['ok' => true]);
  }

  // multipart/form-data: action, taskId, file
  case 'uploadAttachment': {
    $user = requireMember();
    respond(['ok' => true, 'Attachment' => uploadAttachment((int)$user['id'], (int)($body['taskId'] ?? 0))]);
  }

  // Streams the file to its owner. The page fetches it with the Bearer
  // header, then opens/saves the blob.
  case 'attachment': {
    $user = requireMember();
    $rows = q('SELECT a.* FROM tt_attachments a JOIN tt_tasks t ON t.id = a.task_id WHERE a.id = ? AND t.user_id = ?',
              'ii', [(int)($_GET['id'] ?? 0), (int)$user['id']]);
    if (!$rows) {
      fail('Attachment not found', 404);
    }
    $a = $rows[0];
    $path = uploadDir() . '/' . basename($a['stored_name']);
    if (!is_file($path)) {
      fail('That file is missing', 404);
    }
    header('Content-Type: ' . ($a['file_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $a['file_name']) . '"');
    readfile($path);
    exit;
  }

  case 'deleteAttachment': {
    $user = requireMember();
    $rows = q('SELECT a.id, a.stored_name FROM tt_attachments a JOIN tt_tasks t ON t.id = a.task_id WHERE a.id = ? AND t.user_id = ?',
              'ii', [(int)($body['id'] ?? 0), (int)$user['id']]);
    if (!$rows) {
      fail('Attachment not found', 404);
    }
    deleteStoredFile($rows[0]['stored_name']);
    exec1('DELETE FROM tt_attachments WHERE id = ?', 'i', [(int)$rows[0]['id']]);
    respond(['ok' => true]);
  }

  case 'snoozeReminder': {
    $user = requireMember();
    snoozeReminder((int)$user['id'], (int)($body['taskId'] ?? 0), (string)($body['snooze'] ?? ''), $tz, (string)($body['customAt'] ?? ''));
    respond(['ok' => true]);
  }

  case 'dismissReminder': {
    $user = requireMember();
    $id = (int)($body['taskId'] ?? 0);
    ownTask((int)$user['id'], $id);
    exec1('UPDATE tt_reminders SET is_dismissed = 1 WHERE task_id = ?', 'i', [$id]);
    respond(['ok' => true]);
  }

  case 'createTransfer': {
    $user = requireMember();
    respond(['ok' => true] + createTransfer((int)$user['id'], (int)($body['taskId'] ?? 0), $tz));
  }

  case 'redeemTransfer': {
    $user = requireMember();
    respond(['ok' => true, 'TaskId' => redeemTransfer((int)$user['id'], (string)($body['code'] ?? ''), $tz)]);
  }

  // -- MyDataWorld login (shared with the other apps) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $rows = q('SELECT id, password_hash, display_name FROM users WHERE username = ?', 's', [$username]);
    $u = $rows[0] ?? null;
    if (!$u || $u['password_hash'] === null || !password_verify($password, $u['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $uid = (int)$u['id'];
    $days = SESSION_LIFETIME_DAYS;
    exec1('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))', 'sii', [$token, $uid, $days]);
    respond(['token' => $token, 'displayName' => $u['display_name']]);
  }

  case 'checkAccess': {
    $user = requireUser();
    if (!hasAppAccess($user)) {
      fail('Not authorized for ' . APP_NAME, 403);
    }
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}

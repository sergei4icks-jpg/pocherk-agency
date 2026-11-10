<?php
/* «Почерк» — закрытая страница заявок (мини-CRM).
   Заявки пишет api/lead.php в журнал. Пароли — 'users' в api/config.php.
   Данные не покидают сервер в РФ; страница закрыта от поиска. */

$secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['lifetime' => 0, 'path' => '/zayavki/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
session_name('pk_crm');
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$cfgFile = $root . '/api/config.php';
$cfg  = require (file_exists($cfgFile) ? $cfgFile : $root . '/api/config.sample.php');
$DIR  = rtrim($cfg['data_dir'] ?? ($root . '/api/data'), '/');
$FILE = $DIR . '/leads.json';
$SITE = rtrim($cfg['site_url'] ?? 'https://pocherkagency.ru', '/');

$STATUSES = [
  'new'    => ['Новая',    '#B3122B'],
  'work'   => ['В работе', '#B07A00'],
  'deal'   => ['Договор',  '#1E7F3C'],
  'reject' => ['Отказ',    '#6B6B6B'],
  'spam'   => ['Спам',     '#A0A0A0'],
];

function pk_read($file) {
  if (!file_exists($file)) return [];
  $fp = @fopen($file, 'r');
  if (!$fp) return [];
  flock($fp, LOCK_SH);
  $d = json_decode((string)stream_get_contents($fp), true);
  flock($fp, LOCK_UN);
  fclose($fp);
  return is_array($d) ? $d : [];
}
/* Изменение под одной блокировкой: читаем, правим, пишем */
function pk_update($file, callable $fn) {
  $fp = @fopen($file, 'c+');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  $d = json_decode((string)stream_get_contents($fp), true);
  $d = $fn(is_array($d) ? $d : []);
  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, json_encode(array_values($d), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return true;
}
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pk_now($cfg) { return (new DateTime('now', new DateTimeZone($cfg['timezone'] ?? 'Europe/Moscow')))->format('Y-m-d H:i'); }

/* --- вход: замедление после неудачных попыток (общий счётчик в журнале) --- */
$users = is_array($cfg['users'] ?? null) ? $cfg['users'] : [];
$err = '';
if (isset($_POST['login'])) {
  $lockFile = $DIR . '/.login-fails';
  $fails = (int)@file_get_contents($lockFile);
  if ($fails > 0) sleep(min(10, $fails));
  $pass = (string)($_POST['password'] ?? '');
  $who = '';
  foreach ($users as $uname => $upass) {
    $upass = (string)$upass;
    if ($upass === '') continue;
    $ok = strncmp($upass, '$2y$', 4) === 0 ? password_verify($pass, $upass) : hash_equals($upass, $pass);
    if ($ok) { $who = (string)$uname; break; }
  }
  if ($who !== '') {
    @file_put_contents($lockFile, '0');
    session_regenerate_id(true);
    $_SESSION['pk_user'] = $who;
    $_SESSION['pk_csrf'] = bin2hex(random_bytes(16));
    header('Location: ./');
    exit;
  }
  @file_put_contents($lockFile, (string)($fails + 1));
  $err = $users ? 'Неверный пароль' : 'Вход не настроен: добавьте пользователя в api/config.php';
}
if (isset($_GET['logout'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: ./');
  exit;
}
$ME = (string)($_SESSION['pk_user'] ?? '');
$authed = $ME !== '' && isset($users[$ME]);
$csrfOk = $authed && hash_equals((string)($_SESSION['pk_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
$back = function () {
  $f = (string)($_POST['filter'] ?? '');
  header('Location: ./' . ($f !== '' ? '?f=' . urlencode($f) : ''));
  exit;
};

/* --- статус и заметка --- */
if ($csrfOk && isset($_POST['save'])) {
  $id = (int)($_POST['id'] ?? 0);
  $st = (string)($_POST['status'] ?? '');
  $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000, 'UTF-8');
  pk_update($FILE, function ($leads) use ($id, $st, $note, $STATUSES, $ME, $cfg) {
    foreach ($leads as &$l) {
      if ((int)($l['id'] ?? 0) !== $id) continue;
      if (isset($STATUSES[$st])) {
        if ($st === 'work' && empty($l['assignee'])) $l['assignee'] = $ME;
        if ($st === 'new') unset($l['assignee']);
        $l['status'] = $st;
      }
      $l['note'] = $note;
      $l['updated'] = pk_now($cfg);
      $l['updated_by'] = $ME;
    }
    unset($l);
    return $leads;
  });
  $back();
}

/* --- удаление по запросу человека или после отзыва согласия --- */
if ($csrfOk && isset($_POST['delete'])) {
  $id = (int)($_POST['id'] ?? 0);
  pk_update($FILE, function ($leads) use ($id) {
    return array_filter($leads, function ($l) use ($id) { return (int)($l['id'] ?? 0) !== $id; });
  });
  $back();
}

/* --- выгрузка CSV для Excel --- */
if ($authed && isset($_GET['export'])) {
  $leads = pk_read($FILE);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="pocherk-zayavki-' . date('Y-m-d') . '.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['№', 'Дата', 'Имя', 'Контакт', 'Детали', 'Форма', 'Страница', 'UTM', 'Статус', 'Ответственный', 'Заметка', 'Согласие дано'], ';');
  foreach (array_reverse($leads) as $l) {
    $det = [];
    foreach (($l['fields'] ?? []) as $k => $v) $det[] = "$k: $v";
    fputcsv($out, [
      $l['id'] ?? '', $l['ts'] ?? '', $l['name'] ?? '', $l['contact'] ?? '', implode('; ', $det),
      ($l['form'] ?? '') === 'quiz' ? 'Квиз' : 'Форма', $l['page'] ?? '', $l['utm'] ?? '',
      $STATUSES[$l['status'] ?? 'new'][0] ?? '', $l['assignee'] ?? '', $l['note'] ?? '', $l['consent_at'] ?? '',
    ], ';');
  }
  fclose($out);
  exit;
}

$leads  = $authed ? array_reverse(pk_read($FILE)) : [];
$filter = isset($_GET['f'], $STATUSES[$_GET['f']]) ? $_GET['f'] : '';
$counts = ['' => count($leads)] + array_fill_keys(array_keys($STATUSES), 0);
foreach ($leads as $l) { $s = $l['status'] ?? 'new'; if (isset($counts[$s])) $counts[$s]++; }
if ($filter !== '') $leads = array_values(array_filter($leads, function ($l) use ($filter) { return ($l['status'] ?? 'new') === $filter; }));

/* Контакт: телефон → звонок, @ник или t.me → Telegram, почта → письмо */
function pk_contact_link($c) {
  $c = trim($c);
  if (preg_match('/^@?([A-Za-z0-9_]{5,32})$/', $c, $m) && !ctype_digit($m[1])) return 'https://t.me/' . $m[1];
  if (preg_match('~t\.me/([A-Za-z0-9_]{5,32})~', $c, $m)) return 'https://t.me/' . $m[1];
  if (filter_var($c, FILTER_VALIDATE_EMAIL)) return 'mailto:' . $c;
  $d = preg_replace('/\D/', '', $c);
  if (strlen($d) >= 10) return 'tel:+' . (strlen($d) === 10 ? '7' . $d : (strlen($d) === 11 && $d[0] === '8' ? '7' . substr($d, 1) : $d));
  return '';
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Заявки — «Почерк»</title>
<link rel="icon" href="../favicon-32.png" sizes="32x32">
<link rel="stylesheet" href="../assets/fonts.css">
<style>
  :root{--red:#B3122B;--ink:#16141A;--muted:#6E6A73;--line:#E7E3E6;--bg:#F6F4F5;--card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font:15px/1.5 Inter,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
  a{color:inherit}
  .wrap{max-width:1080px;margin:0 auto;padding:22px 16px 60px}
  .top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px}
  .logo{display:flex;align-items:center;gap:10px;font:700 15px Montserrat,Inter,sans-serif;text-transform:uppercase;letter-spacing:.04em}
  .logo img{height:34px;background:var(--ink);border-radius:8px;padding:4px 8px}
  .sp{flex:1}
  .me{color:var(--muted);font-size:13px}
  .btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 16px;border-radius:999px;border:0;background:var(--ink);color:#fff;font:600 13px Inter,sans-serif;text-decoration:none;cursor:pointer}
  .btn-red{background:var(--red)}
  .btn-ghost{background:none;color:var(--muted);border:1px solid var(--line)}
  .btn-ghost:hover{color:var(--red);border-color:var(--red)}
  .tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
  .tab{text-decoration:none;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 14px;font-weight:600;font-size:13px}
  .tab.on{background:var(--ink);color:#fff;border-color:var(--ink)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:12px}
  .card:target{border-color:var(--red);box-shadow:0 0 0 3px rgba(179,18,43,.15)}
  .head{display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}
  .id{color:var(--muted);font-weight:700}
  .name{font-weight:700;font-size:16px}
  .contact a{font-weight:700;text-decoration:none;border-bottom:2px solid var(--red)}
  .meta{color:var(--muted);font-size:12.5px}
  .det{margin:10px 0 12px;background:var(--bg);border-radius:10px;padding:10px 12px;font-size:14px}
  .det div+div{margin-top:3px}
  .det b{font-weight:600;color:var(--muted)}
  .foot{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
  .foot form{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;flex:1}
  .badge{display:inline-block;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;color:#fff}
  .who{font-size:12.5px;color:var(--muted)}
  select,textarea,input{border:1px solid var(--line);border-radius:10px;padding:8px 10px;font:inherit;background:#fff;color:inherit}
  textarea{flex:1;min-width:200px;min-height:38px;height:38px;resize:vertical}
  .empty{text-align:center;color:var(--muted);padding:60px 0}
  .login{max-width:360px;margin:14vh auto 0;background:#fff;border:1px solid var(--line);border-radius:18px;padding:28px}
  .login h1{margin:0 0 16px;font:700 20px Montserrat,Inter,sans-serif}
  .login input{width:100%;margin-bottom:12px}
  .err{color:var(--red);font-weight:600;margin-bottom:10px}
  .del{background:none;border:0;color:var(--muted);font:inherit;font-size:12.5px;text-decoration:underline;cursor:pointer;padding:0}
  .del:hover{color:var(--red)}
  @media (max-width:600px){.foot form{flex-direction:column;align-items:stretch}}
</style>
</head>
<body>
<?php if (!$authed): ?>
  <form class="login" method="post">
    <h1>Заявки «Почерка»</h1>
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
    <input type="password" name="password" placeholder="Пароль" autocomplete="current-password" autofocus>
    <button class="btn btn-red" type="submit" name="login" value="1">Войти</button>
  </form>
<?php else: ?>
  <div class="wrap">
    <div class="top">
      <span class="logo"><img src="../img/logo-word.svg" alt="Почерк">Заявки с сайта</span>
      <span class="sp"></span>
      <span class="me">Вы: <b><?= e($ME) ?></b></span>
      <a class="btn btn-ghost" href="?export=csv">Скачать CSV</a>
      <a class="btn" href="?logout=1">Выйти</a>
    </div>

    <div class="tabs">
      <a class="tab<?= $filter === '' ? ' on' : '' ?>" href="./">Все <b><?= $counts[''] ?></b></a>
      <?php foreach ($STATUSES as $k => $v): ?>
        <a class="tab<?= $filter === $k ? ' on' : '' ?>" href="?f=<?= $k ?>"><?= e($v[0]) ?> <b><?= $counts[$k] ?></b></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$leads): ?><div class="empty">Заявок пока нет. Они появятся здесь сразу после отправки формы на сайте.</div><?php endif; ?>

    <?php foreach ($leads as $l): $st = $l['status'] ?? 'new'; $id = (int)($l['id'] ?? 0); $link = pk_contact_link($l['contact'] ?? ''); ?>
      <div class="card" id="lead-<?= $id ?>">
        <div class="head">
          <span class="id">№<?= $id ?></span>
          <span class="name"><?= e($l['name'] ?? '') ?></span>
          <span class="contact"><?php if ($link): ?><a href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($l['contact'] ?? '') ?></a><?php else: ?><?= e($l['contact'] ?? '') ?><?php endif; ?></span>
          <span class="meta">
            <?= e($l['ts'] ?? '') ?> · <?= ($l['form'] ?? '') === 'quiz' ? 'квиз' : 'форма' ?>
            <?php if (!empty($l['page'])): ?> · <a href="<?= e($SITE . $l['page']) ?>" target="_blank" rel="noopener"><?= e($l['page']) ?></a><?php endif; ?>
            <?php if (!empty($l['utm'])): ?> · <?= e($l['utm']) ?><?php endif; ?>
          </span>
        </div>
        <?php if (!empty($l['fields'])): ?>
          <div class="det"><?php foreach ($l['fields'] as $k => $v): ?><div><b><?= e($k) ?>:</b> <?= e($v) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="foot">
          <span class="badge" style="background:<?= $STATUSES[$st][1] ?? '#888' ?>"><?= e($STATUSES[$st][0] ?? $st) ?></span>
          <?php if (!empty($l['assignee'])): ?><span class="who">ведёт: <?= e($l['assignee']) ?></span><?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['pk_csrf'] ?? '') ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <select name="status" aria-label="Статус">
              <?php foreach ($STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $k === $st ? ' selected' : '' ?>><?= e($v[0]) ?></option><?php endforeach; ?>
            </select>
            <textarea name="note" placeholder="Заметка: о чём договорились, когда перезвонить" aria-label="Заметка"><?= e($l['note'] ?? '') ?></textarea>
            <button class="btn" type="submit" name="save" value="1">Сохранить</button>
          </form>
          <form method="post" onsubmit="return confirm('Удалить заявку №<?= $id ?> навсегда? Так делают, когда человек отозвал согласие или попросил удалить данные.')" style="flex:none">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['pk_csrf'] ?? '') ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <button class="del" type="submit" name="delete" value="1">Удалить</button>
          </form>
        </div>
        <div class="meta" style="margin-top:8px">Согласие на обработку данных: <?= e($l['consent_at'] ?? '—') ?><?php if (!empty($l['updated'])): ?> · изменено <?= e($l['updated']) ?>, <?= e($l['updated_by'] ?? '') ?><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</body>
</html>

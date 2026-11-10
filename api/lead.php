<?php
/* «Почерк» — приём заявок с сайта (POST JSON из assets/site.js, функция sendLead).
   Заявка: запись в журнал на хостинге в РФ → письмо на почту → сигнал в Telegram без персональных данных.
   Заявка считается принятой, если сохранилась на сервере. Настройки — api/config.php (в git не кладём). */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$cfgFile = __DIR__ . '/config.php';
$cfg = require (file_exists($cfgFile) ? $cfgFile : __DIR__ . '/config.sample.php');

function pk_out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pk_out(405, ['ok' => false, 'msg' => 'method']);

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 20000) pk_out(413, ['ok' => false, 'msg' => 'too large']);
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

/* Ловушка для ботов: скрытое поле website должно остаться пустым */
if (!empty($in['website'])) pk_out(200, ['ok' => true]);

function pk_field($in, $key, $max) {
  $v = isset($in[$key]) ? trim((string)$in[$key]) : '';
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
  return mb_substr($v, 0, $max, 'UTF-8');
}

/* Поля форм сайта и их подписи в журнале */
$LABELS = [
  'service' => 'Что нужно',
  'have'    => 'Что уже есть',
  'goal'    => 'Цель',
  'niche'   => 'Ниша и город',
  'city'    => 'Город',
  'task'    => 'Задача',
];

$name    = pk_field($in, 'name', 200);
$contact = pk_field($in, 'contact', 200);
$consent = pk_field($in, 'consent', 10);
$page    = pk_field($in, 'page', 300);
$form    = pk_field($in, 'form', 20);
$utm     = pk_field($in, 'utm', 300);

if ($name === '' || $contact === '') pk_out(400, ['ok' => false, 'msg' => 'Заполните имя и контакт']);
/* Без отдельного согласия на обработку данных заявку не принимаем (152-ФЗ, ст. 9) */
if ($consent !== 'да') pk_out(400, ['ok' => false, 'msg' => 'Нужно согласие на обработку персональных данных']);

$fields = [];
foreach ($LABELS as $k => $label) {
  $v = pk_field($in, $k, 1000);
  if ($v !== '') $fields[$label] = $v;
}

/* ===== 1. Журнал заявок на сервере (основное хранилище) ===== */
$dir = rtrim($cfg['data_dir'] ?? (__DIR__ . '/data'), '/');
if (!is_dir($dir)) @mkdir($dir, 0750, true);
if (!file_exists($dir . '/.htaccess')) {
  @file_put_contents($dir . '/.htaccess',
    "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
    "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
}

$now = new DateTime('now', new DateTimeZone($cfg['timezone'] ?? 'Europe/Moscow'));
$saved = false;
$leadId = 0;
$fp = @fopen($dir . '/leads.json', 'c+');
if ($fp && flock($fp, LOCK_EX)) {
  $leads = json_decode((string)stream_get_contents($fp), true);
  if (!is_array($leads)) $leads = [];

  /* Срок хранения из политики: не дольше 3 лет с последнего обращения */
  $limit = (clone $now)->modify('-' . (int)($cfg['keep_years'] ?? 3) . ' years')->format('Y-m-d H:i');
  $leads = array_values(array_filter($leads, function ($l) use ($limit) {
    return ($l['updated'] ?? $l['ts'] ?? '') >= $limit;
  }));

  $id = (int)($cfg['first_id'] ?? 1);
  foreach ($leads as $l) if (isset($l['id']) && $l['id'] >= $id) $id = $l['id'] + 1;
  $consentAt = pk_field($in, 'consent_at', 40);
  $leads[] = [
    'id'         => $id,
    'ts'         => $now->format('Y-m-d H:i'),
    'name'       => $name,
    'contact'    => $contact,
    'fields'     => $fields,
    'form'       => $form === 'quiz' ? 'quiz' : 'lead',
    'page'       => $page,
    'utm'        => $utm,
    'consent'    => 'да',
    'consent_at' => $consentAt !== '' ? $consentAt : $now->format(DateTime::ATOM),
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'status'     => 'new',
  ];
  rewind($fp);
  ftruncate($fp, 0);
  fwrite($fp, json_encode($leads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp);
  @chmod($dir . '/leads.json', 0600);
  flock($fp, LOCK_UN);
  $saved = true;
  $leadId = $id;
}
if ($fp) fclose($fp);

if (!$saved) pk_out(500, ['ok' => false, 'msg' => 'Не удалось сохранить заявку']);

$site = rtrim($cfg['site_url'] ?? 'https://pocherkagency.ru', '/');
$crmUrl = $site . '/zayavki/#lead-' . $leadId;

/* ===== 2. Письмо (почта в РФ) ===== */
$mailed = false;
if (!empty($cfg['email_to'])) {
  $lines = ["Новая заявка №{$leadId} с сайта pocherkagency.ru", '', "Имя: {$name}", "Контакт: {$contact}"];
  foreach ($fields as $label => $v) $lines[] = "{$label}: {$v}";
  if ($page !== '') $lines[] = "Страница: {$site}{$page}";
  if ($utm !== '')  $lines[] = "Источник (UTM): {$utm}";
  $lines[] = '';
  $lines[] = "Все заявки: {$site}/zayavki/";
  $subject = "Заявка №{$leadId} с pocherkagency.ru";
  $from = $cfg['email_from'] ?? ('noreply@' . parse_url($site, PHP_URL_HOST));
  $headers = 'From: =?UTF-8?B?' . base64_encode('Сайт «Почерк»') . "?= <{$from}>\r\n"
           . "Content-Type: text/plain; charset=UTF-8\r\n"
           . "Content-Transfer-Encoding: 8bit";
  $mailed = @mail($cfg['email_to'], '=?UTF-8?B?' . base64_encode($subject) . '?=', implode("\n", $lines), $headers);
}

/* ===== 3. Telegram: только номер и ссылка =====
   Серверы Telegram за пределами РФ, поэтому имя, контакт и текст заявки туда не передаём. */
function pk_tg_send($token, $chatId, $text) {
  $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
  $payload = http_build_query(['chat_id' => $chatId, 'text' => $text]);
  if (function_exists('curl_init')) {
    /* На хостинге Рег.ру IPv4-маршрут до api.telegram.org бывает закрыт, IPv6 проходит (проверено на benbene.ru) */
    foreach ([CURL_IPRESOLVE_V6, CURL_IPRESOLVE_WHATEVER] as $ipMode) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_IPRESOLVE => $ipMode,
      ]);
      $ok = curl_exec($ch) !== false;
      curl_close($ch);
      if ($ok) return true;
    }
    return false;
  }
  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => $payload, 'timeout' => 5,
  ]]);
  return @file_get_contents($url, false, $ctx) !== false;
}

$tgSent = false;
if (!empty($cfg['tg_token']) && !empty($cfg['tg_chat_id'])) {
  $kind = $form === 'quiz' ? 'квиз' : 'форма';
  $tgSent = pk_tg_send($cfg['tg_token'], $cfg['tg_chat_id'],
    "Новая заявка №{$leadId} с сайта ({$kind})\nОткрыть: {$crmUrl}");
}

echo json_encode(['ok' => true, 'id' => $leadId, 'mailed' => (bool)$mailed, 'tg' => $tgSent]);

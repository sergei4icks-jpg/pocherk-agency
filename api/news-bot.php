<?php
/* «Почерк» — Telegram-бот новостей @pochertnews_bot.
   Черновики приходят через api/news-draft.php и ждут решения хозяйки бота. Без её кнопки на сайт ничего не попадает:
   «Опубликовать» — заметка сразу на /novosti/; «Править» — бот присылает текст, она отвечает исправленным;
   «Удалить» — черновик убирается. Команда /list — последние опубликованные с кнопкой «Снять с сайта».
   Хозяйка определяется один раз: /start с кодом claim_code из api/config.php. Остальным бот не отвечает по делу.

   Как сообщения доходят: Telegram не может подключиться к хостингу Рег.ру (webhook: Connection timed out, 10.11.2026),
   поэтому сервер сам забирает их — cron раз в минуту запускает `php api/news-bot.php poll`, и тот слушает ~55 секунд.
   Веб-вход (webhook с секретом) оставлен на случай, если связь появится. */

require __DIR__ . '/news-lib.php';

function say($chat, $text, $extra = []) {
  return news_tg('sendMessage', ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
    'link_preview_options' => ['is_disabled' => true]] + $extra);
}
function site_link($slug) { return news_cfg()['site_url'] . '/novosti/' . $slug . '/'; }

function handle_update(array $up) {
  $cfg = news_cfg();
  $state = news_read();
  $admin = $state['admin'];

  /* ===== Кнопки ===== */
  if (isset($up['callback_query'])) {
    $cq = $up['callback_query'];
    $chat = $cq['message']['chat']['id'] ?? null;
    $msgId = $cq['message']['message_id'] ?? null;
    if ($admin === null || (string)$chat !== (string)$admin) {
      news_tg('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
      return;
    }
    [$act, $id] = array_pad(explode(':', (string)($cq['data'] ?? ''), 2), 2, '');
    $note = '';

    if ($act === 'pub') {
      $slug = news_update(function (&$d) use ($id) {
        if (!isset($d['drafts'][$id])) return null;
        $it = $d['drafts'][$id];
        unset($d['drafts'][$id]);
        if ($d['await_edit'] === $id) $d['await_edit'] = null;
        $it['slug'] = news_slug($it['title'], array_column($d['published'], 'slug'));
        $it['published_at'] = news_now()->format('Y-m-d H:i');
        unset($it['msg_id'], $it['created']);
        $d['published'][] = $it;
        return $it['slug'];
      });
      if ($slug) {
        news_tg('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $msgId, 'reply_markup' => ['inline_keyboard' => []]]);
        say($chat, "✅ Опубликовано: " . news_h(site_link($slug)), ['reply_to_message_id' => $msgId]);
        $note = 'Опубликовано';
      } else $note = 'Этого черновика уже нет';
    }

    elseif ($act === 'del') {
      $ok = news_update(function (&$d) use ($id) {
        if (!isset($d['drafts'][$id])) return false;
        unset($d['drafts'][$id]);
        if ($d['await_edit'] === $id) $d['await_edit'] = null;
        return true;
      });
      if ($ok) news_tg('editMessageText', ['chat_id' => $chat, 'message_id' => $msgId, 'text' => "🗑 Черновик №$id удалён"]);
      $note = $ok ? 'Удалено' : 'Этого черновика уже нет';
    }

    elseif ($act === 'edit') {
      $it = $state['drafts'][$id] ?? null;
      if ($it) {
        news_update(function (&$d) use ($id) { $d['await_edit'] = $id; $d['await_ai'] = null; });
        say($chat, "✏️ Правка черновика №$id. Нажмите на текст ниже, он скопируется. Исправьте и пришлите следующим сообщением. "
          . "Подписи разделов («Заголовок:», «Что делать:» и т.д.) оставьте. Можно прислать только те разделы, которые меняете.");
        news_tg('sendMessage', ['chat_id' => $chat, 'text' => '<code>' . news_h(news_to_edit_text($it)) . '</code>', 'parse_mode' => 'HTML']);
        $note = 'Жду исправленный текст';
      } else $note = 'Этого черновика уже нет';
    }

    /* «Доработать»: просьба своими словами, её выполняет редактор-нейросеть на Mac (новости-робот/revise.py) */
    elseif ($act === 'ai') {
      if (isset($state['drafts'][$id])) {
        news_update(function (&$d) use ($id) { $d['await_ai'] = $id; $d['await_edit'] = null; });
        say($chat, "🤖 Что доработать в черновике №$id? Напишите обычными словами, например: «добавь, какие бренды уже ведут эфиры на Маркете» или «сделай мнение острее». Можно попросить найти факты в интернете.");
        $note = 'Жду просьбу';
      } else $note = 'Этого черновика уже нет';
    }

    elseif ($act === 'unpub') {
      $newId = news_update(function (&$d) use ($id) {
        foreach ($d['published'] as $i => $it) {
          if ($it['slug'] !== $id) continue;
          array_splice($d['published'], $i, 1);
          $nid = (string)(++$d['seq']);
          unset($it['slug'], $it['published_at']);
          $it['created'] = news_now()->format('Y-m-d H:i');
          $d['drafts'][$nid] = $it;
          return $nid;
        }
        return null;
      });
      if ($newId) {
        $it = news_read()['drafts'][$newId];
        say($chat, "Снято с сайта. Заметка снова в черновиках, ниже. Её можно поправить и опубликовать заново или удалить.");
        $mid = news_send_draft($chat, $it, $newId);
        if ($mid) news_update(function (&$d) use ($newId, $mid) { if (isset($d['drafts'][$newId])) $d['drafts'][$newId]['msg_id'] = $mid; });
        $note = 'Снято с сайта';
      } else $note = 'Уже снято';
    }

    news_tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $note]);
    return;
  }

  /* ===== Сообщения ===== */
  $msg = $up['message'] ?? null;
  if (!$msg || ($msg['chat']['type'] ?? '') !== 'private') { return; }
  $chat = $msg['chat']['id'];
  $text = trim((string)($msg['text'] ?? ''));

  /* Первый вход: /start <код> делает человека хозяйкой бота (один раз) */
  if ($admin === null) {
    /* код принимаем и из ссылки (/start КОД), и просто сообщением: если чат уже был открыт, ссылка присылает голый /start */
    if ($cfg['claim_code'] !== '' && preg_match('/^(?:\/start\s+)?(\S+)$/', $text, $m) && hash_equals($cfg['claim_code'], $m[1])) {
      news_update(function (&$d) use ($chat) { if ($d['admin'] === null) $d['admin'] = $chat; });
      say($chat, "Готово, бот ваш. Сюда будут приходить черновики новостей для pocherkagency.ru/novosti/ с кнопками «Опубликовать», «Править» и «Удалить».\n\n/list — опубликованные заметки, их можно снять с сайта.");
      $wait = news_read()['drafts'];
      foreach ($wait as $did => $it) {
        $mid = news_send_draft($chat, $it, $did);
        if ($mid) news_update(function (&$d) use ($did, $mid) { if (isset($d['drafts'][$did])) $d['drafts'][$did]['msg_id'] = $mid; });
      }
    } else {
      say($chat, 'Это служебный бот агентства «Почерк». Если вы его хозяйка, пришлите код подключения одним сообщением.');
    }
    return;
  }
  if ((string)$chat !== (string)$admin) { say($chat, 'Это служебный бот агентства «Почерк».'); return; }

  if ($text === '/start' || $text === '/help') {
    say($chat, "Черновики новостей приходят сюда сами. Под каждым кнопки:\n✅ «Опубликовать» — сразу на сайт\n✏️ «Править сама» — бот пришлёт текст, вы исправите и отправите\n🤖 «Доработать» — напишите просьбу словами, редактор перепишет и пришлёт новую версию\n🗑 «Удалить»\n\n/list — опубликованные заметки\n/drafts — черновики, которые ещё ждут решения");
  }
  elseif ($text === '/list') {
    $pub = array_slice(news_published(), 0, 10);
    if (!$pub) say($chat, 'На сайте пока нет опубликованных заметок.');
    foreach ($pub as $it) {
      say($chat, news_h(news_date_ru($it['published_at'])) . "\n<b>" . news_h($it['title']) . "</b>\n" . news_h(site_link($it['slug'])),
        ['reply_markup' => ['inline_keyboard' => [[['text' => 'Снять с сайта', 'callback_data' => 'unpub:' . $it['slug']]]]]]);
    }
  }
  elseif ($text === '/drafts') {
    $dr = news_read()['drafts'];
    if (!$dr) say($chat, 'Черновиков нет.');
    foreach ($dr as $did => $it) {
      $mid = news_send_draft($chat, $it, $did);
      if ($mid) news_update(function (&$d) use ($did, $mid) { if (isset($d['drafts'][$did])) $d['drafts'][$did]['msg_id'] = $mid; });
    }
  }
  elseif ($text !== '' && $text[0] !== '/' && $state['await_ai'] !== null) {
    $id = $state['await_ai'];
    $rid = news_update(function (&$d) use ($id, $text) {
      $d['await_ai'] = null;
      if (!isset($d['drafts'][$id])) return null;
      $rid = (string)(++$d['rseq']);
      $d['requests'][$rid] = ['draft' => $id, 'text' => mb_substr($text, 0, 2000, 'UTF-8'), 'status' => 'new',
        'created' => news_now()->format('Y-m-d H:i:s'), 'taken' => 0];
      return $rid;
    });
    say($chat, $rid
      ? "Принято. Дорабатываю черновик №$id, новая версия придёт сюда, обычно в течение 10 минут (с 8 до 23 по вашему времени). Если Mac выключен, сделаю, когда он включится."
      : 'Этого черновика уже нет.');
  }
  elseif ($text !== '' && $state['await_edit'] !== null) {
    $id = $state['await_edit'];
    $found = news_parse_edit($text);
    if (!$found) {
      say($chat, 'В тексте нет подписей разделов. Пришлите его с подписями, например «Заголовок: …» или «Что делать: …».');
    } else {
      $res = news_update(function (&$d) use ($id, $found) {
        if (!isset($d['drafts'][$id])) return [null, 'Этого черновика уже нет.'];
        [$it, $err] = news_normalize(array_merge($d['drafts'][$id], $found));
        if ($err) return [null, 'Не сохранено: ' . $err . '.'];
        $d['drafts'][$id] = $it + ['created' => $d['drafts'][$id]['created'] ?? ''];
        $d['await_edit'] = null;
        return [$it, null];
      });
      if ($res[1]) say($chat, $res[1]);
      else {
        $mid = news_send_draft($chat, $res[0], $id);
        if ($mid) news_update(function (&$d) use ($id, $mid) { if (isset($d['drafts'][$id])) $d['drafts'][$id]['msg_id'] = $mid; });
      }
    }
  }
  else {
    say($chat, 'Чтобы поправить черновик, нажмите под ним «Править сама» или «Доработать».');
  }
}

/* ===== Запуск из cron: забираем сообщения сами ===== */
if (PHP_SAPI === 'cli') {
  if (($argv[1] ?? '') !== 'poll') { fwrite(STDERR, "usage: php news-bot.php poll\n"); exit(1); }
  $dir = dirname(news_file());
  $lock = fopen($dir . '/poll.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);          // прошлый запуск ещё слушает
  $offFile = $dir . '/poll.offset';
  $offset = (int)@file_get_contents($offFile);
  $until = time() + 55;
  while (time() < $until) {
    $wait = max(1, min(25, $until - time()));
    $r = news_tg('getUpdates', ['offset' => $offset, 'timeout' => $wait, 'allowed_updates' => ['message', 'callback_query']], $wait + 10);
    if (empty($r['ok'])) {
      @file_put_contents($dir . '/poll.log', date('Y-m-d H:i:s') . ' getUpdates failed: ' . ($r['description'] ?? 'нет ответа') . "\n", FILE_APPEND);
      sleep(5); continue;
    }
    foreach ($r['result'] as $up) {
      $offset = $up['update_id'] + 1;
      file_put_contents($offFile, (string)$offset);
      /* короткий журнал для проверки: что пришло (без содержимого правок) */
      $kind = isset($up['callback_query']) ? 'button ' . ($up['callback_query']['data'] ?? '') : 'text ' . mb_substr((string)($up['message']['text'] ?? '-'), 0, 12, 'UTF-8');
      @file_put_contents($dir . '/poll.log', date('Y-m-d H:i:s') . " #{$up['update_id']} $kind\n", FILE_APPEND);
      try { handle_update($up); } catch (Throwable $e) { error_log('news-bot: ' . $e->getMessage()); }
    }
  }
  exit(0);
}

/* ===== Веб-вход (webhook) ===== */
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$cfg = news_cfg();
if ($cfg['tg_token'] === '' || $cfg['webhook_secret'] === ''
    || !hash_equals($cfg['webhook_secret'], (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
  http_response_code(403); echo '{"ok":false}'; exit;
}
$up = json_decode((string)file_get_contents('php://input'), true);
if (is_array($up)) handle_update($up);
echo '{"ok":true}';

<?php
/* «Почерк» — IndexNow: сообщает Яндексу (и через него Bing и другим участникам протокола),
   что страница появилась, изменилась или удалена. Работает без Яндекс Вебмастера и без его лимитов.
   Ключ лежит в корне сайта файлом /d38ebc51e6b777a66f3fdbc288efb394.txt — так поисковик проверяет, что запрос от владельца.
   Ключ не секретный по устройству протокола. Если поменять ключ — поменять и имя файла в корне.
   Подключается из api/news-bot.php. Сам по себе ничего не делает. */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'indexnow.php') { http_response_code(404); exit; }

const PK_INDEXNOW_KEY = 'd38ebc51e6b777a66f3fdbc288efb394';

/* $urls — полные адреса страниц сайта. Ошибки не мешают публикации: результат только пишется в лог. */
function pk_indexnow(array $urls, $logDir = null) {
  $urls = array_values(array_unique(array_filter($urls)));
  if (!$urls || !function_exists('curl_init')) return false;
  $host = parse_url($urls[0], PHP_URL_HOST);
  $body = json_encode([
    'host' => $host,
    'key' => PK_INDEXNOW_KEY,
    'keyLocation' => 'https://' . $host . '/' . PK_INDEXNOW_KEY . '.txt',
    'urlList' => $urls,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $ch = curl_init('https://yandex.com/indexnow');
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
  ]);
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($logDir) @file_put_contents(rtrim($logDir, '/') . '/indexnow.log',
    date('Y-m-d H:i:s') . " HTTP $code " . implode(' ', $urls) . "\n", FILE_APPEND);
  return $code === 200 || $code === 202;
}

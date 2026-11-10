<?php
/* Пример настроек приёма заявок. Боевой файл — api/config.php рядом:
   он не попадает в git (см. .gitignore) и выкладывается на хостинг скриптом deploy.sh.
   Пока config.php нет, работают эти значения: заявки пишутся в журнал, письма и Telegram выключены, вход в /zayavki/ закрыт. */
return [
  'site_url'   => 'https://pocherkagency.ru',
  'timezone'   => 'Europe/Moscow',
  'keep_years' => 3,                          // срок хранения заявок, как в политике (п. 6)
  // 'data_dir' => '/var/www/u1234567/data/pocherk-leads',  // папка журнала вне сайта, если хостинг позволяет; по умолчанию api/data, закрыта .htaccess

  'email_to'   => '',                         // куда слать заявки, например 'sergei4icks@yandex.ru'
  'email_from' => '',                         // ящик на домене, например 'zayavki@pocherkagency.ru'

  'tg_token'   => '',                         // токен бота; в Telegram уходит только номер заявки и ссылка
  'tg_chat_id' => '',

  /* Вход в /zayavki/: имя => пароль. Можно вписать и хэш из password_hash() — он начинается с $2y$.
     Пароль от 12 символов: после неверного ввода вход замедляется. */
  'users' => [],

  /* Бот новостей (api/news-bot.php): токен, секрет webhook, ключ робота-автора, код первого входа, папка данных вне сайта */
  'news' => [
    'tg_token' => '', 'webhook_secret' => '', 'draft_key' => '', 'claim_code' => '',
    // 'data_dir' => '/var/www/u1234567/data/pocherk-news',
  ],
];

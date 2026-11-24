# AliProfi Optimizer

Лёгкая замена тяжёлым «оптимизаторам» для WordPress на серверах **OpenResty / Nginx + PHP-FPM + Redis**.
Страничный кэш (`fastcgi_cache`) и сжатие (`gzip`/`brotli`) остаются на сервере, плагин делает только клиентскую
оптимизацию и своевременно сбрасывает серверный кэш.

## 📝 Описание

Плагин рассчитан на гостевые страницы, отдаваемые из FastCGI-кэша. На некэшированном запросе он:

- откладывает выполнение JavaScript до первого действия пользователя (задерживается всё, кроме списка исключений);
- добавляет `defer` скриптам и убирает мусор WordPress из `<head>`;
- снимает `loading="lazy"` с первого крупного изображения и ставит `fetchpriority="high"`;
- подменяет iframe YouTube лёгким превью;
- переносит Google Fonts в локальный кэш и выводит `preload` для `.woff2`;
- сбрасывает файлы FastCGI-кэша OpenResty по permalink, главной и архивам при изменении контента;
- чистит базу данных от ревизий, авто-черновиков, корзины, спама и транзиентов, при желании — по расписанию.

Для авторизованных пользователей, админки, AJAX/REST/cron, лент, 404, предпросмотра и `DONOTCACHEPAGE`
все модули полностью отключены.

## 🔧 Возможности

| Модуль | Что делает |
| --- | --- |
| FastCGI purge | `save_post`, `transition_post_status`, удаление записи, комментарии, меню. Файловая очистка и/или HTTP `PURGE`. В админ-баре — меню «Очистить» с пунктами «Весь сайт» и «Эту страницу»; полная очистка также доступна в настройках. |
| Delay JS | Задерживаются **все** скрипты, кроме исключений (`jquery`, `jquery-migrate` по умолчанию). Тег получает `type="delayed-script"`, `src` уходит в `data-delayed-src`. Запуск — по `mousemove / scroll / touchstart / keydown / click` или по таймеру 7 с (настраивается). Есть безопасный режим с исключением `wp-content`/`wp-includes`. Загрузчик — 721 байт. |
| Defer | `defer` скриптам, кроме `async`/`defer`, модулей и списка исключений (по умолчанию jQuery). |
| Очистка head | `wp-emoji`, `wp-embed`, `rsd_link`, `wlwmanifest_link`, `wp_generator`, `wp_shortlink`. |
| Изображения | Исключения из LazyLoad по классу/имени файла; первое крупное изображение контента — без `lazy` и с `fetchpriority="high"`. Опционально `width`/`height` из метаданных вложения. |
| YouTube | `<div class="yt-facade">` с превью `hqdefault.jpg` и SVG-кнопкой; iframe с `autoplay=1` создаётся по клику/Enter/Space. |
| Google Fonts | CSS и `.woff2` скачиваются в `wp-content/cache/aliprofi-optimizer-fonts/`, внешняя ссылка подменяется локальной, добавляется `font-display: swap`. Preload — по списку в настройках. |
| База данных | Ревизии, авто-черновики, записи в корзине, спам и удалённые комментарии, транзиенты, `OPTIMIZE TABLE`. Счётчики, очистка по кнопке и WP-Cron daily/weekly/monthly. |

## 🛠 Требования

- PHP 8.1+ (проверено на 8.6), WordPress 6.4+.
- OpenResty или Nginx с `fastcgi_cache` (для HTTP-очистки — модуль `ngx_cache_purge`).
- Запись в каталог кэша для пользователя PHP-FPM — **только** если выбрана файловая очистка.
- Без Composer, внешних библиотек и React/Vue в админке.

## 📦 Установка

1. Скопируйте папку `aliprofi-optimizer` в `wp-content/plugins/` (или загрузите ZIP через «Плагины → Добавить новый»).
2. Активируйте плагин. При активации создаётся одна автозагружаемая опция `aliprofi_optimizer`.
3. Откройте **Оптимизатор** в левом меню админки. Настройки разложены по вкладкам: «Кэш», «JavaScript», «Медиа», «База данных», «Шрифты».

## ⚙️ Настройки по умолчанию

| Параметр | По умолчанию |
| --- | --- |
| Очистка кэша | включена, способ `Файловая система` |
| Каталог кэша | `/var/cache/nginx/fastcgi` |
| Уровни | `1:2` |
| Ключ кэша | `{scheme}{method}{host}{uri}` (метод всегда `GET`) |
| PURGE-адрес | пусто |
| Полная очистка при сохранении меню | включена |
| Delay JS | включён, таймер `7000` мс |
| Исключения задержки | `jquery`, `jquery-migrate`; безопасный режим выключен |
| Defer | включён, исключения `jquery-core`, `jquery` |
| Очистка head | все три пункта включены |
| Lazy-исключения | `custom-logo`, `hero` |
| `width`/`height` в контенте | выключено (может добавить запрос к postmeta) |
| YouTube-фасад | включён |
| Локальные шрифты | включены, preload пуст |
| Очистка БД | все пункты отмечены, автоочистка выключена (частота по умолчанию — раз в неделю) |

Исключения задержки и defer — подстроки без учёта регистра, по одной на строку.

## 🧩 Конфигурация OpenResty / Nginx

Плагин по умолчанию рассчитан на такую схему:

```nginx
# http { ... }

# Каталог, уровни и зона кэша
fastcgi_cache_path /var/cache/nginx/fastcgi
    levels=1:2
    keys_zone=WP:100m
    inactive=60m
    max_size=2g
    use_temp_path=off;

# PURGE-запросы должны попадать в тот же ключ, что и GET:
map $request_method $cache_method {
    PURGE   GET;
    default $request_method;
}

fastcgi_cache_key "$scheme$cache_method$host$request_uri";

# Кого не кэшируем (дублирует отключение плагина для авторизованных)
map $http_cookie $skip_cache {
    default                        0;
    "~*wordpress_logged_in"        1;
    "~*comment_author"             1;
    "~*wp-postpass"                1;
    "~*woocommerce_items_in_cart"  1;
}

map $request_uri $skip_cache_uri {
    default                     0;
    "~*/wp-admin/"              1;
    "~*/wp-login\.php"          1;
    "~*/wp-json/"               1;
    "~*/xmlrpc\.php"            1;
    "~*[?&]preview="            1;
    "~*sitemap"                 1;
}

server {
    listen 443 ssl http2;
    server_name example.com;
    root /var/www/example.com;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    # Очистка (нужен модуль ngx_cache_purge: nginx-extras / libnginx-mod-http-cache-purge)
    location ~ /purge(/.*) {
        allow 127.0.0.1;
        allow ::1;
        # allow 203.0.113.10;  # IP сервера, если PURGE идёт на внешний адрес
        deny all;

        fastcgi_cache_purge WP "$scheme$cache_method$host$1$is_args$args";
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        fastcgi_cache WP;
        fastcgi_cache_valid 200 301 302 60m;
        fastcgi_cache_bypass $skip_cache $skip_cache_uri;
        fastcgi_no_cache    $skip_cache $skip_cache_uri;
        fastcgi_cache_use_stale error timeout updating http_500 http_503;
        fastcgi_cache_lock on;
        fastcgi_cache_lock_timeout 5s;
        fastcgi_ignore_headers Cache-Control Expires Set-Cookie;

        add_header X-FastCGI-Cache $upstream_cache_status always;
    }
}
```

Проверка: `curl -I https://example.com/` → `X-FastCGI-Cache: HIT`,
`curl -X PURGE https://example.com/purge/` → `204 No Content`.

### Соответствие настроек плагина и Nginx

| Плагин | Nginx |
| --- | --- |
| Каталог кэша | путь из `fastcgi_cache_path` |
| Уровни | `levels=1:2` |
| Ключ кэша | `fastcgi_cache_key`; `{scheme}{method}{host}{uri}` = `"$scheme$cache_method$host$request_uri"` |
| HTTP PURGE | `https://example.com/purge` (плагин добавит URI страницы) |

`map $request_method` обязателен: без него PURGE-запрос даст ключ с методом `PURGE` и не совпадёт с `GET`.
Если ваш ключ содержит `$request_method` напрямую (без `map`), используйте файловую очистку.

Файловая очистка повторяет раскладку Nginx: имя файла — MD5 ключа, подкаталоги берутся с конца хеша
(`/cache/c/29/b7f54b2df7773722d382f4809d65029c` для `levels=1:2`). Плагин пробует и «хвостовую», и «головную»
раскладку, поэтому переживёт нестандартный `levels`.

### Права доступа

Файлы создаёт Nginx, а удаляет PHP-FPM. Если это разные пользователи, файловая очистка не сработает.

```bash
# общий пользователь (типовая связка nginx + php-fpm под www-data)
sudo chown -R www-data:www-data /var/cache/nginx/fastcgi

# или общая группа и setgid на каталогах
sudo chgrp -R www-data /var/cache/nginx/fastcgi
sudo chmod -R g+ws /var/cache/nginx/fastcgi
```

Альтернатива без прав на диск — способ «HTTP PURGE» (нужен `ngx_cache_purge`).
Состояние каталога видно на странице настроек.

## 🚀 Производительность и критерии приёмки

- Настройки читаются одним `get_option` из автозагружаемой опции → **0 SQL** на фронтенде.
- Модули не обращаются к БД: шрифты — файловый кэш, YouTube/JS/изображения — только строковые операции.
- Исключение — опция «Добавлять width/height»: для картинок без размеров нужен `wp_get_attachment_metadata()`
  (один запрос к postmeta на уникальное вложение, с кэшем на запрос). По умолчанию выключено.
- Очистка БД работает только в админке и по cron: счётчики считаются при открытии вкладки, на гостевые запросы SQL не добавляется.
- Загрузчик Delay JS — 721 байт, стили YouTube — ~430 байт.
- Время работы модулей на некэшированном запросе — единицы миллисекунд; буферизация Delay JS пропорциональна размеру HTML.

Как измерить в Query Monitor: по умолчанию плагин отключён для авторизованных. Для замера временно добавьте
в `wp-config.php`:

```php
define( 'ALIPROFI_OPTIMIZER_FORCE', true );
```

Откройте страницу как гость/админ с Query Monitor и убедитесь, что число запросов не выросло.
После замера константу удалите.

## 📁 Структура

```
aliprofi-optimizer/
├── aliprofi-optimizer.php        # заголовок, константы, подключение классов
├── uninstall.php                 # удаление опции и кэша шрифтов
├── includes/
│   ├── class-plugin.php          # запуск модулей, активация/деактивация, bail-out
│   ├── class-settings.php        # опция, значения по умолчанию, санитизация, проверка пути
│   ├── class-admin.php           # меню «Оптимизатор», вкладки, кнопки, уведомления
│   ├── class-cache.php           # файловая/HTTP-очистка, ключи и раскладка levels
│   ├── class-database.php        # очистка БД, счётчики и автоочистка по WP-Cron
│   ├── class-scripts.php         # defer и очистка head
│   ├── class-delay-js.php        # отложенные скрипты и загрузчик
│   ├── class-images.php          # LCP, lazy-исключения, width/height
│   ├── class-youtube.php         # фасад YouTube
│   └── class-fonts.php           # локализация Google Fonts, preload, очистка
├── LICENSE
└── README.md
```

## 🔄 Жизненный цикл

- **Деактивация** — удаляется `wp-content/cache/aliprofi-optimizer-fonts/` и задание WP-Cron автоочистки БД; кэш OpenResty не затрагивается.
- **Удаление плагина** — удаляется опция `aliprofi_optimizer` (на всех сайтах сети) и кэш шрифтов.

## ⚠️ Примечания

- `$scheme` в ключе Nginx должен совпадать с фактическим протоколом сайта. Если TLS терминируется на балансировщике,
  настройте `$scheme`/`fastcgi_param HTTPS` так, чтобы ключ был одинаковым для GET и PURGE.
- `$host` берётся без порта; для нестандартного порта ключ нужно скорректировать вручную.
- Первый гостевой запрос с Google Fonts скачивает CSS и `.woff2` (единицы файлов, таймаут 8 с). Дальше — статика.
  CSS обновляется раз в 7 дней; кнопка «Очистить кэш шрифтов» сбрасывает немедленно.
- `defer` не применяется к скриптам с `async`/`defer`, модулям и исключениям. Если тема ломается, добавьте handle/файл в исключения.
- Delay JS задерживает все скрипты, кроме исключений; `application/ld+json`, `importmap` и другие не-JS типы не трогаются. Если вёрстка или интерактив ломаются, включите «Безопасный режим» либо добавьте скрипт в исключения.
- Очистка БД необратима: перед первым запуском сделайте резервную копию. За один запуск обрабатывается до 500 записей/комментариев и 500 транзиентов; при большом объёме повторите.
- `OPTIMIZE TABLE` применяется только к таблицам не-InnoDB со свободным местом — как в WP Rocket; на полностью InnoDB-базе пункт может показывать 0.
- Автоочистка БД использует WP-Cron и запускается при посещениях сайта. На малопосещаемых сайтах настройте системный cron на `wp-cron.php`.
- Автоочистка при сохранении меню удаляет весь кэш — меню обычно выводится на всех страницах.

## 🗓 История версий

- **1.2.1** — пункты сброса кэша объединены в выпадающее меню «Очистить»: «Весь сайт» и «Эту страницу».
- **1.2.0** — в админ-баре на фронтенде появился пункт «Очистить эту страницу»: точечный сброс кэша текущего URL с учётом query-параметров и обоих вариантов слеша.
- **1.1.1** — уведомления перенесены под шапку страницы и получили собственный стиль с кнопкой закрытия; бегунок тумблера выровнен по центру; сохранение и кнопки приведены к единому оранжевому стилю; мелкие правки отступов и наведения на строки.
- **1.1.0** — меню «Оптимизатор» с вкладками и новым дизайном; Delay JS переведён на режим «задерживать всё, кроме исключений» + безопасный режим; добавлена очистка базы данных со счётчиками, кнопкой и расписанием daily/weekly/monthly.
- **1.0.0** — первая версия: FastCGI purge, Delay JS по списку фрагментов, defer, очистка head, изображения, YouTube-фасад, локальные Google Fonts.

## 📜 Лицензия

GPL-2.0-or-later. Полный текст — в [LICENSE](LICENSE).

**Автор:** Али Профи · [aliprofi.ru](https://aliprofi.ru)

# Развёртывание самостоятельной базы знаний

Статус: на проверке — шаблоны требуют проверки на выбранном сервере.<br>
Владелец: администратор нового проекта.<br>
Дата создания и последней проверки: 2026-10-14.<br>
Область: Linux VPS, nginx, PHP-FPM, PostgreSQL с pgvector.<br>
Связанные материалы: [README](../README.md), [архитектура](ARCHITECTURE.md).<br>
Источник: конфигурации `deploy` и `compose.production.yaml` этого репозитория.

Это установка нового пустого экземпляра. Команды с `sudo` выполняет администратор сервера; команды приложения — пользователь `knowledge-base`. Шаблоны рассчитаны на systemd, PHP 8.4 в `/usr/bin/php8.4`, PHP-FPM в `/usr/sbin/php-fpm8.4` и пользователя nginx `www-data`. Если система отличается, замените эти пути и имена в шаблонах.

## 1. Подготовка

Установите Git, Composer 2, Node.js 22.12+, npm, Docker Engine с Compose v2, nginx, PHP 8.4 CLI/FPM и расширения PDO PostgreSQL, mbstring, XML/DOM, cURL, fileinfo, OpenSSL, ZIP. SQLite нужен, если на сервере будут запускаться тесты. Для экспорта PDF установите LibreOffice. Для сертификата потребуется Certbot или другой механизм выдачи TLS.

Создайте DNS-запись своего домена на сервер. В примере используется `kb.example.com`; замените его в `.env` и `deploy/nginx.conf`. Выделите отдельные пути, пользователя, порт и Compose-проект для каждого экземпляра. Проверьте, что loopback-порт `55432` свободен. Откройте снаружи HTTP/HTTPS, а PostgreSQL оставьте на loopback.

```sh
sudo useradd --system --create-home --home-dir /srv/knowledge-base --shell /bin/bash knowledge-base
sudo install -d -o knowledge-base -g knowledge-base -m 0755 /srv/knowledge-base/current /srv/knowledge-base/shared
sudo -u knowledge-base git clone https://github.com/sxquer/knowledge-base-template.git /srv/knowledge-base/current
```

Для приватного репозитория настройте доступ GitHub на сервере своим способом: deploy key или авторизованный Git-клиент. Не вставляйте токен в URL и конфигурацию проекта.

## 2. Постоянные данные и конфигурация

Следующие команды выполняйте под `knowledge-base` при первой установке:

```sh
cd /srv/knowledge-base/current
mv storage /srv/knowledge-base/shared/storage
ln -s /srv/knowledge-base/shared/storage storage
cp .env.production.example /srv/knowledge-base/shared/.env
chmod 640 /srv/knowledge-base/shared/.env
ln -s /srv/knowledge-base/shared/.env .env
```

Отредактируйте `/srv/knowledge-base/shared/.env`: задайте название, настоящий HTTPS-адрес и новый случайный `DB_PASSWORD`. Оставьте `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`. Значения БД должны совпадать с `compose.production.yaml`: по умолчанию БД и пользователь — `knowledge_base`, порт — `55432`. Не переносите `.env` другого проекта.

Сгенерируйте пароль БД своим менеджером паролей; файл с ним должен оставаться вне Git. `APP_KEY` пока пуст — его создаёт следующая команда. Внешние API-ключи после установки задаются через интерфейс.

## 3. Зависимости, БД и первый владелец

Под `knowledge-base`:

```sh
cd /srv/knowledge-base/current
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
php8.4 artisan key:generate --no-interaction
```

Под администратором сервера:

```sh
sudo docker compose --project-directory /srv/knowledge-base/current --env-file /srv/knowledge-base/shared/.env -f /srv/knowledge-base/current/compose.production.yaml up -d --wait
```

Compose запускает **только PostgreSQL**, с закрытым loopback-портом и постоянным томом. PHP и nginx работают как сервисы хоста. Не используйте `docker compose down -v` для действующей базы: эта команда удаляет том с данными.

Снова под `knowledge-base`:

```sh
cd /srv/knowledge-base/current
php8.4 artisan migrate --force --no-interaction
php8.4 artisan kb:admin owner@example.com --name="Владелец базы"
php8.4 artisan kb:initialize --no-interaction
php8.4 artisan config:cache
php8.4 artisan route:cache
php8.4 artisan view:cache
```

Замените email; пароль `kb:admin` вводится скрыто. Не запускайте `db:seed` для создания владельца. После инициализации есть только пустые разделы и схемы виджетов. Миграция включает pgvector и индексы; пользователь БД должен иметь право `CREATE EXTENSION vector`.

Пользователь `knowledge-base` должен иметь запись в `/srv/knowledge-base/shared/storage` и `current/bootstrap/cache`, а nginx — чтение файлов в `current/public`. Пример для отдельной новой установки:

```sh
sudo chown -R knowledge-base:knowledge-base /srv/knowledge-base/current /srv/knowledge-base/shared
sudo chmod -R u+rwX,go+rX /srv/knowledge-base/current
sudo chmod -R u+rwX,g+rX,o-rwx /srv/knowledge-base/shared
sudo chmod 640 /srv/knowledge-base/shared/.env
```

`.env` — символическая ссылка на shared-файл; убедитесь, что итоговые права самого файла остались `640`. Приложение не требует публичной ссылки `public/storage`: вложения выдаются через авторизованный контроллер.

## 4. PHP-FPM

Проверьте и при необходимости измените `deploy/php-fpm.conf`, `php-fpm-master.conf` и `knowledge-base-web.service`. Они используют пользователя `knowledge-base`, каталог `/srv/knowledge-base`, сокет `/run/knowledge-base/php-fpm.sock`, лимит загрузки 200 MiB и отдельные журналы в shared storage.

Под администратором сервера:

```sh
sudo cp /srv/knowledge-base/current/deploy/knowledge-base-web.service /etc/systemd/system/
sudo /usr/sbin/php-fpm8.4 --test --fpm-config /srv/knowledge-base/current/deploy/php-fpm-master.conf
sudo systemctl daemon-reload
sudo systemctl enable --now knowledge-base-web
```

Если проверка конфигурации требует каталог сокета, создайте `/run/knowledge-base` перед проверкой; при запуске сервис создаёт его через `RuntimeDirectory`.

## 5. TLS и nginx

`deploy/nginx.conf` уже содержит HTTPS-блок. Установите его после выдачи сертификата. Для первого сертификата создайте временный HTTP-конфиг nginx для своего домена с таким блоком:

```nginx
server {
    listen 80;
    server_name kb.example.com;
    location ^~ /.well-known/acme-challenge/ {
        root /srv/knowledge-base/acme;
    }
    location / { return 503; }
}
```

Под администратором сервера:

```sh
sudo mkdir -p /srv/knowledge-base/acme
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --webroot -w /srv/knowledge-base/acme -d kb.example.com
```

Затем замените домен и пути сертификатов в `deploy/nginx.conf`, установите конфиг в активную конфигурацию nginx. Например, для системы с `sites-enabled`:

```sh
sudo cp /srv/knowledge-base/current/deploy/nginx.conf /etc/nginx/sites-available/knowledge-base
sudo ln -s /etc/nginx/sites-available/knowledge-base /etc/nginx/sites-enabled/knowledge-base
sudo nginx -t
sudo systemctl reload nginx
```

Временный HTTP-конфиг удалите из активной конфигурации, чтобы не было двух одинаковых `server_name`. При повторной установке ссылки используйте уже существующую. Настройте автоматическое продление сертификата и перезагрузку nginx после успешного продления. Проверьте его механизм на своём сервере.

Проверьте `https://ваш-домен/up` (HTTP 200), вход, сохранение новой статьи, поиск и загрузку тестового вложения. Production-форма создания владельца должна отсутствовать. Браузер должен получать secure cookie.

## 6. Фоновые задачи

Интеграции по умолчанию не настроены. Для базовых страниц и текстового поиска workers не обязательны. После настройки внешних сервисов установите нужные сервисы:

```sh
sudo cp /srv/knowledge-base/current/deploy/knowledge-base-queue.service /etc/systemd/system/
sudo cp /srv/knowledge-base/current/deploy/knowledge-base-calls.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now knowledge-base-queue knowledge-base-calls
```

`knowledge-base-queue` обрабатывает индексацию и аудит, `knowledge-base-calls` — звонки. `DB_QUEUE_RETRY_AFTER=180` в шаблоне превышает timeout общей очереди 120 секунд; подключение `calls` задаёт retry_after 210 и timeout 150. После смены конфигурации перезапускайте workers.

Для импорта Sipuni каждые 30 минут сначала задайте собственные настройки в интерфейсе и включите импорт, затем:

```sh
sudo cp /srv/knowledge-base/current/deploy/knowledge-base-sipuni.service /etc/systemd/system/
sudo cp /srv/knowledge-base/current/deploy/knowledge-base-sipuni.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now knowledge-base-sipuni.timer
```

Проверьте `systemctl status` и журналы через `journalctl -u имя-сервиса`. Журналы и конфиги интеграций не отправляйте в Git.

## 7. Обновление и восстановление

Перед обновлением сделайте согласованную резервную копию БД, shared storage и `.env`, сохраните используемый commit. Для БД можно использовать `pg_dump` внутри контейнера, без пароля в командной строке. Храните копии вне репозитория с ограниченным доступом и проверяйте восстановление на отдельном экземпляре.

Для небольшого экземпляра: включите режим обслуживания `php8.4 artisan down`, остановите workers, обновите код, выполните `composer install --no-dev`, `npm ci`, `npm run build`, `migrate --force`, пересоздайте config/route/view-кеши, перезапустите PHP-FPM и workers, затем `php8.4 artisan up`. При ошибке оставьте режим обслуживания и восстановите проверенные код и резервную копию; старый код может быть несовместим с новой схемой БД.

Не выполняйте `key:generate` при обновлении. Новая установка получает новый ключ; восстановление существующей базы использует её прежний `APP_KEY`.

Шаблоны развёртывания не запускают установку на сервере автоматически и не содержат доступов к исходному серверу. Здесь не выполнялась проверка нового VPS, сертификата, реального S3 и вызовов внешних провайдеров.

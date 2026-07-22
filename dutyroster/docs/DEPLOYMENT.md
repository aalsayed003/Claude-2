# Deployment Guide — Duty Roster (on-premise)

This app is deliberately dependency-light (no Composer). You need **PHP 8.1+**
with the PDO driver for your database, and a web server whose document root
points at the **`public/`** folder.

Required PHP extensions:
- `pdo_mysql` (app DB on MySQL/MariaDB) — or `pdo_sqlsrv` / `pdo_pgsql`.
- For reading BioTime on SQL Server: `pdo_sqlsrv` (Microsoft's PHP driver).

---

## 0. Confirmed target stack (Windows + XAMPP + SQL Server)

This is the intended on-prem setup:

- **Web + app runtime:** XAMPP (Apache + PHP) on Windows Server.
- **App database:** MySQL/MariaDB — bundled with XAMPP, so no extra install.
  The rebuilt app lives here and never writes to your production SQL Server.
- **Punch source:** your existing **SQL Server** `checkinout` table, read
  **read-only** by the 5-minute ingest job via `pdo_sqlsrv`.

### 0.1 Install the SQL Server PHP driver into XAMPP
XAMPP does not ship `pdo_sqlsrv`. Add it once:

1. Install Microsoft's **ODBC Driver 18 for SQL Server** (Windows MSI).
2. Download the **Microsoft Drivers for PHP for SQL Server** matching your
   XAMPP PHP version (`php -v`) and thread-safety (XAMPP is **TS/Thread-Safe**,
   x64). You need `php_pdo_sqlsrv_XX_ts_x64.dll`.
3. Copy that DLL into `C:\xampp\php\ext\`.
4. In `C:\xampp\php\php.ini` add:
   ```ini
   extension=pdo_sqlsrv
   ```
5. Restart Apache from the XAMPP control panel, then verify:
   ```
   C:\xampp\php\php.exe -m | findstr sqlsrv
   ```
   You should see `pdo_sqlsrv`.

### 0.2 Create the app database (MySQL via XAMPP)
Open **phpMyAdmin** (`http://localhost/phpmyadmin`) → create database
`duty_roster` (utf8mb4), or from a shell:
```
C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE duty_roster CHARACTER SET utf8mb4;"
```

### 0.3 Configure, install, deploy
```
copy config\config.example.php config\config.php
:: edit config\config.php -> db (mysql, root/your-pass), and punch_source (sqlsrv)
C:\xampp\php\php.exe ingest\install.php --admin-pass=CHOOSE-A-STRONG-ONE
```
Point an Apache vhost's DocumentRoot at `...\dutyroster\public` (see §4), then
browse to it. Schedule the ingest job per §3 (Windows Task Scheduler).

> The generic engine-agnostic steps below still apply — §0 is the fast path for
> your confirmed XAMPP + SQL Server environment.

---

## 1. Get the database ready

Create the app database and a user, then run the installer.

```sql
-- MySQL / MariaDB
CREATE DATABASE duty_roster CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'duty_roster'@'localhost' IDENTIFIED BY 'STRONG-PASSWORD';
GRANT ALL PRIVILEGES ON duty_roster.* TO 'duty_roster'@'localhost';
FLUSH PRIVILEGES;
```

```bash
cp config/config.example.php config/config.php
# edit config/config.php -> db.host/username/password/database
php ingest/install.php --admin-pass='CHOOSE-A-STRONG-ONE'
```

The installer creates all tables and seeds the shift set, sample departments
and the `admin` user.

---

## 2. Wire up the biometric feed

The app never talks to the biometric devices directly — BioTime already writes
punches into the `checkinout` table. Point the ingest job at that table.

In `config/config.php`, set `punch_source`:
```php
'punch_source' => [
    'enabled'  => true,
    'driver'   => 'sqlsrv',        // or mysql / pgsql, matching BioTime
    'host'     => '10.0.0.5',
    'port'     => 1433,
    'database' => 'biotime',
    'username' => 'reader',        // read-only login is enough
    'password' => '...',
    'query'    => "SELECT id AS source_id, pin, checktime AS punch_time,
                          checktype AS check_type, sn_name AS device_name,
                          SN AS device_sn, area_name AS area_name
                   FROM checkinout
                   WHERE id > :last_id
                   ORDER BY id ASC",
],
```
Adjust the column names in the `query` to match your actual `checkinout`
schema. The importer is **idempotent** — it tracks the highest imported id.

Test it once by hand:
```bash
php ingest/load_punches.php
```

---

## 3. Schedule the 5-minute import

**Linux (cron):**
```cron
*/5 * * * * /usr/bin/php /var/www/dutyroster/ingest/load_punches.php >> /var/log/dutyroster.log 2>&1
```

**Windows (Task Scheduler):**
```bat
schtasks /create /sc minute /mo 5 /tn "DutyRoster Punch Import" ^
  /tr "\"C:\php\php.exe\" C:\inetpub\dutyroster\ingest\load_punches.php"
```

Each run imports new punches and recomputes attendance for the affected days.

---

## 4. Web server

### Apache
```apache
<VirtualHost *:80>
    ServerName roster.hospital.local
    DocumentRoot /var/www/dutyroster/public
    <Directory /var/www/dutyroster/public>
        AllowOverride All          # enables the bundled .htaccess rewrite
        Require all granted
    </Directory>
</VirtualHost>
```
Enable `mod_rewrite`. The bundled `public/.htaccess` handles routing.

### Nginx + PHP-FPM
```nginx
server {
    listen 80;
    server_name roster.hospital.local;
    root /var/www/dutyroster/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\. { deny all; }
}
```

### IIS (Windows Server)
1. Install PHP (e.g. via the Web Platform Installer) and the **URL Rewrite** module.
2. Create a site whose physical path is the `public/` folder.
3. The bundled `public/web.config` provides the rewrite rule.

If the app lives in a sub-folder (e.g. `https://server/dutyroster/`), set
`app.base_url` to `/dutyroster/` in `config/config.php`.

---

## 5. First login & hardening
- Sign in as `admin` and create real user accounts under the appropriate roles.
- Link each user to their employee record so self-service screens work.
- Import your real employees (replace the seed rows) and map each `pin` to the
  biometric enrollment number.
- Put the site behind HTTPS (reverse proxy or a server certificate).
- Keep `config/config.php` out of source control (it already is via `.gitignore`).

---

## 6. Recomputing attendance
Attendance is recomputed automatically as punches arrive. To rebuild a whole
period (e.g. after editing shifts or the roster), use **View Attendance →
Recompute period**, or run:
```bash
php -r 'require "app/bootstrap.php"; (new App\Services\AttendanceEngine())->rebuildPeriod("2023-09");'
```

## Roadmap / not yet built
These were visible in the storyboard and are scaffolded but need business rules
confirmed before finishing:
- Correction / schedule-change / overtime **approval actions** (request capture
  and status tracking are in; the approve/reject chain for these three mirrors
  the roster one and can be enabled once the exact routing is confirmed).
- Public-holiday calendar management UI (table exists; seed via SQL for now).
- Leave import/integration (table exists; used by the attendance engine).
- Active Directory / LDAP login (local accounts today).
- Printable/exportable roster & attendance reports.

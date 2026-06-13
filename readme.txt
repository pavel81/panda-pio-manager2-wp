📄 readme.txt

=== Panda PIO Manager ===
Contributors: Panda Plugins
Tags: panda, integration, manager, api, hmac, rest, cron
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Centrální správce připojení pro systém Panda – umožňuje bezpečné REST API, ověřování přes HMAC, periodické cron úlohy a testování připojení k hlavnímu PIO serveru.

== Popis ==

**Panda PIO Manager** (Panda Integration Operator Manager) je centrální modul, který zajišťuje:
- bezpečné připojení mezi webovými aplikacemi Panda (SEO Hub, Indexer, Bridge aj.),
- REST API rozhraní s podporou HMAC autentizace (App Key / App Secret),
- plánované úlohy přes WP-Cron i externí cron server,
- AJAX test připojení a administraci v prostředí WordPressu.

Plugin lze použít jako **samostatný komunikační modul** nebo jako **řídicí prvek** pro ostatní Panda pluginy, které se připojují přes Bridge nebo REST.

== Hlavní funkce ==

* 🔐 **HMAC autentizace**
  - Bezpečné podepisování požadavků pomocí App Key / App Secret.
  - Ochrana proti replay útokům (časové okno ±5 minut).
  - Hlavičky: `X-PIO-Key`, `X-PIO-Signature`, `X-PIO-Timestamp`.

* 🌐 **REST API**
  - `/wp-json/pio/v1/ping` → veřejný ping (kontrola dostupnosti).
  - `/wp-json/pio/v1/check` → test připojení (jen admin nebo HMAC).
  - `/wp-json/pio/v1/cron-run` → spouštění plánovaných úloh (cron server).

* ⚙️ **Cron job server**
  - Možnost zapnout WP-Cron i externí cron.
  - Intervaly: hodinově, 2× denně, denně nebo vlastní (minuty).
  - Automaticky generovaný token a callback URL.
  - Možnost volat přes URL token nebo HMAC podpis.

* 🧩 **Administrace**
  - Stránka **„Panda PIO“** v menu WordPressu.
  - Ukládání API URL, App Key, App Secret.
  - Zobrazení cron callback URL, tokenu a intervalů.
  - Tlačítko „Otestovat připojení (AJAX)“ s okamžitým výstupem.

* 🧠 **PSR-4 / Composer kompatibilní**
  - Namespace: `Panda\PIOManager`
  - Plná kompatibilita s Composerem, PSR-4 autoloadingem a Bridge integrací.

== Instalace ==

1. Nahraj složku `panda-pio-manager` do `/wp-content/plugins/`.
2. Aktivuj plugin v administraci WordPressu.
3. V menu se objeví položka **Panda PIO**.
4. Vyplň:
   - `PIO API URL` – adresa centrálního Panda serveru.
   - `PIO App Key` a `PIO App Secret` – přístupové údaje pro HMAC podpis.
5. (Volitelné) Zapni **WP-Cron** nebo použij **externí cron URL**.

== Použití REST API ==

**Ping (veřejné):**

GET /wp-json/pio/v1/ping

**Check (ověřené – admin nebo HMAC):**

POST /wp-json/pio/v1/check Content-Type: application/json X-PIO-Key: YOUR_KEY X-PIO-Signature: SIGNATURE X-PIO-Timestamp: UNIX_TIMESTAMP

{"payload":{"ping":"true"}}

**Cron run (externí server):**

GET /wp-json/pio/v1/cron-run?token=YOUR_TOKEN

== Generování HMAC podpisu ==

Podpis se počítá z řetězce:

METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + SHA256(BODY)

Např. v PHP:
```php
$method = 'POST';
$path = '/wp-json/pio/v1/check';
$timestamp = time();
$body = '{"payload":{"ping":"true"}}';
$secret = 'TVUJ_APP_SECRET';
$string = $method."\n".$path."\n".$timestamp."\n".hash('sha256', $body);
$signature = base64_encode(hash_hmac('sha256', $string, $secret, true));

== Cron job server ==

1. WP-Cron

Povolení přepínačem „Povolit WP-Cron“.

Vyber interval: hodinově / 2× denně / denně / vlastní (minuty).

Plugin naplánuje periodický „heartbeat“ požadavek do PIO serveru.



2. Externí cron

Volání REST endpointu:

GET /wp-json/pio/v1/cron-run?token=YOUR_TOKEN

Alternativně HMAC podpis (bez tokenu).

Token se automaticky generuje při první aktivaci pluginu.




== Bezpečnost ==

HMAC podpis chrání komunikaci mezi aplikacemi a PIO Managerem.

Každý požadavek musí obsahovat správné hlavičky a časové razítko.

Anti-replay ochrana: akceptováno ±300 s (lze změnit filtrem panda_pio/hmac_window).

Citlivé hodnoty (App Secret, tokeny) se nikdy nevypisují do výstupu.


== Filtrování a rozšíření ==

Hook	Popis

panda_pio/hmac_window	Úprava časového okna pro HMAC validaci (v sekundách).
panda_pio/endpoint	Přepsání výchozího API endpointu (např. při multi-site).
panda_pio/client	Vrátí instanci PIO Client pro použití v jiných pluginech.


== Logování ==

Plugin může zapisovat události (např. heartbeat, chyby spojení) do logu debug-pio.log ve složce wp-content/uploads/pio-logs/ (pokud je Logger aktivní).

== Odinstalace ==

Deaktivace odstraní naplánované cron úlohy, ale zachová nastavení App Key/Secret pro opětovné použití.

== Changelog ==

= 1.2.0 =

Přidán cron job server (WP-Cron + externí URL)

Přidána administrace intervalu, tokenu a callback URL

Doplněno HMAC ověření REST volání

Vylepšen AJAX test připojení


= 1.1.0 =

REST API /ping a /check

Přidán Security modul s HMAC ověřením

Integrace s Client třídou a Bridge


= 1.0.0 =

První verze pluginu Panda PIO Manager

Administrace API URL, App Key, App Secret

AJAX test připojení


---

Chceš, abych k tomu rovnou připravil i **`changelog.txt`** ve stručnější formě (jen historie verzí a změn, vhodné pro ZIP balíček)?


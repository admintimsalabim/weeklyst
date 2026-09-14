# Weeklyst — Installatie & Gebruikershandleiding

**Versie 1.8** — De gezinsplanner voor maaltijden, boodschappen en klantenkaarten.

---

## 📋 Inhoudsopgave

1. [Vereisten](#vereisten)
2. [Installatie voor de beheerder](#installatie)
3. [Stap 1 — Database aanmaken](#stap-1-database)
4. [Stap 2 — Bestanden uploaden](#stap-2-bestanden)
5. [Stap 3 — Configuratie invullen](#stap-3-config)
6. [Stap 4 — Eerste keer opstarten](#stap-4-opstarten)
7. [Admin dashboard](#admin-dashboard)
8. [Handleiding voor gebruikers](#gebruikers)
9. [Handleiding voor beheerders](#beheerders)
10. [Problemen oplossen](#problemen)

---

## Vereisten

- PHP 8.0 of hoger met extensies: `pdo_mysql`, `openssl`, `curl`
- MySQL 5.7 of hoger (of MariaDB 10.4+)
- HTTPS verbinding (verplicht voor PWA en push notificaties)
- SMTP toegang voor het versturen van e-mails
- Webserver met `.htaccess` ondersteuning (Apache)

---

## Installatie

### Stap 1 — Database aanmaken

1. Open **phpMyAdmin** of een andere MySQL beheerinterface
2. Maak een nieuwe database aan, bijv. `weeklyst`
3. Maak een database gebruiker aan met volledige rechten op die database
4. Ga naar **Importeren** en importeer het bestand:
   ```
   database/weeklyst_schema.sql
   ```
5. Noteer de volgende gegevens voor stap 3:
   - Databasenaam
   - Gebruikersnaam
   - Wachtwoord
   - Host (vrijwel altijd `localhost`)

---

### Stap 2 — Bestanden uploaden

Upload alle bestanden uit de map `app/` naar de **root van je webhosting** (`public_html` of `www`):
Verander de bestandsnaam `htaccess.txt` naar `.htaccess`
```
app/
├── index.html          ← Landingspagina + inlogschermen
├── app.html            ← De weekplanner webapp
├── about.html          ← Over pagina
├── privacy.html        ← Privacy policy
├── install.html        ← Installatie instructies voor gebruikers
├── lang.js             ← Alle vertalingen (NL + EN)
├── sw.js               ← Service worker (push notificaties + cache)
├── api.php             ← REST API backend
├── config.php          ← ⚠️ Configuratie — zie stap 3
├── db.php              ← Database verbinding
├── mailer.php          ← E-mail functies
├── csrf.php            ← Bot bescherming
├── push.php            ← Push notificaties
├── invite.php          ← Uitnodigingsmails backend
├── admin.html          ← Admin dashboard
├── admin_api.php       ← Admin API backend
├── invite_admin.html   ← Uitnodigingen sturen
└── .htaccess           ← Beveiliging + HTTPS redirect
```

> ⚠️ **Let op:** Sla `config.php` NOOIT op in een publieke Git repository — dit bestand bevat wachtwoorden.

---

### Stap 3 — Configuratie invullen

Open `config.php` en vul alle waarden in:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'jouw_database_naam');    // ← aanpassen
define('DB_USER', 'jouw_database_user');    // ← aanpassen
define('DB_PASS', 'jouw_database_wacht');   // ← aanpassen

// E-mail (SMTP)
define('SMTP_HOST',      'mail.jouwprovider.nl');   // ← SMTP server
define('SMTP_PORT',      587);
define('SMTP_USER',      'noreply@jouwdomein.nl');  // ← afzender
define('SMTP_PASS',      'jouw_email_wacht');       // ← e-mail wachtwoord
define('SMTP_FROM',      'noreply@jouwdomein.nl');  // ← zelfde als SMTP_USER
define('SMTP_FROM_NAME', 'Weeklyst');

// App URL — geen slash aan het einde!
define('APP_URL',        'https://jouwdomein.nl');  // ← jouw domein
define('ALLOWED_ORIGIN', 'https://jouwdomein.nl');  // ← zelfde als APP_URL

// Sessie beveiliging — kies een willekeurige string van minimaal 32 tekens
define('COOKIE_SECRET', 'vervang-dit-met-een-willekeurige-lange-string');
define('COOKIE_NAME',   'wl_sess');
define('COOKIE_DAYS',   30);
define('SESSION_DIR',   __DIR__ . '/.sessions');

// Admin wachtwoord — voor admin.html en invite_admin.html
define('INVITE_PASSWORD', 'kies-een-sterk-admin-wachtwoord');
```

**Tips:**
- Genereer een willekeurige `COOKIE_SECRET` via: `openssl rand -hex 32`
- Gebruik een sterk, uniek wachtwoord voor `INVITE_PASSWORD`
- Zorg dat de map `.sessions/` beschrijfbaar is door de webserver, of maak hem handmatig aan

---

### Stap 4 — Eerste keer opstarten

1. Ga naar `https://jouwdomein.nl`
2. Klik op **Maak gratis account aan**
3. Vul je naam, e-mailadres en wachtwoord in
4. Bevestig je e-mailadres via de code die je ontvangt
5. Maak een gezin aan met een naam
6. Je bent nu beheerder van het gezin

---

## Admin dashboard

Het admin dashboard is bereikbaar via `https://jouwdomein.nl/admin.html`

Login met het `INVITE_PASSWORD` uit `config.php`.

**Functies:**
- **Versie beheer** — stel het versienummer in; gebruikers ontvangen automatisch een updatemelding
- **Uitnodigingen** — stuur uitnodigingsmails naar nieuwe gezinsleden of mensen die de app willen bekijken
- **Gezinnen** — overzicht van alle gezinnen met zoekfunctie en verwijderoptie
- **Verwijderverzoeken** — afmeldverzoeken van gezinnen verwerken

---

## Handleiding voor gebruikers

### De app installeren op je telefoon

**iPhone / iPad (Safari):**
1. Ga naar `https://jouwdomein.nl` in Safari
2. Tik op het deelicoon (vierkantje met pijl omhoog)
3. Kies **Zet op beginscherm**
4. Tik op **Voeg toe**

**Android (Chrome):**
1. Ga naar `https://jouwdomein.nl` in Chrome
2. Tik op de drie puntjes rechtsboven
3. Kies **Toevoegen aan startscherm**
4. Tik op **Toevoegen**

> Tip: Open de app altijd via het beginscherm-icoon voor de beste ervaring en om push notificaties te ontvangen.

---

### Meedoen met een gezin

Je hebt een **uitnodigingslink** of een **gezinscode** nodig van de beheerder.

**Via uitnodigingslink:**
1. Tik op de link die je hebt ontvangen
2. Vul een naam en wachtwoord in
3. Je bent direct lid van het gezin

**Via gezinscode:**
1. Ga naar `https://jouwdomein.nl`
2. Tik op **🔑 Meedoen met uitnodigingscode**
3. Vul de gezinscode (6 tekens), je naam en een wachtwoord in

---

### Inloggen

1. Ga naar `https://jouwdomein.nl`
2. Tik op **Inloggen**
3. Kies de tab **👨‍👩‍👧 Gezinslid**
4. Vul je gezinscode, naam en wachtwoord in

---

### De weekplanner gebruiken

- **Maaltijden** — vul in wat je elke dag eet; de huidige dag is groen gemarkeerd
- **Boodschappen** — voeg artikelen toe en vink ze af in de winkel
- **Ideeën** — sla ideeën op voor avondeten
- **Verplaatsen** — houd het ⠇ icoontje ingedrukt om een item te verslepen
- **Nieuwe week** — tik op ⚙️ → Nieuwe week starten om de boodschappenlijst te wissen (maaltijden blijven bewaard)

---

### Klantenkaarten

1. Open ⚙️ Instellingen → Klantenkaarten → **＋ Kaart toevoegen**
2. Scan de barcode of QR-code via de camera, of voer het nummer handmatig in
3. Kies het type (EAN-13, Code 128 of QR-code) en een kleur
4. Tik op een kaart om hem groot te tonen bij de kassa

---

### Push notificaties instellen

1. Open ⚙️ Instellingen → Meldingen
2. Tik op **🔔 Meldingen inschakelen**
3. Geef toestemming wanneer je browser/telefoon daarom vraagt

Je ontvangt voortaan een melding wanneer een gezinslid de lijst heeft bijgewerkt (met 2 minuten vertraging).

---

### Wachtwoord vergeten

**Als je een e-mailadres hebt (beheerder):**
1. Tik op **Wachtwoord vergeten?** op het inlogscherm
2. Kies de **Beheerder** tab en vul je e-mailadres in
3. Je ontvangt een herstelcode per e-mail

**Als je geen e-mailadres hebt (gezinslid):**
1. Tik op **Wachtwoord vergeten?** op het inlogscherm
2. Kies de **Gezinslid** tab en vul je naam en gezinscode in
3. De beheerder ontvangt een e-mail met een resetlink
4. De beheerder stuurt jou die link door
5. Open de link en stel een nieuw wachtwoord in

---

## Handleiding voor beheerders

Als beheerder heb je toegang tot extra functies in ⚙️ Instellingen.

### Gezinsleden beheren

- **Uitnodigen** — tik op de **Uitnodigen** knop naast de gezinscode om een deelbare link te genereren
- **👑 Beheerder maken** — tik op de gouden kroon naast een lid om hem/haar ook beheerder te maken
- **✕ Verwijderen** — tik op de rode ✕ om een lid permanent te verwijderen

### Weekbegin instellen

In ⚙️ Instellingen → Week → **Week begint op** kun je kiezen op welke dag jouw week begint. De overige dagen worden automatisch ingevuld.

### Gezin opzeggen

1. Open ⚙️ Instellingen → Account opzeggen
2. Vink het bevestigingsvakje aan
3. Tik op **Verzoek sturen**

De beheerder van de app ontvangt een e-mail en verwijdert het gezin en alle data.

---

## Problemen oplossen

| Probleem | Oplossing |
|----------|-----------|
| Verbindingsfout bij inloggen | Controleer `DB_*` waarden in `config.php` |
| E-mails komen niet aan | Controleer `SMTP_*` waarden en spammap |
| App laadt niet | Controleer of `.htaccess` correct is geüpload |
| Push notificaties werken niet | App moet via het beginscherm-icoon worden geopend (niet als tabblad) |
| Barcode werkt niet in de winkel | Verwijder de kaart en voeg hem opnieuw toe |
| Cache problemen na update | Purge de Cloudflare cache of wis de browsercache |

---

## Beveiliging

- Alle wachtwoorden worden opgeslagen met bcrypt hashing
- Sessies worden beveiligd met HMAC-gesigneerde cookies
- Alle formulieren hebben bot bescherming
- HTTPS is verplicht (geconfigureerd via `.htaccess`)
- Gevoelige PHP bestanden zijn geblokkeerd via `.htaccess`

---

## Technische informatie

| Component | Technologie |
|-----------|-------------|
| Frontend | HTML, CSS, Vanilla JavaScript |
| Backend | PHP 8.0+ |
| Database | MySQL / MariaDB |
| E-mail | PHPMailer via SMTP |
| Barcodes | JsBarcode + ZXing + QRCode.js |
| Push | Web Push API + VAPID |
| Hosting | Elke Apache webhost met PHP en MySQL |

---

*Weeklyst is gebouwd in samenwerking met Claude.ai — https://claude.ai*

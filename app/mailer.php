<?php
/**
 * Weeklyst – mailer.php
 * Verstuurt e-mails via SMTP met PHP's ingebouwde socket functies.
 * Geen Composer of externe library nodig.
 */

require_once __DIR__ . '/config.php';

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = SMTP_FROM;
    $fromName= SMTP_FROM_NAME;

    // Maak verbinding
    $socket = @fsockopen('tcp://' . $host, $port, $errno, $errstr, 10);
    if (!$socket) {
        error_log("Weeklyst mailer: verbinding mislukt: $errstr ($errno)");
        return false;
    }

    $read = fgets($socket, 1024);
    if (substr($read, 0, 3) !== '220') { fclose($socket); return false; }

    // EHLO
    fputs($socket, 'EHLO ' . gethostname() . "\r\n");
    while ($line = fgets($socket, 1024)) {
        if (substr($line, 3, 1) === ' ') break;
    }

    // STARTTLS
    fputs($socket, "STARTTLS\r\n");
    $read = fgets($socket, 1024);
    if (substr($read, 0, 3) !== '220') { fclose($socket); return false; }

    // Upgrade naar TLS
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

    // EHLO opnieuw na TLS
    fputs($socket, 'EHLO ' . gethostname() . "\r\n");
    while ($line = fgets($socket, 1024)) {
        if (substr($line, 3, 1) === ' ') break;
    }

    // AUTH LOGIN
    fputs($socket, "AUTH LOGIN\r\n");
    fgets($socket, 1024);
    fputs($socket, base64_encode($user) . "\r\n");
    fgets($socket, 1024);
    fputs($socket, base64_encode($pass) . "\r\n");
    $read = fgets($socket, 1024);
    if (substr($read, 0, 3) !== '235') {
        error_log("Weeklyst mailer: authenticatie mislukt");
        fclose($socket); return false;
    }

    // MAIL FROM
    fputs($socket, 'MAIL FROM:<' . $from . ">\r\n");
    fgets($socket, 1024);

    // RCPT TO
    fputs($socket, 'RCPT TO:<' . $toEmail . ">\r\n");
    fgets($socket, 1024);

    // DATA
    fputs($socket, "DATA\r\n");
    fgets($socket, 1024);

    // Headers en body
    $boundary = md5(uniqid());
    $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n"
        . "To: =?UTF-8?B?" . base64_encode($toName) . "?= <$toEmail>\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n"
        . "Date: " . date('r') . "\r\n"
        . "\r\n";

    $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($plainText)) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($htmlBody)) . "\r\n"
        . "--$boundary--\r\n";

    fputs($socket, $headers . $body . "\r\n.\r\n");
    $read = fgets($socket, 1024);

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return substr($read, 0, 3) === '250';
}

// ── E-mail templates ──────────────────────────────────────

function mailVerifyCode(string $toEmail, string $toName, string $code): bool {
    $subject = 'Jouw verificatiecode voor ' . APP_NAME;
    $html = emailTemplate($toName,
        'Jouw verificatiecode',
        "Gebruik de onderstaande code om je e-mailadres te bevestigen.<br>De code is <strong>15 minuten</strong> geldig.",
        "<div style='font-size:36px;font-weight:bold;letter-spacing:8px;color:#2b6cb0;text-align:center;padding:20px 0'>$code</div>",
        'Heb je geen account aangemaakt? Dan kun je deze e-mail negeren.'
    );
    return sendMail($toEmail, $toName, $subject, $html);
}

function mailResetCode(string $toEmail, string $toName, string $code): bool {
    $subject = 'Wachtwoord opnieuw instellen — ' . APP_NAME;
    $html = emailTemplate($toName,
        'Wachtwoord opnieuw instellen',
        "Gebruik de onderstaande code om een nieuw wachtwoord in te stellen.<br>De code is <strong>15 minuten</strong> geldig.",
        "<div style='font-size:36px;font-weight:bold;letter-spacing:8px;color:#2b6cb0;text-align:center;padding:20px 0'>$code</div>",
        'Heb je geen wachtwoordreset aangevraagd? Dan kun je deze e-mail negeren.'
    );
    return sendMail($toEmail, $toName, $subject, $html);
}

function mailMagicCode(string $toEmail, string $toName, string $code): bool {
    $subject = 'Jouw inlogcode voor ' . APP_NAME;
    $html = emailTemplate($toName,
        'Inlogcode',
        "Gebruik de onderstaande code om in te loggen.<br>De code is <strong>15 minuten</strong> geldig en kan maar één keer gebruikt worden.",
        "<div style='font-size:36px;font-weight:bold;letter-spacing:8px;color:#2b6cb0;text-align:center;padding:20px 0'>$code</div>",
        'Heb je niet geprobeerd in te loggen? Dan kun je deze e-mail negeren.'
    );
    return sendMail($toEmail, $toName, $subject, $html);
}

function mailWelcome(string $toEmail, string $toName, string $familyName, string $inviteCode): bool {
    $subject = 'Welkom bij ' . APP_NAME . '!';
    $html = emailTemplate($toName,
        'Welkom bij ' . APP_NAME . '!',
        "Je account is aangemaakt en je gezin <strong>" . htmlspecialchars($familyName) . "</strong> is klaar voor gebruik.<br><br>"
        . "Deel de onderstaande uitnodigingscode met je gezinsleden zodat zij kunnen meedoen:",
        "<div style='font-size:28px;font-weight:bold;letter-spacing:6px;color:#2b6cb0;text-align:center;padding:20px 0'>$inviteCode</div>",
        'Fijn dat je meedoet!'
    );
    return sendMail($toEmail, $toName, $subject, $html);
}


function mailInvite(string $toEmail, string $toName, string $fromName, string $inviteCode, string $personalMsg = ''): bool {
    $subject = $fromName . ' nodigt je uit voor Weeklyst!';
    $appUrl  = APP_URL;
    $iconB64 = 'data:image/png;base64,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';

    $personalBlock = '';
    if ($personalMsg !== '') {
        $personalBlock = "
        <div style='background:#f0f7ff;border-left:4px solid #2b6cb0;border-radius:0 10px 10px 0;padding:14px 18px;margin:20px 0;font-size:14px;color:#4a6480;line-height:1.6;font-style:italic'>
          &ldquo;" . htmlspecialchars($personalMsg) . "&rdquo;
        </div>";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#dde8f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:32px 16px">
<table width="100%" style="max-width:580px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(43,108,176,0.12)">

  <!-- Header -->
  <tr><td style="background:linear-gradient(135deg,#2b6cb0 0%,#1a4a7a 100%);padding:36px 40px 28px;text-align:center">
    <img src="$iconB64" width="72" height="72" style="border-radius:18px;display:block;margin:0 auto 14px" alt="Weeklyst">
    <h1 style="margin:0;color:#ffffff;font-size:26px;font-weight:700">Weeklyst</h1>
    <p style="margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:14px">De gezinsplanner voor maaltijden &amp; boodschappen</p>
  </td></tr>

  <!-- Body -->
  <tr><td style="padding:36px 40px;background:#ffffff">
    <p style="margin:0 0 6px;font-size:15px;color:#4a6480">Hoi $toName,</p>
    <h2 style="margin:0 0 16px;font-size:21px;color:#1a2433;font-weight:700;line-height:1.3">
      $fromName nodigt je uit voor onze gezinsplanner! 🛒
    </h2>
    <p style="margin:0 0 16px;font-size:15px;color:#4a6480;line-height:1.7">
      Ik gebruik <strong>Weeklyst</strong> om onze maaltijden en boodschappen bij te houden.
      Het werkt heel makkelijk &mdash; je vult samen de weekplanning in en iedereen ziet meteen
      wat er op het menu staat en wat er mee moet van de supermarkt.
    </p>

    $personalBlock

    <!-- Features -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#eef3fa;border-radius:12px;margin:20px 0">
    <tr><td style="padding:20px 24px">
      <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#1a2433">Wat maakt Weeklyst handig?</p>
      <table cellpadding="0" cellspacing="0">
        <tr><td style="padding:5px 0"><span style="font-size:18px">🛒</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Gedeelde boodschappenlijst &mdash; altijd up-to-date</td></tr>
        <tr><td style="padding:5px 0"><span style="font-size:18px">🍽️</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Weekmenu plannen &mdash; nooit meer "wat eten we vandaag?"</td></tr>
        <tr><td style="padding:5px 0"><span style="font-size:18px">💡</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Ideeënlijst voor avondeten &mdash; inspiratie bij de hand</td></tr>
        <tr><td style="padding:5px 0"><span style="font-size:18px">🔒</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Geen reclame, geen tracking &mdash; gewoon eerlijk</td></tr>
      </table>
    </td></tr>
    </table>

    <!-- CTA -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0">
    <tr><td align="center">
      <a href="$appUrl" style="display:inline-block;background:#2b6cb0;color:#ffffff;text-decoration:none;padding:15px 44px;border-radius:10px;font-size:16px;font-weight:700">Ga naar Weeklyst &rarr;</a>
      <p style="margin:8px 0 0;font-size:12px;color:#8aabca">$appUrl</p>
    </td></tr>
    </table>

    <hr style="border:none;border-top:1px solid #e8eef5;margin:24px 0">

    <!-- Stappen -->
    <p style="margin:0 0 14px;font-size:14px;font-weight:700;color:#1a2433">Zo doe je mee in 3 stappen:</p>
    <table cellpadding="0" cellspacing="0" width="100%">
      <tr>
        <td valign="top" style="width:32px">
          <div style="width:26px;height:26px;background:#2b6cb0;border-radius:50%;text-align:center;line-height:26px;color:#fff;font-size:12px;font-weight:700">1</div>
        </td>
        <td style="padding:3px 0 12px 12px;font-size:14px;color:#4a6480;line-height:1.5">
          Ga naar <strong style="color:#1a2433">weeklyst.nl</strong> en maak een gratis account aan.
        </td>
      </tr>
      <tr>
        <td valign="top">
          <div style="width:26px;height:26px;background:#2b6cb0;border-radius:50%;text-align:center;line-height:26px;color:#fff;font-size:12px;font-weight:700">2</div>
        </td>
        <td style="padding:3px 0 12px 12px;font-size:14px;color:#4a6480;line-height:1.5">
          Kies <strong style="color:#1a2433">"Meedoen met gezin"</strong> en vul de uitnodigingscode in.
        </td>
      </tr>
      <tr>
        <td valign="top">
          <div style="width:26px;height:26px;background:#2b6cb0;border-radius:50%;text-align:center;line-height:26px;color:#fff;font-size:12px;font-weight:700">3</div>
        </td>
        <td style="padding:3px 0 0 12px;font-size:14px;color:#4a6480;line-height:1.5">
          Voeg Weeklyst toe aan je beginscherm voor de beste ervaring!
        </td>
      </tr>
    </table>

    <!-- Uitnodigingscode -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0">
    <tr><td style="background:#f0f7ff;border:2px dashed #b8ccdf;border-radius:12px;padding:20px;text-align:center">
      <p style="margin:0 0 8px;font-size:13px;color:#4a6480">Jouw uitnodigingscode:</p>
      <p style="margin:0;font-size:34px;font-weight:800;letter-spacing:8px;color:#2b6cb0;font-family:'Courier New',monospace">$inviteCode</p>
      <p style="margin:8px 0 0;font-size:12px;color:#8aabca">Vul deze code in bij stap 2</p>
    </td></tr>
    </table>

    <hr style="border:none;border-top:1px solid #e8eef5;margin:24px 0">
    <p style="margin:0;font-size:14px;color:#4a6480;line-height:1.6">
      Heb je vragen of lukt het niet? Stuur gerust een berichtje terug.<br>
      <strong style="color:#1a2433">Groetjes, $fromName</strong>
    </p>
  </td></tr>

  <!-- Footer -->
  <tr><td style="background:#dde8f4;padding:20px 40px;text-align:center">
    <p style="margin:0 0 4px;font-size:12px;color:#4a6480"><strong>Weeklyst</strong> &mdash; De simpele gezinsplanner</p>
    <p style="margin:0 0 4px;font-size:11px;color:#8aabca">weeklyst.nl &nbsp;&middot;&nbsp; Geen reclame &nbsp;&middot;&nbsp; Geen tracking</p>
    <p style="margin:8px 0 0;font-size:10px;color:#aabccc">Gebouwd in samenwerking met Claude.ai</p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

    return sendMail($toEmail, $toName, $subject, $html);
}


function mailInviteTest(string $toEmail, string $toName, string $fromName, string $personalMsg = ''): bool {
    $subject = $fromName . ' laat je Weeklyst zien!';
    $appUrl  = APP_URL;
    $iconB64 = 'data:image/png;base64,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';

    $personalBlock = '';
    if ($personalMsg !== '') {
        $personalBlock = "
        <div style='background:#f0f7ff;border-left:4px solid #2b6cb0;border-radius:0 10px 10px 0;padding:14px 18px;margin:20px 0;font-size:14px;color:#4a6480;line-height:1.6;font-style:italic'>
          &ldquo;" . htmlspecialchars($personalMsg) . "&rdquo;
        </div>";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#dde8f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:32px 16px">
<table width="100%" style="max-width:580px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(43,108,176,0.12)">

  <tr><td style="background:linear-gradient(135deg,#2b6cb0 0%,#1a4a7a 100%);padding:36px 40px 28px;text-align:center">
    <img src="$iconB64" width="72" height="72" style="border-radius:18px;display:block;margin:0 auto 14px" alt="Weeklyst">
    <h1 style="margin:0;color:#ffffff;font-size:26px;font-weight:700">Weeklyst</h1>
    <p style="margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:14px">De gezinsplanner voor maaltijden &amp; boodschappen</p>
  </td></tr>

  <tr><td style="padding:36px 40px;background:#ffffff">
    <p style="margin:0 0 6px;font-size:15px;color:#4a6480">Hoi $toName,</p>
    <h2 style="margin:0 0 16px;font-size:21px;color:#1a2433;font-weight:700;line-height:1.3">
      $fromName wil je Weeklyst laten zien! 👀
    </h2>
    <p style="margin:0 0 16px;font-size:15px;color:#4a6480;line-height:1.7">
      Ik gebruik <strong>Weeklyst</strong> voor de weekplanning van maaltijden en boodschappen.
      Een simpele, eerlijke app zonder reclame of tracking &mdash; gewoon overzichtelijk voor het hele gezin.
    </p>

    $personalBlock

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#eef3fa;border-radius:12px;margin:20px 0">
    <tr><td style="padding:20px 24px">
      <p style="margin:0 0 14px;font-size:13px;font-weight:700;color:#1a2433">Wat maakt Weeklyst handig?</p>
      <table cellpadding="0" cellspacing="0">
        <tr><td style="padding:5px 0"><span style="font-size:18px">🛒</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Gedeelde boodschappenlijst &mdash; altijd up-to-date</td></tr>
        <tr><td style="padding:5px 0"><span style="font-size:18px">🍽️</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Weekmenu plannen &mdash; nooit meer "wat eten we vandaag?"</td></tr>
        <tr><td style="padding:5px 0"><span style="font-size:18px">💡</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Ideeënlijst voor avondeten &mdash; inspiratie bij de hand</td></tr>
        <tr><td style="padding:5px 0"><span style="font-size:18px">🔒</span></td><td style="padding:5px 0 5px 10px;font-size:14px;color:#4a6480">Geen reclame, geen tracking &mdash; gewoon eerlijk</td></tr>
      </table>
    </td></tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0">
    <tr><td align="center">
      <a href="$appUrl" style="display:inline-block;background:#2b6cb0;color:#ffffff;text-decoration:none;padding:15px 44px;border-radius:10px;font-size:16px;font-weight:700">Bekijk Weeklyst &rarr;</a>
      <p style="margin:8px 0 0;font-size:12px;color:#8aabca">$appUrl</p>
    </td></tr>
    </table>

    <hr style="border:none;border-top:1px solid #e8eef5;margin:24px 0">

    <p style="margin:0 0 10px;font-size:14px;font-weight:700;color:#1a2433">Wil je het zelf proberen?</p>
    <p style="margin:0 0 16px;font-size:14px;color:#4a6480;line-height:1.6">
      Maak een gratis account aan op <strong>weeklyst.nl</strong> en zet de app op je beginscherm.
      Je kunt een eigen gezin aanmaken of meedoen met een bestaand gezin via een uitnodigingscode.
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0">
    <tr><td style="background:#f0fff4;border:1px solid #86efac;border-radius:12px;padding:16px 20px">
      <p style="margin:0;font-size:14px;color:#2f855a;line-height:1.6">
        ✓ &nbsp;<strong>Volledig gratis</strong> &nbsp;·&nbsp;
        ✓ &nbsp;<strong>Geen reclame</strong> &nbsp;·&nbsp;
        ✓ &nbsp;<strong>Data in Nederland</strong>
      </p>
    </td></tr>
    </table>

    <hr style="border:none;border-top:1px solid #e8eef5;margin:24px 0">
    <p style="margin:0;font-size:14px;color:#4a6480;line-height:1.6">
      Vragen of feedback? Laat het me weten!<br>
      <strong style="color:#1a2433">Groetjes, $fromName</strong>
    </p>
  </td></tr>

  <tr><td style="background:#dde8f4;padding:20px 40px;text-align:center">
    <p style="margin:0 0 4px;font-size:12px;color:#4a6480"><strong>Weeklyst</strong> &mdash; De simpele gezinsplanner</p>
    <p style="margin:0 0 4px;font-size:11px;color:#8aabca">weeklyst.nl &nbsp;&middot;&nbsp; Geen reclame &nbsp;&middot;&nbsp; Geen tracking</p>
    <p style="margin:8px 0 0;font-size:10px;color:#aabccc">Gebouwd in samenwerking met Claude.ai</p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

    return sendMail($toEmail, $toName, $subject, $html);
}

function mailMemberResetRequest(string $toEmail, string $toName, string $memberName, string $resetLink): bool {
    $subject = $memberName . ' wil zijn/haar wachtwoord resetten — Weeklyst';
    $appName = APP_NAME;
    $year    = date('Y');
    $html = <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#dde8f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:32px 16px">
<table width="100%" style="max-width:480px;background:#eef3fa;border-radius:16px;overflow:hidden">
  <tr><td style="background:#2b6cb0;padding:24px 32px;text-align:center">
    <span style="color:#fff;font-size:22px;font-weight:bold">$appName</span>
  </td></tr>
  <tr><td style="padding:32px">
    <p style="margin:0 0 8px;color:#4a6480;font-size:13px">Hoi $toName,</p>
    <h2 style="margin:0 0 16px;color:#1a2433;font-size:20px">🔑 Wachtwoord reset verzoek</h2>
    <p style="margin:0 0 16px;color:#4a6480;font-size:14px;line-height:1.6">
      <strong>$memberName</strong> wil zijn of haar wachtwoord resetten voor Weeklyst.
      Stuur de onderstaande knop of link door naar $memberName zodat hij/zij een nieuw wachtwoord kan instellen.
    </p>
    <div style="text-align:center;margin:24px 0">
      <a href="$resetLink" style="display:inline-block;background:#2b6cb0;color:#fff;text-decoration:none;padding:14px 32px;border-radius:10px;font-size:15px;font-weight:700">
        Wachtwoord resetten voor $memberName
      </a>
    </div>
    <p style="margin:0 0 8px;color:#4a6480;font-size:13px;line-height:1.6">
      Of kopieer deze link en stuur hem door:
    </p>
    <p style="margin:0;background:#dde8f4;border-radius:8px;padding:10px 12px;font-size:12px;color:#2b6cb0;word-break:break-all">$resetLink</p>
    <p style="margin:20px 0 0;color:#8aabca;font-size:12px">De link is 1 uur geldig. Heb je dit verzoek niet verwacht? Dan kun je deze mail negeren.</p>
  </td></tr>
  <tr><td style="padding:16px 32px;background:#dde8f4;text-align:center">
    <p style="margin:0;color:#8aabca;font-size:11px">&copy; $year $appName &mdash; Geen reclame, geen tracking</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    return sendMail($toEmail, $toName, $subject, $html);
}

function emailTemplate(string $name, string $title, string $intro, string $highlight, string $footer): string {
    $appName = APP_NAME;
    $year    = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#dde8f4;font-family:'DM Sans',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:40px 20px">
      <table width="100%" style="max-width:480px;background:#eef3fa;border-radius:16px;overflow:hidden">
        <tr><td style="background:#2b6cb0;padding:24px 32px;text-align:center">
          <span style="color:#fff;font-size:22px;font-weight:bold">$appName</span>
        </td></tr>
        <tr><td style="padding:32px">
          <p style="margin:0 0 8px;color:#4a6480;font-size:13px">Hoi $name,</p>
          <h2 style="margin:0 0 16px;color:#1a2433;font-size:20px">$title</h2>
          <p style="margin:0 0 24px;color:#4a6480;font-size:14px;line-height:1.6">$intro</p>
          $highlight
          <p style="margin:24px 0 0;color:#8aabca;font-size:12px">$footer</p>
        </td></tr>
        <tr><td style="padding:16px 32px;background:#dde8f4;text-align:center">
          <p style="margin:0;color:#8aabca;font-size:11px">&copy; $year $appName &mdash; Geen reclame, geen tracking</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

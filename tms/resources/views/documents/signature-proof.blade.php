{{--
    Proof page appended to a document signed online (Signatures::sign).
    Escaped values only; no external resource.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'">
    <title>Preuve de signature</title>
    <style>
        @page { size: A4; margin: 20mm 18mm; }
        body { font-family: "Liberation Sans", "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2933; }
        h1 { font-size: 16pt; margin: 0 0 12pt; }
        table { border-collapse: collapse; width: 100%; }
        th { text-align: left; width: 34%; padding: 5pt 8pt 5pt 0; vertical-align: top; color: #52606d; font-weight: 400; }
        td { padding: 5pt 0; border-bottom: 0.5pt solid #e4e7eb; word-break: break-all; }
        .note { margin-top: 16pt; font-size: 8.5pt; color: #52606d; }
    </style>
</head>
<body>
    <h1>Preuve de signature électronique</h1>
    <table>
        <tr><th>Document</th><td>{{ $title }}</td></tr>
        <tr><th>Signataire</th><td>{{ $signer }}</td></tr>
        <tr><th>Adresse e-mail vérifiée</th><td>{{ $email }}</td></tr>
        <tr><th>Signé le (heure de Paris)</th><td>{{ $signed_at }}</td></tr>
        <tr><th>Adresse IP</th><td>{{ $ip }}</td></tr>
        <tr><th>Navigateur</th><td>{{ $agent }}</td></tr>
        <tr><th>Empreinte SHA-256 du document signé</th><td>{{ $sha256 }}</td></tr>
        <tr><th>Référence de la signature</th><td>{{ $request }}</td></tr>
        <tr><th>Organisme</th><td>{{ $organisme }}</td></tr>
    </table>
    <p class="note">Signature électronique simple : le signataire a ouvert son lien personnel, lu le document, accepté de le
        signer et saisi le code à usage unique reçu à l'adresse e-mail ci-dessus. L'empreinte permet de vérifier que le
        document précédant cette page n'a pas été modifié.</p>
</body>
</html>

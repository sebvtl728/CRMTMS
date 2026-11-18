{{-- Repeated at the bottom of every page by Chromium (Gotenberg footer.html): inline styles only. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { margin: 0; padding: 0 0.6in; box-sizing: border-box; width: 100%; font-family: "Liberation Sans", "DejaVu Sans", Arial, sans-serif; font-size: 7pt; color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0; }
        td.page { text-align: right; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>{{ $title }}</td>
            <td class="page">Page <span class="pageNumber"></span> / <span class="totalPages"></span></td>
        </tr>
    </table>
</body>
</html>

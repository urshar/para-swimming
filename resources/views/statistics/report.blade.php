<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Jahresbericht {{ $config->year }} — ÖBSV Para Swimming</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
            padding: 24px;
        }
    </style>
</head>
<body>
{{-- Inhalt und dessen Gestaltung kommen aus dem gemeinsamen Partial, das
     auch die PDF-Fassung einbindet. --}}
@include('statistics.partials.sections')
</body>
</html>

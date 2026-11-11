<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>{{ $config['name'] }} · Yomara Office</title>
<link rel="icon" href="{{ url('/brand/mark-512.png') }}">
<link rel="stylesheet" href="{{ asset('office/office.css') }}?v={{ $ver }}">
</head>
<body>
<div id="root"></div>
<script>window.OFFICE = @json($config);</script>
<script type="module" src="{{ asset('office/'.$entry) }}"></script>
</body>
</html>

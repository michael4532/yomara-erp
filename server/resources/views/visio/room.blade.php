<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0B1220">
<title>{{ $m->title }} · {{ $c->name }}</title>
<link rel="stylesheet" href="{{ asset('vendor/visio/room.css') }}?v={{ $ver }}">
</head>
<body>
<div id="app" class="boot"><div class="spinner"></div></div>
<script>
window.VISIO = {
  code: @json($m->code),
  token: @json($token),
  name: @json($name),
  api: @json(url('/api/visio-room/'.$m->code)),
  base: @json(url('/')),
  sdk: { agora: @json(asset('vendor/visio/agora-4.24.9.js')), zego: @json(asset('vendor/visio/zego-3.13.0.js')) },
  embedded: window.self !== window.top
};
</script>
<script src="{{ asset('vendor/visio/room.js') }}?v={{ $ver }}"></script>
</body>
</html>

<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>{{ $l->title ?: parse_url($to, PHP_URL_HOST) }}</title>
<style>html,body,iframe{margin:0;height:100%;width:100%;border:0;overflow:hidden}</style></head>
<body><iframe src="{{ $to }}" title="{{ $l->title }}" referrerpolicy="no-referrer-when-downgrade" allow="fullscreen; clipboard-write; autoplay"></iframe>
<noscript><a href="{{ $to }}">Ouvrir</a></noscript></body></html>

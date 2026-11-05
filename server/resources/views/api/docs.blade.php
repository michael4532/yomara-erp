<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>API Yomara ERP · Documentation</title>
<style>
  :root { --night:#0B1B3A; --blue:#1F6FEB; --line:#E3E8F1; --muted:#5B6B85; --bg:#F6F8FC; }
  * { box-sizing:border-box; } body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; color:var(--night); background:var(--bg); line-height:1.55; }
  header { background:linear-gradient(135deg,#0B1B3A,#1B3D7A); color:#fff; padding:34px 20px; } header .in, main { max-width:1080px; margin:0 auto; }
  header h1 { margin:0 0 6px; font-size:30px; } header p { margin:0; color:#C9D4EA; }
  nav { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; } nav a { color:#fff; background:rgba(255,255,255,.12); padding:6px 12px; border-radius:20px; text-decoration:none; font-size:13px; }
  main { padding:24px 20px 60px; } h2 { margin:36px 0 10px; font-size:22px; } h3 { margin:22px 0 8px; font-size:16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px; margin:12px 0; }
  code, pre { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; } pre { background:#0E1729; color:#E8EEF9; padding:14px; border-radius:12px; overflow:auto; }
  code { background:#EEF2FA; padding:2px 6px; border-radius:6px; }
  table { width:100%; border-collapse:collapse; font-size:13px; } td, th { padding:7px 8px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
  .m { display:inline-block; min-width:58px; text-align:center; font-weight:800; font-size:11px; padding:3px 6px; border-radius:6px; color:#fff; }
  .GET { background:#12A150; } .POST { background:#1F6FEB; } .PUT, .PATCH { background:#E8A317; } .DELETE { background:#D93025; }
  .muted { color:var(--muted); } details summary { cursor:pointer; font-weight:700; padding:6px 0; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:12px; }
</style>
</head>
<body>
<header><div class="in">
  <h1>API Yomara ERP</h1>
  <p>Version {{ $version }} · Base : <code style="background:rgba(255,255,255,.15);color:#fff">{{ $base }}</code> · JSON, UTF-8, dates ISO 8601, montants en nombre décimal.</p>
  <nav><a href="#auth">Authentification</a><a href="#webhooks">Webhooks</a><a href="#events">Événements</a><a href="#feeds">Flux de données</a><a href="#nocode">Zapier, Make, n8n</a><a href="#endpoints">Points d'accès</a></nav>
</div></header>
<main>
  <h2 id="auth">Authentification</h2>
  <div class="card">
    <p>Créez une clé dans <b>Paramètres › Intégrations & API › Clés d'API</b> (lecture seule ou lecture/écriture). Elle agit avec les droits et les agences de son créateur, et s'utilise en en-tête :</p>
<pre>curl {{ $base }}/core/partners \
  -H "Authorization: Bearer VOTRE_CLE" \
  -H "Accept: application/json"</pre>
    <p class="muted">Une clé en lecture seule refuse POST / PUT / DELETE (403). Aucune clé ne peut gérer les comptes, les rôles ni les intégrations. Erreurs : 401 (clé invalide), 403 (droit manquant), 404, 422 (validation, champ <code>message</code> et <code>errors</code>).</p>
    <h3>Écriture groupée (recommandée pour synchroniser un autre logiciel)</h3>
<pre>POST {{ $base }}/sync/push
{ "mutations": [ { "id": "uuid-de-la-mutation", "entity": "partners", "op": "upsert",
                   "record_id": "uuid-du-client", "data": { "name": "Hôtel Ivoire", "type": "customer", "phone": "+2250700000000" } } ] }

GET {{ $base }}/sync/pull?cursor=0      → changements depuis le curseur (toutes les entités autorisées)</pre>
  </div>

  <h2 id="webhooks">Webhooks</h2>
  <div class="card">
    <p>Dans <b>Paramètres › Intégrations & API › Webhooks</b>, indiquez l'adresse qui recevra les événements choisis. Chaque événement est envoyé en <code>POST</code> JSON :</p>
<pre>POST https://votre-outil.example/webhook
X-Yomara-Event: invoice.paid
X-Yomara-Delivery: 5f0c…            (identifiant unique : ignorez les doublons)
X-Yomara-Signature: t=1793440000,v1=4b9e…

{ "id": "5f0c…", "event": "invoice.paid", "created_at": "2026-10-31T10:00:00+00:00", "company_id": 1,
  "data": { "id": "…", "number": "FAC-2026-00042", "partner_name": "Hôtel Ivoire", "total": 250000, "lines": [ … ] } }</pre>
    <p>Répondez par un code <b>2xx</b> en moins de 15 secondes. Sinon, nouvel essai après 1 min, 5 min, 30 min, 2 h, 6 h puis 24 h ; après 50 échecs consécutifs l'adresse est désactivée. Chaque envoi est consultable et peut être renvoyé.</p>
    <h3>Vérifier la signature</h3>
<pre>// PHP
[$t, $v1] = sscanf($_SERVER['HTTP_X_YOMARA_SIGNATURE'], 't=%d,v1=%s');
$ok = hash_equals(hash_hmac('sha256', $t.'.'.file_get_contents('php://input'), $secret), $v1) && abs(time() - $t) < 300;

// Node.js
const [t, v1] = sig.split(',').map(p => p.split('=')[1]);
const ok = crypto.timingSafeEqual(Buffer.from(v1), Buffer.from(crypto.createHmac('sha256', secret).update(t + '.' + rawBody).digest('hex')));</pre>
  </div>

  <h2 id="events">Événements</h2>
  <div class="card">
    <p>Abonnez-vous à des événements précis, à une famille (<code>partners.*</code>) ou à tout (<code>*</code>).</p>
    <table><tr><th>Événement</th><th>Description</th></tr>
      @foreach($events as $e)
        @if($e['group'] === 'Événements métier')<tr><td><code>{{ $e['key'] }}</code></td><td>{{ $e['label'] }}</td></tr>@endif
      @endforeach
    </table>
    <details><summary>Créations, modifications et suppressions de données</summary>
      <table>@foreach(collect($events)->where('group', '!=', 'Événements métier')->groupBy('group') as $g => $list)<tr><td>{{ $g }}</td><td>@foreach($list as $e)<code>{{ $e['key'] }}</code> @endforeach</td></tr>@endforeach</table>
    </details>
  </div>

  <h2 id="feeds">Flux de données (Google Sheets, Excel, Power BI)</h2>
  <div class="card">
    <p>Dans <b>Intégrations & API › Flux de données</b>, créez un lien secret vers un jeu de données, toujours à jour :</p>
<pre>Google Sheets :  =IMPORTDATA("{{ $base }}/feeds/VOTRE_JETON.csv")
Excel :          Données › À partir du web › {{ $base }}/feeds/VOTRE_JETON.csv
Power BI :       Obtenir les données › Web › {{ $base }}/feeds/VOTRE_JETON.json</pre>
    <table><tr><th>Jeu de données</th><th>Contenu</th></tr>@foreach($datasets as $k => $d)<tr><td><code>{{ $k }}</code></td><td>{{ $d['label'] }}</td></tr>@endforeach</table>
  </div>

  <h2 id="nocode">Zapier, Make, n8n, Pabbly</h2>
  <div class="grid">
    <div class="card"><b>Recevoir un événement Yomara</b><p class="muted">Créez un déclencheur « Webhook » (Zapier : <i>Webhooks by Zapier › Catch Hook</i> ; Make : <i>Custom webhook</i> ; n8n : <i>Webhook</i>), copiez son adresse dans un webhook Yomara, cliquez sur « Tester ».</p></div>
    <div class="card"><b>Écrire dans Yomara</b><p class="muted">Action « Requête HTTP » vers <code>{{ $base }}/…</code> avec l'en-tête <code>Authorization: Bearer VOTRE_CLE</code> (clé lecture/écriture).</p></div>
    <div class="card"><b>Exemples</b><p class="muted">Facture payée → message WhatsApp de remerciement ; nouvelle commande de la boutique → ligne Google Sheets ; inscription webinaire → contact Mailchimp ; ticket client → canal Slack.</p></div>
  </div>

  <h2 id="endpoints">Points d'accès</h2>
  <p class="muted">Liste générée à partir de l'installation (seuls les modules actifs de votre société répondent). Le droit indiqué est celui que doit avoir le compte de la clé.</p>
  @foreach($groups as $k => $list)
    <details class="card"><summary>{{ $labels[$k] ?? $k }} <span class="muted">({{ count($list) }})</span></summary>
      <table>@foreach($list as $r)<tr><td style="width:80px"><span class="m {{ $r['method'] }}">{{ $r['method'] }}</span></td><td><code>{{ $r['uri'] }}</code></td><td class="muted">{{ $r['perm'] }}</td></tr>@endforeach</table>
    </details>
  @endforeach
</main>
</body>
</html>

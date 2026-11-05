@php
  $fg = $style['text'] ?? '#0B1B3A';
  $primary = $style['primary'] ?? '#1F6FEB';
  $bg = $style['background'] ?? '#F4F6FB';
  $title = $form->title ?: $form->name;
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — {{ $company->name }}</title>
<meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $form->description), 150) }}">
<meta property="og:title" content="{{ $title }}">
<style>
  * { box-sizing: border-box; }
  body { margin:0; background: {{ $bg }}; font-family: {{ $style['font'] ?? 'Arial, Helvetica, sans-serif' }}; color: {{ $fg }}; }
  .wrap { max-width: 640px; margin: 0 auto; padding: 24px 12px 40px; }
  .card { background: {{ $style['card'] ?? '#FFFFFF' }}; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 12px rgba(11,27,58,.08); }
  .head { padding: 22px 32px 6px; font-weight: bold; font-size: 18px; color: {{ $primary }}; }
  .head img { height: 40px; }
  table { border-collapse: collapse; }
  img { max-width: 100%; height: auto; }
  .card img { max-width: 100% !important; }
  .form { padding: 8px 32px 28px; }
  .form h2 { margin: 8px 0 6px; font-size: 22px; }
  .form p.d { margin: 0 0 16px; color: #5B6B85; line-height: 1.5; }
  label { display:block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; }
  input[type=text], input[type=email], input[type=tel], input[type=date] { width: 100%; padding: 12px 14px; border: 1px solid #D5DCE8; border-radius: 10px; font-size: 16px; font-family: inherit; }
  input:focus { outline: 2px solid {{ $primary }}; border-color: transparent; }
  .consent { display:flex; gap:10px; align-items:flex-start; font-size: 13px; color: #5B6B85; margin-top: 14px; line-height: 1.4; }
  .consent input { margin-top: 3px; }
  button { width: 100%; margin-top: 18px; padding: 14px; border: 0; border-radius: 10px; background: {{ $primary }}; color: #fff; font-size: 16px; font-weight: bold; cursor: pointer; }
  .err { color: #D93025; font-size: 13px; margin-top: 4px; }
  .msg { margin: 8px 32px 24px; padding: 18px; border-radius: 12px; text-align: center; line-height: 1.5; }
  .ok { background: #E7F6EE; color: #0E6B37; } .wait { background: #FFF6DB; color: #7A5600; }
  .hp { position: absolute; left: -5000px; }
  footer { text-align:center; color: #8A97AD; font-size: 12px; margin-top: 18px; }
  @media (max-width: 620px) { .col { display:block !important; width:100% !important; } .form, .head { padding-left: 20px; padding-right: 20px; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    @if (($style['show_header'] ?? true) !== false)
      <div class="head">
        @if ($style['logo'] ?? $company->logo)
          <img src="{{ preg_match('#^https?://#', $style['logo'] ?? $company->logo) ? ($style['logo'] ?? $company->logo) : url($style['logo'] ?? $company->logo) }}" alt="{{ $company->name }}">
        @else
          {{ $company->name }}
        @endif
      </div>
    @endif
    @if ($rows)
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0">{!! $rows !!}</table>
    @endif

    @if ($state === 'ok' || $state === 'confirmed')
      <div class="msg ok">{{ $form->success_message ?: 'Merci ! Votre inscription est bien enregistrée.' }}</div>
    @elseif ($state === 'pending')
      <div class="msg wait">Presque terminé : nous venons de vous envoyer un e-mail. Cliquez sur le lien qu'il contient pour confirmer votre inscription.</div>
    @elseif ($form->with_form)
      <form class="form" method="post" action="{{ url('/f/'.$form->slug) }}" novalidate>
        @if ($form->kind === 'form' || $form->title)
          <h2>{{ $title }}</h2>
        @endif
        @if ($form->description)
          <p class="d">{{ $form->description }}</p>
        @endif
        @foreach ($form->fieldList() as $f)
          @php $k = $f['key']; @endphp
          <label for="f_{{ $k }}">{{ \App\Modules\Marketing\Models\Form::FIELDS[$k] }}{{ ! empty($f['required']) ? ' *' : '' }}</label>
          <input id="f_{{ $k }}" name="{{ $k }}" value="{{ $old[$k] ?? '' }}"
            type="{{ $k === 'email' ? 'email' : ($k === 'phone' ? 'tel' : ($k === 'birthday' ? 'date' : 'text')) }}"
            @if (! empty($f['required'])) required @endif
            autocomplete="{{ ['first_name' => 'given-name', 'last_name' => 'family-name', 'email' => 'email', 'phone' => 'tel', 'company_name' => 'organization', 'city' => 'address-level2', 'birthday' => 'bday'][$k] ?? 'off' }}">
          @if (! empty($errors[$k]))<div class="err">{{ $errors[$k][0] }}</div>@endif
        @endforeach
        <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        @if ($form->consent_text)
          <label class="consent"><input type="checkbox" name="consent" value="1" {{ ! empty($old['consent']) ? 'checked' : '' }}> <span>{{ $form->consent_text }}</span></label>
          @if (! empty($errors['consent']))<div class="err">{{ $errors['consent'][0] }}</div>@endif
        @endif
        <button type="submit">{{ $form->button_label ?: 'Je m\'inscris' }}</button>
      </form>
    @endif
  </div>
  <footer>{{ $company->name }} · Vos données ne sont utilisées que par {{ $company->name }} et vous pouvez vous désinscrire à tout moment.</footer>
</div>
</body>
</html>

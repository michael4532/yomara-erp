<section class="sec custom" id="contact">
  <div class="wrap grid">
    <div>
      <h2>Un besoin précis ? On construit le module pour vous.</h2>
      <p class="lead">Un module propre à votre métier, une intégration avec un autre logiciel, un rapport ou un circuit de validation particulier, une installation sur vos serveurs ou la formation de vos équipes : décrivez votre besoin, nous revenons vers vous avec une proposition.</p>
      <div class="kinds"><span>Module métier</span><span>Intégration et API</span><span>Rapports</span><span>Reprise de données</span><span>Formation</span><span>Démonstration</span></div>
      <div class="reach">
        @if($contact['phone'])<a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}"><small>Téléphone</small><b>{{ $contact['phone'] }}</b></a>@endif
        @if($contact['whatsapp'])<a href="https://wa.me/{{ preg_replace('/\D+/', '', $contact['whatsapp']) }}" target="_blank" rel="noopener"><small>WhatsApp</small><b>{{ $contact['whatsapp'] }}</b></a>@endif
        @if($contact['email'])<a href="mailto:{{ $contact['email'] }}"><small>E-mail</small><b>{{ $contact['email'] }}</b></a>@endif
        @if($contact['address'])<div><small>Adresse</small><span>{{ $contact['address'] }}</span></div>@endif
        @if($contact['hours'])<div><small>Horaires</small><span>{{ $contact['hours'] }}</span></div>@endif
      </div>
    </div>
    <form class="cform rv" method="post" action="/contact" id="formulaire">
      @csrf
      <h3>Parlons de votre projet</h3>
      <p style="margin:0;color:var(--mute);font-size:14.5px">Réponse en général sous 24 h ouvrées.</p>
      @if(session('contact_ok'))<div class="okmsg" style="margin-top:14px">Merci, votre demande est envoyée. Nous vous répondons très vite.</div>@endif
      @if($errors->any())<div class="errmsg" style="margin-top:14px">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
      <label for="c-type">Votre demande</label>
      <select id="c-type" name="type">@foreach($requestTypes as $k => $v)<option value="{{ $k }}" @selected(old('type', 'custom_module') === $k)>{{ $v }}</option>@endforeach</select>
      <div class="r2">
        <div><label for="c-name">Nom complet</label><input id="c-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"></div>
        <div><label for="c-company">Entreprise</label><input id="c-company" name="company" value="{{ old('company') }}" maxlength="160" autocomplete="organization"></div>
      </div>
      <div class="r2">
        <div><label for="c-email">E-mail</label><input id="c-email" type="email" name="email" value="{{ old('email') }}" required maxlength="160" autocomplete="email"></div>
        <div><label for="c-phone">Téléphone ou WhatsApp</label><input id="c-phone" name="phone" value="{{ old('phone') }}" maxlength="60" autocomplete="tel"></div>
      </div>
      <label for="c-budget">Budget indicatif (facultatif)</label>
      <select id="c-budget" name="budget"><option value="">Je ne sais pas encore</option>@foreach(['Moins de 500 000 FCFA', '500 000 à 2 000 000 FCFA', '2 à 5 millions FCFA', 'Plus de 5 millions FCFA'] as $b)<option @selected(old('budget') === $b)>{{ $b }}</option>@endforeach</select>
      <label for="c-msg">Décrivez votre besoin</label>
      <textarea id="c-msg" name="message" rows="5" required minlength="10" maxlength="5000" placeholder="Exemple : un module de suivi des tournées de livraison relié au stock et à la facturation.">{{ old('message') }}</textarea>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <button class="btn" type="submit">Envoyer ma demande</button>
    </form>
  </div>
</section>

/* Kostenfreie Anfrage: Kontaktdaten → Anliegen → prüfen. Keine Daten an Analytics. */
(function () {
  'use strict';
  var form = document.querySelector('form[data-wizard]');
  if (!form) return;
  var steps = Array.from(form.querySelectorAll('[data-step]'));
  var tabs = Array.from(form.querySelectorAll('[data-step-tab]'));
  var back = form.querySelector('.x-wizard__back');
  var next = form.querySelector('.x-wizard__next');
  var submit = form.querySelector('.x-wizard__submit');
  var status = form.querySelector('.x-form__status');
  var progress = form.querySelector('.x-wizard__progress');
  var current = 1, busy = false;
  var names = ['Deine Kontaktdaten', 'Dein Anliegen', 'Prüfen und senden'];
  function message(text) { status.textContent = text; status.hidden = false; status.setAttribute('role','alert'); }
  function mark(el, invalid) {
    var field = el.closest('.x-field');
    if (field) field.classList.toggle('is-invalid', invalid);
    el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
  }
  form.querySelectorAll('.x-field').forEach(function (field, i) {
    var error = field.querySelector('.x-error');
    if (!error) return;
    error.id = 'field-error-' + i;
    field.querySelectorAll('input,select,textarea').forEach(function (el) {
      el.setAttribute('aria-describedby', ((el.getAttribute('aria-describedby') || '') + ' ' + error.id).trim());
    });
  });
  function validate(n) {
    var bad = null;
    steps[n-1].querySelectorAll('input,select,textarea').forEach(function (el) {
      if (el.type === 'hidden' || el.disabled) return;
      var ok = el.checkValidity();
      if (el.required && !['radio','checkbox'].includes(el.type)) ok = ok && el.value.trim().length > 0;
      if (el.type === 'email' && el.value.trim()) ok = ok && /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(el.value.trim());
      if (el.type === 'url' && el.value.trim()) ok = ok && /^https?:\/\//i.test(el.value.trim());
      mark(el, !ok);
      if (!ok && !bad) bad = el;
    });
    return bad;
  }
  function review() {
    var box = form.querySelector('[data-review]');
    if (!box) return;
    box.replaceChildren();
    [[1,'Deine Kontaktdaten',[['vorname','Vorname'],['nachname','Nachname'],['company','Unternehmen'],['role','Rolle'],['email','E-Mail'],['phone','Telefon']]],
     [2,'Dein Anliegen',[['category','Unternehmenstyp'],['question','Deine Frage'],['linkedin','LinkedIn']]]].forEach(function (group) {
      var section=document.createElement('section'), title=document.createElement('h3');
      title.className='x-h4';title.textContent=group[1];section.append(title);
      var dl=document.createElement('dl');
      group[2].forEach(function (f) {
        var el=form.elements.namedItem(f[0]), value=el ? el.value : '';
        if (f[0]==='category') value=({versicherer:'Versicherer',maklerpool:'Maklerpool',vertrieb:'Versicherungsvertrieb',sonstiges:'Sonstiges'})[value] || value;
        if (!value) return;
        var dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=f[1];dd.textContent=value;
        if(f[0]==='email') dd.className='x-review__email';
        dl.append(dt,dd);
      });
      section.append(dl);
      var edit=document.createElement('button');edit.type='button';edit.className='x-btn x-btn--secondary';edit.textContent=group[1]+' bearbeiten';
      edit.addEventListener('click',function(){go(group[0]);});section.append(edit);box.append(section);
    });
  }
  function go(n, quiet) {
    current=n;
    steps.forEach(function(s,i){s.classList.toggle('is-active',i===n-1);});
    tabs.forEach(function(t,i){t.classList.toggle('is-active',i===n-1);t.classList.toggle('is-done',i<n-1);var b=t.querySelector('button');if(i===n-1)b.setAttribute('aria-current','step');else b.removeAttribute('aria-current');});
    back.hidden=n===1;next.hidden=n===steps.length;submit.hidden=n!==steps.length;status.hidden=true;
    if(progress) progress.textContent='Schritt '+n+' von '+steps.length+' · '+names[n-1];
    if(n===steps.length)review();
    if(!quiet){var h=steps[n-1].querySelector('.x-kicker');if(h){h.tabIndex=-1;h.focus({preventScroll:true});}(form.closest('.x-wizard')||form).scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
  }
  function advance(target) {
    if(busy)return;
    if(target>current)for(var n=current;n<target;n++){var bad=validate(n);if(bad){go(n,true);message('Bitte prüfe die markierten Felder.');bad.focus();return;}}
    go(target);
  }
  next.addEventListener('click',function(){advance(current+1);});
  back.addEventListener('click',function(){advance(current-1);});
  tabs.forEach(function(t,i){t.querySelector('button').addEventListener('click',function(){advance(i+1);});});
  form.querySelectorAll('input,textarea,select').forEach(function(el){el.addEventListener('input',function(){mark(el,false);});el.addEventListener('change',function(){mark(el,false);});});
  form.addEventListener('submit',function(ev){
    ev.preventDefault();if(busy)return;
    if(current<steps.length){advance(current+1);return;}
    for(var n=1;n<=steps.length;n++){var bad=validate(n);if(bad){go(n,true);message('Bitte prüfe die markierten Felder.');bad.focus();return;}}
    var data={};new FormData(form).forEach(function(v,k){data[k]=v;});
    data.name=((data.vorname||'')+' '+(data.nachname||'')).trim();data.privacy=form.elements.privacy.checked;
    data.edition=form.dataset.edition;data.source=location.origin+location.pathname;
    busy=true;submit.disabled=true;var label=submit.textContent;submit.textContent='Wird gesendet …';
    fetch(form.dataset.endpoint,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(data)})
    .then(function(r){return r.json().then(function(j){if(!r.ok||!j.ok){var err=new Error(j.error||'Die Anfrage konnte nicht gesendet werden.');err.fields=j.fields||[];throw err;}return j;});})
    .then(function(j){
      if(j.pay_url){var target=new URL(j.pay_url,location.href);if(target.origin!==location.origin)throw new Error('Der Buchungslink konnte nicht geöffnet werden. Bitte nutze Deine Bestätigungsmail.');location.assign(target.href);return;}
      location.assign(form.dataset.thanks);
    })
    .catch(function(err){busy=false;submit.disabled=false;submit.textContent=label;
      var first=null;(err.fields||[]).forEach(function(name){var el=form.querySelector('[name="'+name.replace(/[^a-z_]/g,'')+'"]');if(el){mark(el,true);if(!first)first=el;}});
      if(first){var step=first.closest('[data-step]');go(Number(step.dataset.step),true);first.focus();}
      message(err.fields ? err.message : 'Die Übertragung wurde nicht bestätigt. Deine Angaben bleiben erhalten. Prüfe bitte Deinen Posteingang, bevor Du erneut sendest. Bei Fragen: info@25-experts.de.');
    });
  });
  go(1,true);
})();

/* 25 EXPERTS – PayPal Smart Buttons auf zahlung.php.
   Order wird NICHT im Browser angelegt, sondern serverseitig (paypal.php?action=create) und nach der Freigabe
   serverseitig gecaptured und geprüft (paypal.php?action=capture: Betrag, Währung, Order-ID). Erst dann gilt die Zahlung. */
(function () {
  'use strict';
  var box = document.getElementById('paypal-buttons');
  var status = document.getElementById('paypal-status');
  if (!box || !window.paypal) { if (status) { status.textContent = 'PayPal konnte nicht geladen werden. Bitte zahlen Sie per Rechnung oder laden Sie die Seite neu.'; } return; }
  var token = box.getAttribute('data-token');
  var api = box.getAttribute('data-api');
  var ticketUrl = box.getAttribute('data-ticket');

  function call(action, extra) {
    var body = { action: action, t: token };
    if (extra) { for (var k in extra) { body[k] = extra[k]; } }
    return fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) { throw new Error(j && j.error ? j.error : 'HTTP ' + r.status); } return j; }); });
  }
  function say(t) { if (status) { status.textContent = t; } }

  window.paypal.Buttons({
    style: { layout: 'vertical', color: 'black', shape: 'rect', label: 'pay' },
    createOrder: function () {
      say('');
      return call('create').then(function (j) { return j.orderID; });
    },
    onApprove: function (data) {
      say('Zahlung wird geprüft …');
      return call('capture', { orderID: data.orderID }).then(function (j) {
        say('Vielen Dank, Ihre Zahlung ist eingegangen. Ihr Ticket ' + (j.ticket || '') + ' ist per E-Mail unterwegs.');
        window.setTimeout(function () { window.location.href = ticketUrl; }, 1200);
      });
    },
    onCancel: function () { say('Die PayPal-Zahlung wurde abgebrochen. Sie können es erneut versuchen oder per Rechnung zahlen.'); },
    onError: function (err) {
      say('Die PayPal-Zahlung konnte nicht abgeschlossen werden' + (err && err.message ? ' (' + err.message + ')' : '') + '. Bitte versuchen Sie es erneut oder zahlen Sie per Rechnung.');
    }
  }).render('#paypal-buttons');
})();

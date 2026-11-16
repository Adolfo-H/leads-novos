/* A24.3 — 3 visuais do Prospector. Nao toca em sessoes ou login. */
(function () {
  'use strict';

  var KEY = 'ec.prospector.appearance.v65';
  var VALID = ['light', 'system', 'dark'];
  var root = document.documentElement;
  var mode;

  function valid(value) { return VALID.indexOf(value) !== -1; }
  function read() {
    try { return window.localStorage.getItem(KEY); } catch (ignored) { return null; }
  }
  function save(value) {
    try { window.localStorage.setItem(KEY, value); } catch (ignored) { /* modo temporario */ }
  }
  function fromPreviousFlux() {
    try {
      var value = window.Flux && window.Flux.appearance;
      return valid(value) ? value : null;
    } catch (ignored) { return null; }
  }
  function apply(value, persist, notify) {
    if (!valid(value)) return;
    mode = value;
    root.setAttribute('data-ec-appearance', value);
    // Sistema e uma opcao institucional clara, NAO acompanha o SO.
    var effective = value === 'dark' ? 'dark' : 'light';
    root.classList.toggle('dark', effective === 'dark');

    try {
      if (window.Flux && window.Flux.appearance !== effective) {
        window.Flux.appearance = effective;
      }
    } catch (ignored) { /* o CSS e html.dark ainda sao atualizados */ }

    if (persist) save(value);
    if (notify) {
      window.dispatchEvent(new CustomEvent('ec-appearance-changed', {
        detail: { mode: value }
      }));
    }
  }

  // Pode ser reexecutado pelo Livewire; evita ouvintes duplicados.
  if (window.EcAppearanceV65 && window.EcAppearanceV65.version === 'A24.3') {
    window.EcAppearanceV65.sync();
    return;
  }

  var initial = read() || fromPreviousFlux() || 'system';
  apply(valid(initial) ? initial : 'system', false, false);

  window.EcAppearanceV65 = {
    version: 'A24.3',
    get: function () { return mode; },
    set: function (value) { apply(value, true, true); },
    sync: function () { apply(mode, false, false); }
  };

  document.addEventListener('DOMContentLoaded', window.EcAppearanceV65.sync);
  document.addEventListener('livewire:navigated', window.EcAppearanceV65.sync);
  window.addEventListener('pageshow', window.EcAppearanceV65.sync);
  window.addEventListener('storage', function (event) {
    if (event.key !== KEY) return;
    var next = read();
    if (valid(next)) apply(next, false, true);
  });
})();

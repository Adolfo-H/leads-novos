import test from 'node:test';
import assert from 'node:assert/strict';
import { classifyPresence } from '../presence-classifier.mjs';

test('resposta negativa explicita indica nao localizado', () => {
  assert.equal(classifyPresence([{ jid: '5511999999999@s.whatsapp.net', exists: false }]), 'not_found');
});
test('lista vazia nao comprova ausencia', () => {
  assert.equal(classifyPresence([]), 'inconclusive');
});
test('positivo prevalece, sem negativas falsas', () => {
  assert.equal(classifyPresence([{ exists: false }, { exists: true }]), 'found');
});
test('sem resposta nao vira ausencia', () => {
  assert.equal(classifyPresence(undefined), 'inconclusive');
  assert.equal(classifyPresence(null), 'inconclusive');
  assert.equal(classifyPresence({}), 'inconclusive');
  assert.equal(classifyPresence([{}]), 'inconclusive');
});

// Run: node tests/panel_presentation_test.js (no browser or database needed).
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const nodes = new Map();
function node(id) {
  if (!nodes.has(id)) {
    const classes = new Set();
    nodes.set(id, { value: '', textContent: '', dataset: {}, classList: {
      remove: c => classes.delete(c),
      toggle: (c, on) => on ? classes.add(c) : classes.delete(c),
      contains: c => classes.has(c)
    }});
  }
  return nodes.get(id);
}
const checkout = vm.createContext({ document: { getElementById: node, addEventListener() {} }, CashierApp: { formatCurrency: n => n.toFixed(2) } });
vm.runInContext(read('function/cashier/checkout-modal.js').replace('return { open };', 'return { open, co, renderTotals };'), checkout);
vm.runInContext(`Object.assign(CheckoutModal.co, { ticket: { price: 500, depositPaid: true, depositVerified: true, depositAmount: 100, status: 'In Progress' }, cart: [{price: 75, qty: 2}], tip: 25 });`, checkout);
node('cashReceivedInput').value = '600';
vm.runInContext('CheckoutModal.renderTotals()', checkout);
assert.equal(node('remainingServiceDisplay').textContent, '550.00');
assert.equal(node('totalDisplay').textContent, '575.00');
assert.equal(node('changeDueDisplay').textContent, '25.00');
assert.equal(node('btnProcessPayment').disabled, false);
node('cashReceivedInput').value = '574';
vm.runInContext('CheckoutModal.renderTotals()', checkout);
assert.equal(node('btnProcessPayment').disabled, true);
vm.runInContext("CheckoutModal.co.ticket.depositPaid = false; CheckoutModal.co.tip = 0; CheckoutModal.renderTotals()", checkout);
assert.equal(node('reservationReceivedRow').classList.contains('hidden'), false);
assert.equal(node('tipRow').classList.contains('hidden'), false);
console.log('PASS checkout deposit, products, tip, change, insufficient cash and zero rows');

// 01:00 UTC is 09:00 Manila regardless of the device's local timezone.
class FixedDate extends Date { constructor() { super('2026-11-14T01:00:00Z'); } static now() { return 1794618000000; } }
const label = node('liveLabel'), stat = node('stat');
stat.dataset = { liveStat: 'services', statFallback: 'Explore' };
let response = { ok: false };
const publicContext = vm.createContext({ Date: FixedDate, Intl, AbortController, setTimeout, clearTimeout,
  BRANCH_ID_BY_KEY: { daraga: 1 }, fetch: async () => response,
  document: { querySelectorAll: s => s === '[data-live-stat]' ? [stat] : s.startsWith('[data-live-label') ? [label] : [] }
});
const source = read('function/public/main.js');
vm.runInContext(source.slice(source.indexOf('  let publicStats = null;'), source.indexOf('  function goBranchMenu(key)')), publicContext);
const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  vm.runInContext('refreshLiveStatus()', publicContext);
  await settle();
  assert.equal(label.textContent, 'Call to confirm hours');
  assert.equal(stat.textContent, 'Explore');
  response = { ok: true, json: async () => ({ success: true, date: '2026-11-14', services: 12, branchHours: { daraga: { open: '08:00:00', close: '17:00:00' } } }) };
  vm.runInContext('publicStatsFetchedAt = 0; refreshLiveStatus()', publicContext);
  await settle();
  assert.equal(label.textContent, 'Open now until 5 PM');
  assert.equal(stat.textContent, '12');
  vm.runInContext("publicStats.branchHours.daraga.close = '08:00:00'; paintLiveStatus()", publicContext);
  assert.equal(label.textContent, 'Closed today');
  vm.runInContext("publicStats.date = '2026-11-13'; publicStats.services = -1; paintLiveStatus()", publicContext);
  assert.equal(label.textContent, 'Call to confirm hours');
  assert.equal(stat.textContent, 'Explore');
  console.log('PASS failed statistics, recovery, Manila time, closed holiday and stale/invalid data');
})().catch(error => { console.error(error); process.exitCode = 1; });

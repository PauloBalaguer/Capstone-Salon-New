/* pages/cashier/receipt.html — printable receipt lookup.
   Reads ?ref=<reference_code>&pid=<payment id> from the URL and renders
   the itemized breakdown returned by backend/cashier/receipt.php. */

async function init() {
  document.getElementById('btnPrint').addEventListener('click', () => window.print());

  const params = new URLSearchParams(window.location.search);
  const reference = params.get('ref') || '';
  const paymentId = params.get('pid') || '';

  if (!reference) {
    showNotFound();
    return;
  }

  const result = await CashierApp.get('receipt.php', { reference, paymentId });
  if (!result.success) {
    CashierApp.toast(result.message || 'Receipt not found.', 'error');
    showNotFound();
    return;
  }

  renderReceipt(result.receipt);
}

function showNotFound() {
  document.getElementById('receiptNotFound').classList.remove('hidden');
  document.getElementById('receiptCard').classList.add('hidden');
}

function renderReceipt(receipt) {
  document.getElementById('receiptCard').classList.remove('hidden');
  document.getElementById('receiptBranchName').textContent = receipt.branchName || '-';
  document.getElementById('receiptInvoiceId').textContent = receipt.invoiceId;
  document.getElementById('receiptDate').textContent = receipt.date;
  document.getElementById('receiptClientName').textContent = receipt.clientName;
  document.getElementById('receiptStylistName').textContent = receipt.stylistName || 'Unassigned';
  document.getElementById('receiptCashierName').textContent = receipt.cashierName;

  document.getElementById('receiptItemsContainer').innerHTML = receipt.items.map(item => `
    <div class="flex justify-between py-1.5">
      <div>
        <p class="font-semibold text-slate-800">${escapeHtml(item.itemName)}${item.quantity > 1 ? ` &times;${item.quantity}` : ''}</p>
        <p class="text-[9px] text-gray-400">${item.itemType}</p>
      </div>
      <span class="font-semibold text-slate-800">${CashierApp.formatCurrency(item.lineTotal)}</span>
    </div>
  `).join('') || '<p class="text-gray-400 italic py-3 text-center">No itemized lines.</p>';

  document.getElementById('receiptSubtotal').textContent = CashierApp.formatCurrency(receipt.subtotal);
  // The reservation deposit was paid before the visit, so it's deducted here --
  // otherwise Subtotal + Tip wouldn't add up to Total Paid.
  const reservation = Number(receipt.reservationReceived) || 0;
  document.getElementById('receiptReservationRow').classList.toggle('hidden', reservation <= 0);
  document.getElementById('receiptReservation').textContent = '− ' + CashierApp.formatCurrency(reservation);
  document.getElementById('receiptTip').textContent = CashierApp.formatCurrency(receipt.tip);
  document.getElementById('receiptTotal').textContent = CashierApp.formatCurrency(receipt.total);
  document.getElementById('receiptMethod').textContent = receipt.paymentMethod;

  const showCash = receipt.paymentMethod === 'Cash' && receipt.cashReceived !== null;
  document.getElementById('receiptCashRow').classList.toggle('hidden', !showCash);
  document.getElementById('receiptChangeRow').classList.toggle('hidden', !showCash);
  if (showCash) {
    document.getElementById('receiptCashReceived').textContent = CashierApp.formatCurrency(receipt.cashReceived);
    document.getElementById('receiptChange').textContent = CashierApp.formatCurrency(receipt.changeGiven || 0);
  }
}

document.addEventListener('DOMContentLoaded', init);

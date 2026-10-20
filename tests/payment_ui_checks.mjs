import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const base = new URL('../', import.meta.url);
const html = fs.readFileSync(new URL('pages/cashier/payment.html', base), 'utf8');
const elements = new Map();
for (const match of html.matchAll(/<[^>]+\bid="([^"]+)"[^>]*>/g)) {
  const classes = new Set((match[0].match(/class="([^"]*)"/)?.[1] || '').split(/\s+/));
  elements.set(match[1], {value:'',textContent:'',innerHTML:'',disabled:false,
    classList:{contains:c=>classes.has(c),add:c=>classes.add(c),remove:c=>classes.delete(c),toggle(c,on){if(on===undefined)on=!classes.has(c);on?classes.add(c):classes.delete(c);}},
    setAttribute(){},removeAttribute(){}});
}
const document = {getElementById(id){assert(elements.has(id),'missing element '+id);return elements.get(id);},querySelectorAll(){return [];},addEventListener(){}};
let count=0, finish;
const ctx=vm.createContext({document,URLSearchParams,console,
 window:{location:{search:'',href:''},confirm:()=>true},setTimeout:f=>f(),
 escapeHtml:s=>String(s??''),statusBadgeClass:()=>'',
 CashierApp:{formatCurrency:n=>'PHP '+Number(n||0).toFixed(2),toast(){},post:()=>{count++;return new Promise(r=>finish=r);}}});
const run = code => new vm.Script(code).runInContext(ctx);
run(fs.readFileSync(new URL('scripts/cashier/payment.js',base),'utf8'));
run(`state.data={bookings:[{id:'PREPAID',paymentStatus:'Fully Paid',checkoutPaid:false,status:'In Progress',price:900},{id:'SETTLED',paymentStatus:'Fully Paid',checkoutPaid:true,status:'Completed',price:900}]};renderCheckoutList();`);
assert(elements.get('checkoutTableBody').innerHTML.includes('PREPAID'));
assert(!elements.get('checkoutTableBody').innerHTML.includes('SETTLED'));
run(`state.ticket={id:'TEST',price:900,depositPaid:true,depositVerified:true,depositAmount:450,status:'In Progress'};document.getElementById('cashReceivedInput').value='450';renderTotals();`);
assert.equal(elements.get('remainingServiceDisplay').textContent,'PHP 450.00');
const first=run('processPayment()');const second=run('processPayment()');
assert.equal(count,1,'double click posts once');
assert(elements.get('payButtonLabel').textContent.startsWith('Processing checkout'));
finish({success:true,reference:'TEST',paymentId:'1'});await Promise.all([first,second]);
assert(ctx.window.location.href.includes('receipt.html?ref=TEST'));
const reportCtx=vm.createContext({Alpine:{store:()=>({branches:[{id:'daraga',name:'Test'}],bookings:[
 {id:'PAID',branchId:'daraga',date:'2030-01-01',status:'Completed',checkoutPaid:true,checkoutRevenue:900,price:900},
 {id:'UNPAID',branchId:'daraga',date:'2030-01-01',status:'Completed',checkoutPaid:false,price:900}]})}});
const report=new vm.Script(fs.readFileSync(new URL('scripts/admin/reports.js',base),'utf8')+';adminReportsApp()').runInContext(reportCtx);
report.collections=[{branchId:'daraga',branchName:'Test',amount:450,kind:'Reservation payment'},{branchId:'daraga',branchName:'Test',amount:450,kind:'Cashier checkout'}];
assert.equal(report.getReportTotalRevenue(),900,'report includes deposit once and excludes unpaid completed visits');
console.log('PASS UI checks: prepaid checkout list, balance, duplicate click, loading, receipt redirect, report revenue');

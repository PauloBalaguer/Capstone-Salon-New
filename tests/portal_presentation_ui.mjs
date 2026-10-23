import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const root=new URL('../',import.meta.url);
const context=vm.createContext({window:{},document:{addEventListener:(event,fn)=>{if(event==='alpine:init')fn();}},Alpine:{store:(_,value)=>{if(value){context.staff=value;value.refresh=async()=>{};}return context.staff;}},Date,setInterval:()=>0,clearInterval(){},console});
new vm.Script(fs.readFileSync(new URL('assets/js/portal-presentation.js',root),'utf8')).runInContext(context);
const display=context.window.PortalPresentation;
assert.equal(display.paymentStatus('Down Payment Verified'),'Deposit verified');
for(const status of ['Fully Paid','Refunded','Forfeited','Payment Required'])assert.equal(display.paymentStatus(status),status);
assert.equal(display.notificationTitle('CANCELLED'),'Booking cancelled');
assert.equal(display.notificationTitle('CUSTOM_EVENT'),'Custom event');
assert.equal(display.reservationLabel({paymentRequirement:'50% Down Payment',reservationAmount:49,reservationLabel:'Full Payment'},n=>'PHP '+n),'PHP 49 (full payment)');
new vm.Script(fs.readFileSync(new URL('assets/js/staff/shared-data.js',root),'utf8')).runInContext(context);
const staff=context.staff;
staff.notifications=[{id:1,isRead:false}];
staff.toggleNotificationPanel();assert.equal(staff.notifications[0].isRead,false);
staff.post=async()=>({success:false});staff.toast=()=>{};
await staff.markAllNotificationsAsRead();assert.equal(staff.notifications[0].isRead,false);
staff.post=async()=>({success:true});
await staff.markAllNotificationsAsRead();assert.equal(staff.notifications[0].isRead,true);
for(const role of ['admin','staff','cashier'])for(const name of fs.readdirSync(new URL('pages/'+role+'/',root)).filter(n=>n.endsWith('.html'))){
 const html=fs.readFileSync(new URL('pages/'+role+'/'+name,root),'utf8');
 assert(html.includes('assets/js/portal-presentation.js'),role+'/'+name+' shared presentation loaded');
 for(const match of html.matchAll(/(?:x-text|x-show|x-if|:disabled)="([^"]*)"/g))new vm.Script('void ('+match[1].replaceAll('&amp;','&').replaceAll('&gt;','>').replaceAll('&lt;','<')+');');
}
console.log('PASS shared display labels preserve statuses, public reservation amounts, staff read success/failure, and role page Alpine bindings.');

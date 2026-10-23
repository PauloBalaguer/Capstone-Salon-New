import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const base=new URL('../',import.meta.url);
const read=p=>fs.readFileSync(new URL(p,base),'utf8');
const pending=[];
const ctx=vm.createContext({URLSearchParams,console,fetch:()=>new Promise(resolve=>pending.push(resolve))});
new vm.Script(read('assets/js/customer/dashboard-home-service.js')).runInContext(ctx);
new vm.Script(`var app=Object.assign(customerHomeServiceMixin(),{homeServiceForm:{eventType:'Wedding',weddingPackage:'D',services:[],preferredDate:'2030-01-01',preferredTime:'10:00'}});`).runInContext(ctx);
const first=ctx.app.refreshEventAvailability();ctx.app.homeServiceForm.preferredTime='15:00';const second=ctx.app.refreshEventAvailability();
assert(ctx.app.eventAvailabilityLoading);
pending[1]({json:async()=>({success:true,available:false,message:'Schedule unavailable. Choose another time.'})});await second;
pending[0]({json:async()=>({success:true,available:true,message:'Old result'})});await first;
assert.equal(ctx.app.homeServiceAvailability,false,'stale availability cannot replace newer result');
assert.equal(ctx.app.homeServiceForm.preferredTime,'15:00','unavailable wedding is never moved automatically');
assert.equal(ctx.app.eventAvailabilityLoading,false);
ctx.fetch=async()=>{throw Error('offline');};await ctx.app.refreshEventAvailability();
assert.equal(ctx.app.homeServiceAvailability,null);assert(ctx.app.eventAvailabilityMessage.includes('check again'));
assert.equal(ctx.app.getHomeServiceStatusLabel('Payment Required'),'Awaiting Payment');
assert.equal(ctx.app.getHomeServiceStatusLabel('Pending Review'),'Pending Review');
assert.equal(ctx.app.getHomeServiceStatusLabel('Confirmed'),'Confirmed');
for(const p of ['assets/js/main.js','assets/js/customer/dashboard-home-service.js','assets/js/payment/result.js'])new vm.Script(read(p));
for(const m of read('pages/customer/home-service.html').matchAll(/(?:x-text|x-show|x-if|:aria-busy)="([^"]*)"/g))new vm.Script('void('+m[1].replaceAll('&amp;','&').replaceAll('&gt;','>').replaceAll('&lt;','<')+');');
for(const status of ['Confirmed','Payment Being Verified','Cancelled']) {
    const elements=new Map();const element=id=>{if(!elements.has(id))elements.set(id,{textContent:'',classList:{remove(){}}});return elements.get(id);};
    const resultCtx=vm.createContext({URLSearchParams,window:{location:{search:'?ref=EVENT&token=test&from=customer'}},document:{getElementById:element},
        fetch:async()=>({json:async()=>({success:true,paid:true,type:'home_service',reference:'EVENT',status,amountPaid:2000})})});
    new vm.Script(read('assets/js/payment/result.js')).runInContext(resultCtx);
    await new Promise(r=>setImmediate(r));
    const message=element('resultMessage').textContent;
    assert(message.includes(status==='Confirmed'?'request is confirmed':status==='Cancelled'?'remains cancelled':'needs staff review'));
}
console.log('PASS event availability loading/stale/network states, unchanged wedding schedule, customer status labels, payment-versus-confirmation messages, JS/Alpine syntax.');

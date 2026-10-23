import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const base = new URL('../', import.meta.url);
const read = p => fs.readFileSync(new URL(p,base),'utf8');
const events = {};
class Input {
  constructor(value='',id='') { Object.assign(this,{value,id,dataset:{},min:'',required:true,attrs:{}}); }
  getAttribute(k){return this.attrs[k] || '';}
  setAttribute(k,v){this.attrs[k]=v;}
  setCustomValidity(v){this.error=v;}
  insertAdjacentElement(_,e){this.nextElementSibling=e;}
  focus(){this.focused=true;}
}
const elements = new Map();
const get = id => {if(!elements.has(id)) elements.set(id,new Input('',id)); return elements.get(id);};
const document={head:{appendChild(){}},getElementById:get,querySelector:()=>null,
  createElement:()=>({classList:{contains:()=>true},setAttribute(){}}),
  addEventListener(k,f,capture){(events[k]??=[]).push({f,capture});}};
class FormDataStub { constructor(){this.values=[];} append(k,v){this.values.push([k,v]);} }
const ctx=vm.createContext({document,window:{location:{}},HTMLInputElement:Input,HTMLTextAreaElement:Input,FormData:FormDataStub,Promise,console});
const run = s => new vm.Script(s).runInContext(ctx);
for(const p of ['assets/js/main.js','assets/js/booking-validation.js','assets/js/customer/dashboard-booking.js']) new vm.Script(read(p));
run(read('assets/js/booking-validation.js'));
const validator=ctx.window.BookingFormValidation;
for(const [value,kind,ok] of [['','name',false],['123','name',false],["María O’Neil",'name',true],['09123456789','mobile',true],['09ABC456789','mobile',false],['a@','email',false],['2030-02-30','date',false]]) {
 assert.equal(validator.check(new Input(value),kind),ok,kind+' '+value);
}
const phone=new Input('09abc123456789','bookPhone');
events.input.find(e=>e.capture).f({target:phone});
assert.equal(phone.value,'09123456789','mobile sanitized before model update');
ctx.BookingFormValidation=validator;
let requestCount=0, rejectRequest;
ctx.fetch=()=>{requestCount++;return new Promise((_,reject)=>rejectRequest=reject);};
run(read('assets/js/customer/dashboard-booking.js'));
run(`var app=Object.assign(customerBookingMixin(),{isSubmittingBooking:false,bookingStep:'terms',
selectedBranchForServices:'main', selectedServicesForBooking:[{id:'1'}],depositLocked:true,
bookingForm:{date:'2030-01-01',time:'13:00',staffId:'1',customerName:'Mary Jane',customerPhone:'09123456789',paymentMethod:'Cash',agreedToTerms:true},
reservationQuote:{quoteToken:'test'},isSlotAvailableNow:()=>true,pushToast(){}}); app.submitBooking();app.submitBooking();`);
assert.equal(requestCount,1,'customer double click posts once');
assert(ctx.app.isSubmittingBooking);
rejectRequest(new Error('offline'));
await new Promise(resolve=>setImmediate(resolve));
assert.equal(ctx.app.isSubmittingBooking,false);
assert.equal(ctx.app.bookingForm.customerName,'Mary Jane');
assert(ctx.app.bookingError.includes('connection'));
ctx.fetch=async url=>({status:409,json:async()=>url.includes('getAvailableSlots')
  ? {success:true,slots:[{time:'13:00',available:false}]}
  : {success:false,conflict:true,message:'Selected time slot is unavailable. Please choose another schedule.'}});
ctx.URLSearchParams=URLSearchParams;
run('app.submitBooking();');
await new Promise(resolve=>setImmediate(resolve));
assert.equal(ctx.app.bookingStep,'schedule');
assert.equal(ctx.app.slotAvailabilityMap['13:00'],false);
assert.equal(ctx.app.bookingForm.time,'13:00','conflict retains schedule for correction');
ctx.fetch=()=>{requestCount++;return new Promise((_,reject)=>rejectRequest=reject);};
ctx.showToast=()=>{};
const main=read('assets/js/main.js');
run(main.slice(main.indexOf('  let guestBookingSubmitting = false;'),main.indexOf("  document.getElementById('bookingOtpForm')?.addEventListener('submit'")));
ctx.booking={name:'Mary Jane',phone:'09123456789',branchId:'1',date:'2030-01-01',time:'13:00',staffId:'1',serviceIds:['1'],serviceStaff:{1:1},email:'',depositMethod:'Cash',quoteToken:'test'};
requestCount=0;
run(`var btn=new HTMLInputElement();var guestFirst=finalizeGuestBooking(booking,'',btn,'Confirm');var guestSecond=finalizeGuestBooking(booking,'',btn,'Confirm');`);
assert.equal(requestCount,1,'guest duplicate request blocked');
rejectRequest(new Error('offline'));
await ctx.guestFirst;
assert.equal(ctx.booking.name,'Mary Jane');
assert.equal(ctx.btn.disabled,false);
assert.equal(ctx.btn.attrs['aria-busy'],'false');
assert(ctx.btn.nextElementSibling.textContent.includes('connection'));
assert(read('pages/customer/services-promos.html').includes('Final booking summary'));
assert(main.includes("['Reservation Requirement', bookReservationQuote.reservationRequirement]"));
for (const match of read('pages/customer/services-promos.html').matchAll(/(?:x-text|x-show|x-if|:aria-busy)="([^"]*)"/g)) {
  new vm.Script('void ('+match[1].replaceAll('&amp;','&').replaceAll('&gt;','>').replaceAll('&lt;','<')+');');
}
console.log('PASS: JS/Alpine syntax, inline validation, mobile sanitization, customer/guest double clicks, network recovery, conflict recovery, retained details and summary bindings.');

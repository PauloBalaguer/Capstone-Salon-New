import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const base=new URL('../',import.meta.url);
const read=p=>fs.readFileSync(new URL(p,base),'utf8');
const data=JSON.parse(read('backend/data/albay-locations.json'));
class Element {
  constructor(tag='div') {this.tag=tag;this.children=[];this.dataset={};this.attrs={};this.events={};this._value='';this.disabled=false;}
  append(...els){this.children.push(...els);}
  setAttribute(k,v){this.attrs[k]=v;}
  removeAttribute(k){delete this.attrs[k];}
  addEventListener(k,f){this.events[k]=f;}
  dispatchEvent(e){this.events[e.type]?.(e);}
  replaceChildren(...els){this.children=els;this._value=els[0]?.value||'';}
  get value(){return this._value;}
  set value(v){this._value=this.tag==='select'&&!this.children.some(o=>o.value===v)?'':v;}
  get selectedOptions(){return this.children.filter(o=>o.value===this.value);}
  querySelector(){return this.children.flatMap(c=>c.children||[]).find(e=>e.attrs?.['aria-invalid']==='true');}
  focus(){this.focused=true;}
}
const root=new Element();root.id='service-address';root.dataset={addressTarget:'target',addressSaved:'true'};
const target=new Element('input');
let ready,failCity='';
const ctx=vm.createContext({window:{},URL,console,
  document:{currentScript:{src:'http://localhost/scripts/address-form.js'},createElement:t=>new Element(t),
    getElementById:()=>target,querySelectorAll:()=>[root],addEventListener:(_,f)=>ready=f},
  Event:class {constructor(type){this.type=type;}},
  Option:class {constructor(text,value){this.textContent=text;this.value=value;}},
  fetch:async url=>{
    const level=url.searchParams.get('level'),parent=url.searchParams.get('parent');
    if(level==='barangays'&&parent===failCity)throw Error('Could not load locations. Try again.');
    return {ok:true,json:async()=>({success:true,locations:level==='provinces'?data.provinces:data[level][parent]||[]})};
  }});
const run=s=>new vm.Script(s).runInContext(ctx);
const settle=async()=>{for(let i=0;i<4;i++)await new Promise(r=>setImmediate(r));};
run(read('scripts/address-form.js'));ready();await settle();
const addressForms=ctx.window.AddressForms;
const province=data.provinces[0].code,city=data.cities[province][0].code,barangay=data.barangays[city][0].code;
const profile={address:'12 Test Street, Albay',structuredAddress:{province,city,barangay,house:'12',street:'Test Street',subdivision:'Building A',landmark:'Blue gate'}};
const original=JSON.stringify(profile);
addressForms.setSaved(profile);await settle();await addressForms.defaultService();
const choice=root.children.find(e=>e.children?.some(b=>b.textContent==='Use My Saved Address'));
const [savedButton,otherButton]=choice.children;
const controls=Object.fromEntries(root.children.find(e=>e.children?.some(w=>w.children?.some(c=>c.name==='address_province'))).children.map(w=>{const c=w.children[1];return[c.name.replace('address_',''),c];}));
assert.equal(savedButton.disabled,false);assert.equal(savedButton.attrs['aria-pressed'],'true');
assert.equal(controls.street.value,'Test Street');assert(target.value.includes(data.barangays[city][0].name));
otherButton.events.click();await settle();assert.equal(controls.street.value,'');
controls.province.value=province;controls.province.events.change();await settle();
assert.equal(controls.city.disabled,false);assert.equal(controls.barangay.disabled,true);
controls.city.value=city;controls.city.events.change();await settle();
controls.barangay.value=barangay;controls.barangay.events.change();await settle();
controls.street.value='Another Street';controls.street.events.input();
savedButton.events.click();await settle();assert.equal(controls.street.value,'Test Street');
otherButton.events.click();await settle();assert.equal(controls.street.value,'Another Street','alternate draft preserved');
assert.equal(JSON.stringify(profile),original,'saved profile unchanged');
assert(addressForms.prepare('service-address',{append(){}}));
const secondCity=data.cities[province][1].code;failCity=secondCity;
controls.city.value=secondCity;controls.city.events.change();await settle();
const retry=root.children.find(e=>e.textContent==='Retry loading locations');
assert.equal(retry.hidden,false);assert.equal(controls.street.value,'Another Street');
failCity='';retry.events.click();await settle();assert.equal(retry.hidden,true);
assert.equal(controls.street.value,'Another Street');assert.equal(controls.barangay.value,'');
addressForms.setSaved({});await settle();await addressForms.defaultService();
assert(savedButton.disabled);assert.equal(controls.street.value,'');
assert.equal(addressForms.prepare('service-address',{append(){}}),false);
addressForms.setSaved({address:'Old saved address'});await settle();await addressForms.defaultService();
assert.equal(controls.street.value,'Old saved address','legacy address reused without guessing location');
assert(savedButton.disabled);
class FormDataStub {constructor(){this.fields=[];}append(k,v){this.fields.push([k,v]);}}
ctx.FormData=FormDataStub;
ctx.BookingFormValidation={fields:()=>true};
ctx.AddressForms={prepare:()=>true,errors(){},setSaved:p=>ctx.latestSaved=p};
let calls=0,rejectRequest;
ctx.fetch=()=>{calls++;return new Promise((_,reject)=>rejectRequest=reject);};
run(read('scripts/customer/dashboard-home-service.js'));
run(`var app=Object.assign(customerHomeServiceMixin(),{isSubmittingHomeService:false,
homeServiceForm:{address:'Test Street',eventType:'Standard Home Service',services:['Hair Styling'],clients:'1',preferredDate:'2030-01-01',preferredTime:'09:00',agreedToTerms:true},pushToast(){}});app.submitHomeServiceRequest();app.submitHomeServiceRequest();`);
assert.equal(calls,1);rejectRequest(Error('offline'));await settle();
assert.equal(ctx.app.homeServiceForm.address,'Test Street');assert.equal(ctx.app.isSubmittingHomeService,false);
assert(ctx.app.homeServiceError.includes('connection'));
ctx.fetch=async()=>({status:200,json:async()=>({success:true,addressProfile:{address:'New Saved Address',structuredAddress:{province,city,barangay,street:'New Saved Street'}}})});
run(read('scripts/customer/dashboard-profile.js'));
run(`var profileApp=Object.assign(customerProfileMixin(),{customerProfile:{name:'Test Customer',email:'test@example.com',phone:'09123456789',notificationPrefs:{email:true,sms:true}},pushToast(){}});profileApp.saveProfile();`);
await settle();assert.equal(ctx.profileApp.customerProfile.address,'New Saved Address');assert.equal(ctx.latestSaved.address,'New Saved Address');
for(const file of ['scripts/customer/dashboard-state.js','scripts/customer/dashboard-home-service.js','scripts/customer/dashboard-profile.js'])new vm.Script(read(file));
for(const m of read('pages/customer/home-service.html').matchAll(/(?:x-text|x-show|x-if)="([^"]*)"/g))new vm.Script('void('+m[1].replaceAll('&amp;','&').replaceAll('&gt;','>').replaceAll('&lt;','<')+');');
console.log('PASS saved/no-saved/legacy autofill, dropdown dependencies, alternate draft switching, profile isolation, location retry, duplicate submission, failed-request retention, saved-profile refresh, JS/Alpine syntax.');

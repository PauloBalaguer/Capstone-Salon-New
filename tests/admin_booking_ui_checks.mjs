import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const root = new URL('../', import.meta.url);
const source = fs.readFileSync(new URL('assets/js/admin/appointments.js',root),'utf8');
const shared = fs.readFileSync(new URL('assets/js/admin/shared-data.js',root),'utf8');
new vm.Script(source); new vm.Script(shared);
const store={bookings:[],bookingPagination:{page:5,pageSize:10,total:95,totalPages:10},branches:[],post:async()=>({success:true}),refresh:async()=>{},getBranchName:()=> 'Daraga',exportRowsToCSV(...args){this.export=args;}};
let pending=[];
const context=vm.createContext({Alpine:{store:()=>store},ADMIN_API_BASE:'../../backend/admin/',URLSearchParams,clearTimeout,setTimeout,
 window:{location:{}},alert(){},confirm:()=>true,adminHighlightNav(){},fetch:url=>new Promise(resolve=>pending.push({url,resolve}))});
const app=new vm.Script(source+';adminBookingsApp()').runInContext(context);
assert.deepEqual(Array.from(app.pageNumbers),[3,4,5,6,7]);
app.bookingSearch=' Alice ';app.bookingFilterBranch='daraga';app.bookingFilterPayment='Fully Paid';
const first=app.loadBookings(2);const second=app.loadBookings(3);
assert(pending[0].url.includes('bookingPage=2') && pending[0].url.includes('search=Alice') && pending[0].url.includes('paymentStatus=Fully+Paid'));
pending[1].resolve({status:200,json:async()=>({success:true,bookings:[{id:'NEW'}],bookingPagination:{page:3,pageSize:10,total:95,totalPages:10}})});await second;
pending[0].resolve({status:200,json:async()=>({success:true,bookings:[{id:'OLD'}],bookingPagination:{page:2,pageSize:10,total:95,totalPages:10}})});await first;
assert.equal(store.bookings[0].id,'NEW','stale response cannot replace a newer page');
assert.equal(app.bookingLoading,false);
const count=pending.length;app.bookingDateFrom='2030-06-10';app.bookingDateTo='2030-06-01';await app.loadBookings();assert.equal(pending.length,count);assert(app.bookingError.includes('end date'));
app.resetFilters();clearTimeout(app.filterTimer);app.bookingError='';let shown=0;app.$nextTick=fn=>fn();app.$refs={bookingDetails:{showModal(){shown++;}}};
app.openBookingDetails({id:'HOME',weddingPackage:'A',requests:'Full wedding details'});assert.equal(shown,1);assert.equal(app.detailsBooking.requests,'Full wedding details');
// CSV fetches every filtered page explicitly instead of exporting only the visible ten rows.
pending=[];const exporting=app.exportBookingsToCSV();
pending[0].resolve({status:200,json:async()=>({success:true,bookings:[{id:'FIRST'}],bookingPagination:{page:1,totalPages:2}})});
await new Promise(resolve=>setTimeout(resolve,0));
assert(pending[0].url.includes('pageSize=100'));
pending[1].resolve({status:200,json:async()=>({success:true,bookings:[{id:'SECOND'}],bookingPagination:{page:2,totalPages:2}})});await exporting;
assert.equal(store.export[2].length,2);assert.equal(app.exporting,false);
// Initial refresh is paginated only on the booking management screen.
for(const page of ['appointments.html','dashboard.html']) {
 let callback,admin,calls=[];
 const location={pathname:'/pages/admin/'+page};
 const ctx=vm.createContext({document:{addEventListener(_event,fn){callback=fn;}},location,window:{location},URLSearchParams,
  console,Alpine:{store(name,value){if(value)admin=value;return admin;}},fetch:async url=>{calls.push(url);return{status:200,json:async()=>({success:true,bookings:[],branches:[],bookingPagination:{page:1,total:0,totalPages:1,pageSize:10}})};}});
 new vm.Script(shared).runInContext(ctx);callback();await new Promise(resolve=>setTimeout(resolve,0));
 assert.equal(calls[0].includes('bookingPage=1'),page==='appointments.html');
}
const html=fs.readFileSync(new URL('pages/admin/appointments.html',root),'utf8');
for(const method of ['openConfirmDeposit','openStaffAssign','refundDeposit','openHomeServiceQuote','openCollectBalance','sendBalanceLink','editHomeServiceQuote','requestHomeServiceScheduleChange','rejectHomeService','completeHomeService','openCheckoutModal']) assert(html.includes(method+'('),'preserved action '+method);
assert(html.includes('<dialog') && html.includes('aria-labelledby="bookingDetailsTitle"') && html.includes('aria-current'));
assert(html.includes('booking-mobile-list') && html.includes('booking-table'));
console.log('PASS admin UI logic: page navigation, combined query, stale response protection, date errors, details, full CSV export, shared-screen isolation, preserved actions and accessibility markup');

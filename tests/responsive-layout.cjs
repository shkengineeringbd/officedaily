const {chromium}=require('playwright');
const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'..');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const page=await browser.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const actor={id:'admin',name:'Administrator Long Name',email:'admin@example.com',role:'owner',post:'md',globalRead:true,canAssignTasks:true,canBrowseStaff:true,budgetRole:'md'};
 const tasks=[{id:'task1',title:'Review office operations and employee records',description:'Long description',date:'2026-11-23',created_at:'2026-11-23T08:00:00Z',status:'Remaining',assignee_id:'admin',assignee_name:actor.name,assigner_name:'Managing Director',assigner_post:'md',reported_today:0}];
 const state={access:{state:'approved',user:{name:actor.name,email:actor.email}},actor,today:'2026-11-23',tasks,members:[],taskAssignees:[],grants:[],scope:[],audit:[],registrations:[],pendingRegistrations:0};
 await page.route('http://office.test/**',async route=>{
  const u=new URL(route.request().url());
  if(u.pathname==='/api.php'){
   let data=state;
   if(u.searchParams.has('preview'))data={canPreview:true,active:true,selected:{id:'admin',name:'Administrator Long Name / দীর্ঘ নামের কর্মকর্তা',post:'md',branch_name:'Dhaka Main Office',profile_ready:1},users:[]};
   else if(u.searchParams.has('task'))data={task:{...tasks[0],canReview:true,canEdit:true},reports:[],comments:[],statusHistory:[],today:state.today};
   else if(u.searchParams.has('attendanceSelfie'))data={today:state.today,serverTime:'2026-11-23T08:00:00Z',readOnly:false,account:actor,attendance:null,entries:[],photos:{in:null,out:null}};
   return route.fulfill({json:data});
  }
  let file=path.join(root,u.pathname==='/'?'index.php':u.pathname.slice(1));
  if(!fs.existsSync(file))return route.fulfill({status:404,body:'Missing'});
  let body=fs.readFileSync(file);
  if(file.endsWith('.php'))body=body.toString().replace(/<\?php[\s\S]*?\?>/g,'').replace(/<\?=[\s\S]*?\?>/g,'qa');
  return route.fulfill({body,contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':file.endsWith('.svg')?'image/svg+xml':'text/html'});
 });

 const assert=require('node:assert/strict');
 async function checkWidth(label,width,height){
  await page.setViewportSize({width,height});await page.waitForTimeout(120);
  const dimensions=await page.evaluate(()=>({viewport:innerWidth,body:document.documentElement.scrollWidth}));
  if(dimensions.body>width+1){console.log('OVERFLOW',label,width,await page.evaluate(()=>[...document.querySelectorAll('body *')].map(e=>({tag:e.tagName,cls:e.className,right:Math.round(e.getBoundingClientRect().right),width:Math.round(e.getBoundingClientRect().width)})).filter(e=>e.right>innerWidth+1).slice(0,25)));await page.screenshot({path:'responsive-failure.png',fullPage:true});}
  assert.ok(dimensions.body<=width+1,`${label} ${width}: page overflow ${JSON.stringify(dimensions)}`);
 }
 const widths=[320,360,390,600,768,820,900,1024,1280,1440,1920];
 await page.goto('http://office.test/?view=tasks');await page.waitForSelector('.shell');
 for(const width of widths){
  await checkWidth('workspace',width,900);
  const expected=width<=900;
  assert.equal(await page.locator('.mobile-menu').isVisible(),expected,`menu ${width}`);
  const heights=await page.evaluate(()=>({bar:document.querySelector('.role-preview-bar').getBoundingClientRect().height,padding:parseFloat(getComputedStyle(document.body).paddingTop)}));
  assert.ok(heights.padding>=heights.bar,`preview overlaps workspace at ${width}`);
 }
 await page.setViewportSize({width:390,height:844});
 await page.locator('.mobile-menu').click();assert.ok(await page.locator('.sidebar').evaluate(e=>e.getBoundingClientRect().left>=-1));
 await page.locator('.scrim').click();
 await page.locator('.task-link').first().click();await page.waitForSelector('.drawer');
 for(const width of [320,390,768])await checkWidth('task drawer',width,568);
 await page.screenshot({path:'responsive-phone.png',fullPage:true});
 await page.goto('http://office.test/?view=tasks');await page.waitForSelector('.shell');
 await page.evaluate(()=>{document.querySelector('.main>main').innerHTML=`<div class="hr-desk"><div class="hr-tabs">${['Profile','Attendance records','Salary and payroll','Leave requests','Recovery'].map(t=>`<button class="btn"><span class="hr-tab-icon">●</span>${t}</button>`).join('')}</div><label class="hr-user-picker">Employee<select><option>Employee with a long name / দীর্ঘ নাম</option></select></label><div class="hr-metrics">${['Attendance','Salary','Leave','Records'].map(t=>`<div><small>${t}</small><strong>123456789</strong></div>`).join('')}</div><section class="hr-panel panel"><div class="panel-title"><h2>Salary and attendance</h2><button class="btn">Download salary record</button></div><form class="hr-form-grid"><label>Employee name<input value="Employee name"></label><label>Payment amount<input type="number" value="25000"></label></form><div class="table-scroll"><table><thead><tr><th>Employee</th><th>Salary</th><th>Attendance</th><th>Department</th></tr></thead><tbody><tr><td>Employee long name</td><td>25000</td><td>Present</td><td>Operations</td></tr></tbody></table></div></section><section class="budget-desk"><div class="budget-process">${['Request','Review','Approval','Release'].map(t=>`<div class="budget-step"><strong>${t}</strong><small>Pending review</small></div>`).join('')}</div><div class="budget-filter-grid"><label>From<input type="date"></label><label>To<input type="date"></label><label>Branch<select><option>Dhaka branch</option></select></label></div></section></div>`});
 for(const width of widths){await checkWidth('people salary budget',width,900);const table=await page.locator('.table-scroll').evaluate(e=>({width:e.clientWidth,scroll:e.scrollWidth}));if(width<600)assert.ok(table.scroll>table.width,'table should scroll within its own container')}
 await page.setViewportSize({width:844,height:390});await checkWidth('landscape',844,390);
 await page.locator('.role-preview-menu>summary').click();
 const menu=await page.locator('.role-preview-panel').boundingBox();assert.ok(menu.x>=0&&menu.x+menu.width<=844&&menu.y+menu.height<=390,'preview panel fits landscape screen');
 await page.goto('http://office.test/attendance.php');
 // Display the real page markup without depending on authenticated attendance data.
 await page.evaluate(()=>{document.querySelector('#attendance-content').hidden=false});
 for(const width of widths)await checkWidth('attendance',width,900);
 // Representative profile data using the production classes and stylesheet.
 await page.goto('http://office.test/profile-settings.php');
 await page.evaluate(()=>{const card=document.querySelector('#profile-contact');card.hidden=false;card.innerHTML='<h2>Personal details</h2><div class="profile-personal"><div class="profile-avatar"><div class="profile-initial">AB</div></div><form class="profile-fields"><label>Email<input value="averylongemailaddress@example.com"></label><label>Phone<input value="01700000000"></label><label>Current password<input type="password"></label><div class="profile-actions"><button class="btn primary">Save changes</button></div></form></div>'});
 for(const width of widths)await checkWidth('profile',width,900);
 await page.goto('http://office.test/operations.php');
 await page.evaluate(()=>{const p=document.querySelector('#operations-notices');p.hidden=false;p.innerHTML='<h2>Office management</h2><form><label>Title<input value="Office announcement"></label><label>Details<textarea>Team meeting</textarea></label><button class="btn primary">Save notice</button></form>'});
 for(const width of widths)await checkWidth('operations',width,900);
 assert.deepEqual(errors,[]);
 console.log('PASS: workspace, preview bar, menu, task drawer, attendance, profile and operations across 320–1920px.');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});

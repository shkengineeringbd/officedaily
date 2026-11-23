const $ = id => document.getElementById(id);
let state, stream, busy=false, expiryTimer;
const csrf=document.querySelector('meta[name="csrf-token"]').content;
const time=value=>value?new Intl.DateTimeFormat('bn-BD',{timeZone:'Asia/Dhaka',hour:'numeric',minute:'2-digit',second:'2-digit'}).format(new Date(value)):'—';
const text=(id,value)=>$(id).textContent=value;
async function request(body,month='') {
    const response=await fetch(body?'./api.php':'./api.php?attendanceSelfie=state'+(month?'&month='+encodeURIComponent(month):''),body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{cache:'no-store'});
    const data=await response.json();if(!response.ok) throw new Error(data.error||'হাজিরা সংরক্ষণ করা যায়নি।');return data;
}
function stopCamera(){if(stream)stream.getTracks().forEach(track=>track.stop());stream=null;$('attendance-video').srcObject=null;$('attendance-capture').hidden=true;$('attendance-submit').disabled=true;}
function controls(){
    $('attendance-camera').hidden=!!state?.current||!!state?.readOnly;
    $('attendance-camera').disabled=busy;
    $('attendance-checkout').hidden=!state?.current?.check_in||!!state?.current?.check_out||!!state?.readOnly;
    $('attendance-checkout').disabled=busy;
    $('attendance-month').disabled=busy;
    $('attendance-cancel').disabled=busy;
}
async function load(month=''){
    const data=await request(null,month);state=data;
    $('attendance-content').hidden=false;
    text('attendance-person',data.name);text('attendance-date',data.today+' · বাংলাদেশ সময়');
    text('attendance-in',time(data.current?.check_in));text('attendance-out',time(data.current?.check_out));text('attendance-status',data.current?.status||'এখনো হাজিরা দেওয়া হয়নি');
    $('attendance-readonly').hidden=!data.readOnly;$('attendance-month').value=data.month;
    $('attendance-rows').replaceChildren();
    for(const entry of data.entries){const tr=document.createElement('tr');for(const value of [entry.work_date,entry.status,time(entry.check_in),time(entry.check_out)]){const td=document.createElement('td');td.textContent=value;tr.append(td);}$('attendance-rows').append(tr);}
    if(!data.entries.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=4;td.textContent='এই মাসে হাজিরার রেকর্ড নেই।';tr.append(td);$('attendance-rows').append(tr);}
    clearTimeout(expiryTimer);$('attendance-photo').hidden=!data.photo;
    if(data.photo){$('attendance-photo-image').src='./api.php?attendanceSelfie=photo&date='+encodeURIComponent(data.today);text('attendance-photo-caption','সেলফি জমা: '+time(data.photo.captured_at)+' · ২৪ ঘণ্টা পরে ছবির মেয়াদ শেষ হবে।');
        const ttl=data.photo.expires_at*1000-Date.parse(data.serverTime);expiryTimer=setTimeout(()=>{$('attendance-photo').hidden=true;$('attendance-photo-image').removeAttribute('src');},Math.max(0,ttl));
    }else $('attendance-photo-image').removeAttribute('src');
    controls();
}
$('attendance-camera').addEventListener('click',async()=>{
    if(busy||state?.readOnly)return;
    busy=true;controls();text('attendance-message','');
    try{
        if(!navigator.mediaDevices?.getUserMedia)throw new Error('এই ব্রাউজারে ক্যামেরা পাওয়া যাচ্ছে না। HTTPS-এ Chrome বা Safari দিয়ে খুলুন।');
        stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
        if(document.hidden){stopCamera();throw new Error('এই পেজে ফিরে আবার ক্যামেরা খুলুন।');}
        const video=$('attendance-video');video.srcObject=stream;$('attendance-capture').hidden=false;await video.play();
        $('attendance-submit').disabled=false;
    }catch(error){stopCamera();text('attendance-message',error.name==='NotAllowedError'?'সেলফির জন্য ব্রাউজারে ক্যামেরার অনুমতি দিন।':error.message);}
    finally{busy=false;controls();}
});
$('attendance-submit').addEventListener('click',async()=>{
    if(busy||!stream||state?.readOnly)return;
    busy=true;controls();$('attendance-submit').disabled=true;text('attendance-message','সেলফি ও হাজিরা সংরক্ষণ হচ্ছে…');
    try{
        const video=$('attendance-video');if(video.readyState<2||!video.videoWidth)throw new Error('ক্যামেরা প্রস্তুত হয়নি। আবার চেষ্টা করুন।');
        const canvas=document.createElement('canvas');const scale=Math.min(1,640/Math.max(video.videoWidth,video.videoHeight));canvas.width=Math.round(video.videoWidth*scale);canvas.height=Math.round(video.videoHeight*scale);canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
        const image=canvas.toDataURL('image/jpeg',0.78);stopCamera();
        const challenge=await request({action:'attendance_selfie_begin'});
        await request({action:'attendance_selfie_checkin',token:challenge.token,image});
        await load();text('attendance-message','সেলফি দিয়ে আজকের চেক-ইন সম্পন্ন হয়েছে।');
    }catch(error){stopCamera();text('attendance-message',error.message+' রেকর্ডটি দেখতে পৃষ্ঠা রিফ্রেশ করতে পারেন।');}
    finally{busy=false;controls();}
});
$('attendance-cancel').addEventListener('click',()=>{stopCamera();controls();});
$('attendance-checkout').addEventListener('click',async()=>{
    if(busy||state?.readOnly)return;busy=true;controls();
    try{await request({action:'attendance',date:state.today,mode:'out'});await load($('attendance-month').value);text('attendance-message','চেক-আউট সম্পন্ন হয়েছে।');}catch(error){text('attendance-message',error.message);}finally{busy=false;controls();}
});
$('attendance-month').addEventListener('change',async()=>{stopCamera();try{await load($('attendance-month').value);text('attendance-message','');}catch(error){text('attendance-message',error.message);}});
window.addEventListener('pagehide',stopCamera);document.addEventListener('visibilitychange',()=>{if(document.hidden)stopCamera();});
load().then(()=>text('attendance-message','')).catch(error=>text('attendance-message',error.message+' Workspace-এ ফিরে লগইন ও প্রোফাইল অনুমোদন যাচাই করুন।'));

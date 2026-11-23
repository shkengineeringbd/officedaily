const csrf = document.querySelector('meta[name=csrf-token]');
const roleLabels = {staff:['Staff','স্টাফ'],officer:['Officer','অফিসার'],gm:['GM','জিএম'],agm:['AGM','এজিএম'],branch_manager:['Branch Manager','ব্রাঞ্চ ম্যানেজার'],cfo:['CFO','সিএফও'],md:['MD','এমডি']};
const bn = () => document.documentElement.lang === 'bn';
const t = (en, bangla) => bn() ? bangla : en;
function el(tag,text,cls) { const node=document.createElement(tag); if(text!==undefined) node.textContent=text; if(cls) node.className=cls; return node; }
async function api(body) {
    const response=await fetch(body?'./api.php':'./api.php?preview=1',body?{
        method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf.content},body:JSON.stringify(body),
    }:{cache:'no-store'});
    const result=await response.json(); if(!response.ok) throw new Error(result.error||t('Preview failed.','প্রিভিউ চালু হয়নি।')); return result;
}
async function mount() {
    if (!csrf) return;
    const state=await api(); if(!state.canPreview) return;
    const css=el('link'); css.rel='stylesheet'; css.href='./assets/role-preview.css'; document.head.append(css);
    document.body.classList.add('has-role-preview');
    const bar=el('section',undefined,'role-preview-bar'); bar.setAttribute('aria-label',t('Admin view preview','অ্যাডমিন ভিউ প্রিভিউ'));
    const title=el('strong',t('Admin Preview','অ্যাডমিন প্রিভিউ'));
    const indicator=el('span',state.active ? (state.selected ? [state.selected.name,roleLabels[state.selected.post]?.[bn()?1:0]||state.selected.post,state.selected.branch_name].join(' · ') : t('Preview user unavailable','প্রিভিউ ইউজার পাওয়া যাচ্ছে না')) : t('Administrator view','অ্যাডমিন ভিউ'),'role-preview-indicator');
    const menu=el('details',undefined,'role-preview-menu');
    const toggle=el('summary',t('Preview','প্রিভিউ')); menu.append(toggle);
    const panel=el('div',undefined,'role-preview-panel');
    panel.append(el('p',t('Choose a role and user to see their actual view.','পদ ও ইউজার বেছে তাঁর আসল ভিউ দেখুন।')));
    const form=el('form');
    const role=el('select'); role.setAttribute('aria-label',t('Role','পদ'));
    const prompt=el('option',t('Select role','পদ বেছে নিন')); prompt.value=''; role.append(prompt);
    const ordered=[...Object.keys(roleLabels),...new Set(state.users.map(user=>user.post).filter(post=>!roleLabels[post]))];
    for(const post of ordered) {
        const available=state.users.some(user=>user.post===post);
        const option=el('option',(roleLabels[post]?.[bn()?1:0]||post)+(available?'':t(' — no users',' — ইউজার নেই')));
        option.value=post; option.disabled=!available; role.append(option);
    }
    const user=el('select'); user.required=true; user.setAttribute('aria-label',t('User and branch','ইউজার ও ব্রাঞ্চ'));
    function fillUsers() {
        user.replaceChildren();
        for(const entry of state.users.filter(entry=>entry.post===role.value)) {
            const option=el('option',entry.name+' · '+entry.branch_name); option.value=entry.id; user.append(option);
        }
        user.disabled=!user.options.length; view.disabled=!user.options.length;
    }
    role.addEventListener('change',fillUsers);
    const roleLabel=el('label',t('Role','পদ')); roleLabel.append(role);
    const userLabel=el('label',t('User / Branch','ইউজার / ব্রাঞ্চ')); userLabel.append(user);
    const view=el('button',t('View preview','প্রিভিউ দেখুন')); view.type='submit';
    const error=el('p',undefined,'role-preview-error'); error.setAttribute('role','alert');
    form.append(roleLabel,userLabel,view); panel.append(form,error); menu.append(panel);
    form.addEventListener('submit',async event=>{
        event.preventDefault(); view.disabled=true; error.textContent='';
        try { await api({action:'preview_start',accountId:user.value}); location.assign('./'); }
        catch(failure) { error.textContent=failure.message; view.disabled=false; }
    });
    bar.append(title,indicator,menu);
    if(state.active) {
        bar.classList.add('is-preview');
        const note=el('span',t('Read only','শুধু দেখা'),'role-preview-note');
        const stop=el('button',t('Back to Admin','অ্যাডমিনে ফিরুন')); stop.type='button';
        stop.addEventListener('click',async()=>{
            stop.disabled=true;
            try { await api({action:'preview_stop'}); location.assign('./'); }
            catch(failure) { error.textContent=failure.message; menu.open=true; stop.disabled=false; }
        });
        bar.append(note,stop);
    }
    document.body.prepend(bar);
    if(state.selected) role.value=state.selected.post;
    fillUsers(); if(state.selected) user.value=state.selected.id;
    new MutationObserver(()=>{
        toggle.textContent=t('Preview','প্রিভিউ'); title.textContent=t('Admin Preview','অ্যাডমিন প্রিভিউ');
    }).observe(document.documentElement,{attributes:true,attributeFilter:['lang']});
}
mount().catch(()=>{});

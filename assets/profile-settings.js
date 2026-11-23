const message = document.querySelector('#profile-message');
const accountId = new URLSearchParams(location.search).get('accountId');
const endpoint = './api.php?profileSettings=1' + (accountId ? '&accountId=' + encodeURIComponent(accountId) : '');
let language = 'en', state;
try { language = localStorage.getItem('office-daily-language') === 'bn' ? 'bn' : 'en'; } catch {}
const t = (en, bn) => language === 'bn' ? bn : en;
function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
}
function field(form, name, label, value = '', type = 'text', required = true, maxLength = 100) {
    const container = element('label', label);
    const input = element('input');
    Object.assign(input, { name, type, required, maxLength, value: value ?? '' });
    container.append(input); form.append(container); return input;
}
async function request(body) {
    const response = await fetch(body ? './api.php' : endpoint, body ? {
        method: 'POST', headers: {'Content-Type':'application/json', 'X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},
        body: JSON.stringify(body),
    } : {cache:'no-store'});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || t('Request failed.', 'অনুরোধ সম্পন্ন হয়নি।'));
    return result;
}
function onSave(form, label, save) {
    const actions = element('div', undefined, 'profile-actions');
    const cancel = element('button',t('Cancel','বাতিল'),'btn'); cancel.type = 'button';
    cancel.addEventListener('click', () => { if (state) render(); });
    const button = element('button',label,'btn primary'); button.type = 'submit';
    actions.append(cancel,button); form.append(actions);
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (button.disabled) return;
        button.disabled = true; cancel.disabled = true; message.textContent = '';
        try { await save(Object.fromEntries(new FormData(form))); await refresh(); message.textContent = t('Changes saved.','পরিবর্তন সংরক্ষণ হয়েছে।'); }
        catch (error) { message.textContent = error.message; }
        finally { button.disabled = false; cancel.disabled = false; }
    });
}
function heading(section, en, bn) { section.replaceChildren(element('h2',t(en,bn))); }
function renderContact(data) {
    const {current,isSelf,assignment} = data;
    const section = document.querySelector('#profile-contact');
    heading(section,'Personal Details','ব্যক্তিগত তথ্য');
    const layout = element('div',undefined,'profile-personal');
    const avatar = element('div',undefined,'profile-avatar');
    if (current.photo_id) {
        const preview = element('img'); preview.src = './api.php?staff=file&id=' + encodeURIComponent(current.photo_id);
        preview.alt = t('Profile photo','প্রোফাইল ছবি'); avatar.append(preview);
    } else avatar.append(element('div',(current.full_name || current.name || '?').slice(0,1),'profile-initial'));
    if (isSelf && current.photo_id) {
        const photo = element('form');
        const file = field(photo,'photo',t('Change photo · max 1 MB','ছবি পরিবর্তন · সর্বোচ্চ ১ MB'),'','file'); file.accept = 'image/jpeg,image/png,image/webp';
        onSave(photo,t('Save photo','ছবি সংরক্ষণ'),async () => {
            const selected = file.files[0];
            if (!selected || selected.size > 1048576) throw new Error(t('Choose an image up to 1 MB.','সর্বোচ্চ ১ MB-এর ছবি দিন।'));
            const image = await new Promise((resolve,reject) => {
                const reader = new FileReader(); reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error(t('Unable to read image.','ছবিটি পড়া যায়নি।'))); reader.readAsDataURL(selected);
            });
            await request({action:'profile_photo_update',photo:image,expectedPhotoId:current.photo_id});
        }); avatar.append(photo);
    } else if (isSelf) {
        const link = element('a',t('Add your first photo in My Profile','প্রথম ছবি আমার প্রোফাইলে যোগ করুন'),'btn'); link.href = './'; avatar.append(link);
    }
    const contact = element('form',undefined,'profile-fields');
    field(contact,'name',t('Full name','পূর্ণ নাম'),current.full_name || current.name).readOnly = true;
    const posts = {officer:'Officer',staff:'Staff',branch_manager:'Branch Manager',gm:'GM',cfo:'CFO',md:'MD',admin:'Admin'};
    field(contact,'designation',t('Designation','পদবি'),current.designation || posts[assignment?.post] || 'Admin').readOnly = true;
    field(contact,'branch',t('Branch','ব্রাঞ্চ'),assignment?.branch_name || '—').readOnly = true;
    field(contact,'email',t('Email','ইমেইল'),current.email,'email',true,254).readOnly = !isSelf;
    field(contact,'phone',t('Phone','মোবাইল নম্বর'),current.phone,'tel',false,30).readOnly = !isSelf;
    const nid = element('div',undefined,'profile-nid'); nid.append(element('span',t('NID','জাতীয় পরিচয়পত্র')));
    if (current.nid_id) { const link = element('a',t('View / download NID','NID দেখুন / ডাউনলোড')); link.href = './api.php?staff=file&id=' + encodeURIComponent(current.nid_id); nid.append(link); }
    else nid.append(element('p',t('Not submitted','জমা দেওয়া হয়নি')));
    contact.append(nid);
    if (isSelf) {
        contact.append(element('p',t('Current password required to change email or phone.','ইমেইল বা মোবাইল বদলাতে বর্তমান পাসওয়ার্ড প্রয়োজন।'),'profile-notice'));
        const password = field(contact,'password',t('Current password','বর্তমান পাসওয়ার্ড'),'','password',true,72); password.autocomplete = 'current-password';
        password.parentElement.className = 'profile-wide';
        onSave(contact,t('Save Changes','পরিবর্তন সংরক্ষণ'),values => request({action:'profile_contact_update',email:values.email,phone:values.phone,password:values.password}));
    } else contact.append(element('p',t('Management view · read only','ম্যানেজমেন্ট ভিউ · শুধু দেখার অনুমতি'),'profile-notice'));
    layout.append(avatar,contact); section.append(layout); section.hidden = false;
}
function paymentForm(kind, entry) {
    const form = element('form',undefined,'profile-fields'); const details = entry?.details || {};
    if (kind === 'bank') {
        field(form,'bank',t('Bank name','ব্যাংকের নাম'),details.bank);
        field(form,'branch',t('Branch / address','ব্রাঞ্চ / ঠিকানা'),details.branch,'text',true,150);
        field(form,'holder',t('Account holder','অ্যাকাউন্টধারীর নাম'),details.holder);
        field(form,'number',t('Account number','অ্যাকাউন্ট নম্বর'),details.number,'text',true,64);
        field(form,'routing',t('Routing number (optional)','রাউটিং নম্বর (ঐচ্ছিক)'),details.routing,'text',false,32);
    } else {
        field(form,'mobile',t('bKash mobile number','bKash নম্বর'),details.mobile,'tel',true,30);
        field(form,'holder',t('Account holder (optional)','অ্যাকাউন্টধারীর নাম (ঐচ্ছিক)'),details.holder,'text',false);
    }
    onSave(form,entry ? t('Save Changes','পরিবর্তন সংরক্ষণ') : t('Add account','অ্যাকাউন্ট যোগ করুন'),values => request({action:'profile_payment_save',kind,...entry ? {id:entry.id,revision:entry.revision} : {},...values}));
    return form;
}
function renderPayments(data) {
    const section = document.querySelector('#profile-payment'); heading(section,'Payment Details','পেমেন্ট তথ্য');
    if (!data.payments.length) section.append(element('p',t('No bank or bKash account has been added.','কোনো ব্যাংক বা bKash অ্যাকাউন্ট যোগ করা হয়নি।'),'profile-empty'));
    for (const entry of data.payments) {
        const panel = element('details',undefined,'profile-payment-row'); panel.open = true;
        panel.append(element('summary',entry.kind === 'bank' ? entry.details.bank + ' · ' + entry.details.branch : 'bKash · ' + entry.details.mobile));
        if (data.isSelf) {
            panel.append(paymentForm(entry.kind,entry));
            const remove = element('button',t('Remove account','অ্যাকাউন্ট সরান'),'profile-remove'); remove.type = 'button';
            remove.addEventListener('click', async () => {
                if (!confirm(t('Remove this payment account?','এই পেমেন্ট অ্যাকাউন্ট সরাবেন?'))) return;
                remove.disabled = true;
                try { await request({action:'profile_payment_remove',id:entry.id,revision:entry.revision}); await refresh(); }
                catch (error) { message.textContent = error.message; remove.disabled = false; }
            }); panel.append(remove);
        } else {
            const list = element('dl',undefined,'profile-payment-values');
            const labels = entry.kind === 'bank' ? [['bank',t('Bank name','ব্যাংকের নাম')],['branch',t('Branch / address','ব্রাঞ্চ / ঠিকানা')],['holder',t('Account holder','অ্যাকাউন্টধারীর নাম')],['number',t('Account number','অ্যাকাউন্ট নম্বর')],['routing',t('Routing number','রাউটিং নম্বর')]] : [['mobile',t('bKash number','bKash নম্বর')],['holder',t('Account holder','অ্যাকাউন্টধারীর নাম')]];
            for (const [key,label] of labels) { const cell = element('div'); cell.append(element('dt',label),element('dd',entry.details[key] || '—')); list.append(cell); }
            panel.append(list);
        }
        section.append(panel);
    }
    if (data.isSelf) for (const [kind,label] of [['bank',t('+ Add bank account','+ ব্যাংক অ্যাকাউন্ট যোগ করুন')],['bkash',t('+ Add bKash number','+ bKash যোগ করুন')]]) {
        const panel = element('details',undefined,'profile-add-account'); panel.append(element('summary',label),paymentForm(kind)); section.append(panel);
    }
    section.hidden = false;
}
function renderHistory(data) {
    const section = document.querySelector('#profile-history'); section.hidden = !data.canHistory;
    if (!data.canHistory) { section.replaceChildren(); return; }
    const details = element('details'); details.append(element('summary',t('Change history · Previous NID, email and phone records','পরিবর্তনের ইতিহাস · পুরোনো NID, ইমেইল ও মোবাইল')));
    const list = element('ul');
    for (const entry of data.contacts) list.append(element('li',(entry.kind === 'email' ? t('Previous email','পুরোনো ইমেইল') : t('Previous phone','পুরোনো মোবাইল')) + ' · ' + entry.value + ' · ' + dateLabel(entry.changedAt)));
    for (const entry of data.nids) {
        const item = element('li'), link = element('a',t('Download previous NID','পুরোনো NID ডাউনলোড'));
        link.href = './api.php?staff=file&id=' + encodeURIComponent(entry.id); item.append(link,' · ' + dateLabel(entry.created_at)); list.append(item);
    }
    details.append(list.children.length ? list : element('p',t('No previous records.','পুরোনো তথ্য নেই।'))); section.replaceChildren(details);
}
function dateLabel(value) { return new Intl.DateTimeFormat(language === 'bn' ? 'bn-BD' : 'en-GB',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Dhaka'}).format(new Date(value)); }
function render() {
    document.documentElement.lang = language;
    document.querySelectorAll('[data-bn]').forEach(node => { if (!node.dataset.en) node.dataset.en = node.textContent; node.textContent = language === 'bn' ? node.dataset.bn : node.dataset.en; });
    document.querySelectorAll('[data-language]').forEach(button => button.setAttribute('aria-pressed',String(button.dataset.language === language)));
    renderContact(state); renderPayments(state); renderHistory(state);
}
for (const button of document.querySelectorAll('[data-language]')) button.addEventListener('click',() => {
    if (state && !confirm(t('Switch language? Unsaved form edits will be cleared.','ভাষা পরিবর্তন করবেন? সংরক্ষণ না করা তথ্য মুছে যাবে।'))) return;
    language = button.dataset.language; try { localStorage.setItem('office-daily-language',language); } catch {} if (state) render();
});
async function refresh() { state = await request(); render(); }
refresh().then(() => { message.textContent = ''; }).catch(error => { message.textContent = error.message; });

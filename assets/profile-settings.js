const message = document.querySelector('#profile-message');
const accountId = new URLSearchParams(location.search).get('accountId');
const endpoint = './api.php?profileSettings=1' + (accountId ? '&accountId=' + encodeURIComponent(accountId) : '');

function element(tag, text) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
}

function field(form, name, label, value = '', type = 'text', required = true, maxLength = 100) {
    const container = element('label', label);
    const input = element('input');
    Object.assign(input, { name, type, required, maxLength, value });
    container.append(input);
    form.append(container);
    return input;
}

async function request(body) {
    const response = await fetch(body ? './api.php' : endpoint, body ? {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content,
        },
        body: JSON.stringify(body),
    } : undefined);
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'অনুরোধ সম্পন্ন হয়নি।');
    return result;
}

function onSave(form, label, save) {
    const button = element('button', label);
    button.className = 'btn primary';
    form.append(button);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        button.disabled = true;
        message.textContent = '';
        try {
            await save(Object.fromEntries(new FormData(form)));
            await refresh();
            message.textContent = 'পরিবর্তন সংরক্ষণ হয়েছে।';
        } catch (error) {
            message.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
}

function renderContact(current) {
    const section = document.querySelector('#profile-contact');
    const contact = element('form');
    contact.className = 'hr-form-grid';
    field(contact, 'email', 'ইমেইল', current.email, 'email', true, 254);
    field(contact, 'phone', 'মোবাইল নম্বর', current.phone || '', 'tel', false, 30);
    field(contact, 'password', 'বর্তমান পাসওয়ার্ড', '', 'password', true, 72).autocomplete = 'current-password';
    onSave(contact, 'যোগাযোগের তথ্য সংরক্ষণ করুন', values => request({action:'profile_contact_update', ...values}));
    section.replaceChildren(element('h2','ছবি ও যোগাযোগের তথ্য'),contact);
    if (current.photo_id) {
        const photo = element('form');
        photo.className = 'hr-form-grid';
        const preview = element('img');
        preview.src = './api.php?staff=file&id=' + encodeURIComponent(current.photo_id);
        preview.alt = 'বর্তমান প্রোফাইল ছবি';
        preview.width = 72;
        photo.append(preview);
        const file = field(photo, 'photo', 'নতুন ছবি (সর্বোচ্চ ১ MB)', '', 'file');
        file.accept = 'image/jpeg,image/png,image/webp';
        onSave(photo, 'ছবি পরিবর্তন করুন', async () => {
            const selected = file.files[0];
            if (!selected || selected.size > 1048576) throw new Error('সর্বোচ্চ ১ MB-এর ছবি দিন।');
            const data = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error('ছবিটি পড়া যায়নি।'));
                reader.readAsDataURL(selected);
            });
            await request({action:'profile_photo_update', photo:data, expectedPhotoId:current.photo_id});
        });
        section.append(photo);
    } else {
        section.append(element('p','প্রথম ছবি যোগ করতে Office Daily-তে ব্যক্তিগত প্রোফাইল জমা দিন।'));
    }
    section.hidden = false;
}

function paymentForm(kind, entry) {
    const form = element('form');
    form.className = 'hr-form-grid';
    const details = entry?.details || {};
    if (kind === 'bank') {
        field(form,'bank','ব্যাংকের নাম',details.bank);
        field(form,'branch','ব্রাঞ্চ / ঠিকানা',details.branch,'text',true,150);
        field(form,'holder','অ্যাকাউন্টধারীর নাম',details.holder);
        field(form,'number','অ্যাকাউন্ট নম্বর',details.number,'text',true,64);
        field(form,'routing','রাউটিং নম্বর (ঐচ্ছিক)',details.routing,'text',false,32);
    } else {
        field(form,'mobile','bKash নম্বর',details.mobile,'tel',true,30);
        field(form,'holder','অ্যাকাউন্টধারীর নাম (ঐচ্ছিক)',details.holder,'text',false);
    }
    const fields = entry ? {id:entry.id, revision:entry.revision} : {};
    onSave(form, entry ? 'পরিবর্তন সংরক্ষণ করুন' : 'যোগ করুন', values =>
        request({action:'profile_payment_save', kind, ...fields, ...values}));
    return form;
}

function renderPayments(payments) {
    const section = document.querySelector('#profile-payment');
    section.replaceChildren(element('h2','ব্যাংক ও bKash'));
    for (const entry of payments) {
        const panel = element('details');
        const label = entry.kind === 'bank' ? entry.details.bank + ' · ' + entry.details.branch :
            'bKash · ' + entry.details.mobile;
        panel.append(element('summary',label),paymentForm(entry.kind,entry));
        const remove = element('form');
        onSave(remove,'এই পেমেন্ট তথ্য সরান', () =>
            request({action:'profile_payment_remove',id:entry.id,revision:entry.revision}));
        panel.append(remove);
        section.append(panel);
    }
    for (const [kind,label] of [['bank','ব্যাংক অ্যাকাউন্ট যোগ করুন'],['bkash','bKash যোগ করুন']]) {
        const panel = element('details');
        panel.append(element('summary',label),paymentForm(kind));
        section.append(panel);
    }
    section.hidden = false;
}

function renderHistory(contacts,nids) {
    const section = document.querySelector('#profile-history');
    const list = element('ul');
    for (const entry of contacts) {
        const kind = entry.kind === 'email' ? 'পুরোনো ইমেইল' : 'পুরোনো মোবাইল';
        list.append(element('li',kind + ' · ' + entry.value + ' · ' + dateLabel(entry.changedAt)));
    }
    for (const entry of nids) {
        const item = element('li');
        const link = element('a','পুরোনো NID ডাউনলোড করুন');
        link.href = './api.php?staff=file&id=' + encodeURIComponent(entry.id);
        item.append(link,' · আপলোড: ' + dateLabel(entry.created_at));
        list.append(item);
    }
    section.replaceChildren(element('h2','প্রোফাইল ইতিহাস'),list.children.length ? list : element('p','পুরোনো তথ্য পাওয়া যায়নি।'));
    section.hidden = false;
}

function dateLabel(value) {
    return new Intl.DateTimeFormat('bn-BD', { dateStyle:'medium', timeStyle:'short', timeZone:'Asia/Dhaka' }).format(new Date(value));
}

async function refresh() {
    const data = await request();
    if (data.isSelf) {
        renderContact(data.current);
        renderPayments(data.payments);
    }
    renderHistory(data.contacts,data.nids);
}

refresh().catch(error => { message.textContent = error.message; });

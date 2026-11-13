const status = document.querySelector('#operations-status');
const modules = {
    profiles: 'প্রোফাইল অনুমোদন',
    attendance: 'হাজিরা',
    leave: 'ছুটি',
    payroll: 'মাসিক বেতন',
    notices: 'অফিস নোটিশ',
};

function element(tag, value) {
    const result = document.createElement(tag);
    if (value !== undefined) result.textContent = value;
    return result;
}

function select(name, options) {
    const result = element('select');
    result.name = name;
    for (const [value, label] of options) {
        const option = element('option', label);
        option.value = value;
        result.append(option);
    }
    return result;
}

function field(form, title, control) {
    const label = element('label', title);
    label.append(control);
    form.append(label);
}

async function request(view, body) {
    const options = body ? {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content,
        },
        body: JSON.stringify(body),
    } : undefined;
    const response = await fetch(view ? './api.php?operations=' + view : './api.php', options);
    const payload = await response.json();
    if (!response.ok) {
        const error = new Error(payload.error || payload.message || 'অনুরোধ সম্পন্ন হয়নি।');
        error.status = response.status;
        throw error;
    }
    return payload;
}

function saveForm(form, submitLabel, save) {
    const button = element('button', submitLabel);
    button.className = 'btn primary';
    form.append(button);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        button.disabled = true;
        status.textContent = '';
        try {
            await save();
            status.textContent = 'সংরক্ষণ হয়েছে।';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });
}

async function renderNotices() {
    const data = await request('notices');
    const section = document.querySelector('#operations-notices');
    section.replaceChildren(element('h2', 'অফিস নোটিশ'));
    section.hidden = false;
    if (data.canPublish) {
        const form = element('form');
        form.className = 'hr-form-grid';
        const title = element('input');
        title.required = true;
        title.maxLength = 150;
        const body = element('textarea');
        body.required = true;
        body.maxLength = 4000;
        const branch = select('branchId', [['', 'সব ব্রাঞ্চ'], ...data.branches.map(item => [item.id, item.name])]);
        if (data.canBroadcast) field(form, 'কারা দেখবেন', branch);
        field(form, 'বিষয়', title);
        field(form, 'নোটিশ', body);
        saveForm(form, 'প্রকাশ করুন', async () => {
            await request('', { action: 'operations_notice', title: title.value, body: body.value, branchId: branch.value });
            await renderNotices();
        });
        section.append(form);
    }
    if (!data.notices.length) section.append(element('p', 'কোনো নোটিশ নেই।'));
    for (const notice of data.notices) {
        const article = element('article');
        const body = element('p', notice.body);
        body.style.whiteSpace = 'pre-wrap';
        article.append(element('h3', notice.title), element('p', notice.author_name + ' · ' + notice.created_at), body);
        section.append(article);
    }
}

async function renderAccess() {
    const data = await request('access');
    const section = document.querySelector('#operations-access');
    const form = element('form');
    form.className = 'hr-form-grid';
    const user = select('accountId', data.users.map(item => [item.id, item.name + ' · ' + item.email]));
    const module = select('module', data.modules.map(key => [key, modules[key]]));
    const level = select('level', [['-1', 'পদ অনুযায়ী'], ['0', 'অ্যাক্সেস নেই'], ['1', 'শুধু দেখা'], ['2', 'দেখা ও এডিট']]);
    const grants = new Map(data.grants.map(item => [item.account_id + ':' + item.module, String(item.access_level)]));
    const key = () => user.value + ':' + module.value;
    const update = () => { level.value = grants.get(key()) ?? '-1'; };
    user.addEventListener('change', update);
    module.addEventListener('change', update);
    field(form, 'দায়িত্বপ্রাপ্ত ইউজার', user);
    field(form, 'অপশন', module);
    field(form, 'অনুমতি', level);
    update();
    saveForm(form, 'অনুমতি সংরক্ষণ', async () => {
        const savedKey = key();
        const savedLevel = level.value;
        await request('', { action: 'operations_access', accountId: user.value, module: module.value, level: Number(savedLevel) });
        grants.set(savedKey, savedLevel);
        update();
    });
    section.replaceChildren(element('h2', 'অপশনের অ্যাক্সেস'), data.users.length ? form : element('p', 'কোনো সক্রিয় ইউজার নেই।'));
    section.hidden = false;
}

if (status) {
    const outcomes = await Promise.allSettled([renderNotices(), renderAccess()]);
    const failure = outcomes.find(result => result.status === 'rejected' && result.reason.status !== 403);
    if (failure) status.textContent = failure.reason.message;
    else if (outcomes.every(result => result.status === 'rejected')) status.textContent = 'এই বিভাগে আপনার অ্যাক্সেস নেই।';
} else {
    const root = document.querySelector('#root');
    const observer = new MutationObserver(() => {
        const top = root.querySelector('.topbar .top-right');
        if (!top || !top.querySelector('.logout-button') || top.querySelector('[data-office-operations]')) return;
        const link = element('a', 'অফিস পরিচালনা');
        link.href = './operations.php';
        link.className = 'btn compact';
        link.dataset.officeOperations = '1';
        top.prepend(link);
    });
    if (root) observer.observe(root, { childList: true, subtree: true });
}

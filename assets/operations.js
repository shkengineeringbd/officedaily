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

function releaseField(form, name, title, value = '', type = 'text', required = true) {
    const input = element('input');
    input.name = name;
    input.type = type;
    input.value = value;
    input.required = required;
    field(form, title, input);
}

function releaseForm(article, budget, title, action, fields, extra = {}) {
    const details = element('details');
    details.append(element('summary', title));
    const form = element('form');
    form.className = 'hr-form-grid';
    for (const args of fields) releaseField(form, ...args);
    saveForm(form, title, async () => {
        const values = Object.fromEntries(new FormData(form));
        if (values.percent === '') delete values.percent;
        await request('', { action, id: budget.id, ...extra, ...values });
        await renderReleases();
    });
    details.append(form);
    article.append(details);
}

async function renderReleases() {
    const data = await request('releases');
    const section = document.querySelector('#operations-releases');
    section.replaceChildren(element('h2', 'বাজেটের কিস্তি ও চূড়ান্ত রিলিজ'));
    section.hidden = false;
    if (!data.items.length) section.append(element('p', 'রিলিজের রেকর্ড নেই।'));
    for (const budget of data.items) {
        const article = element('article');
        article.append(
            element('h3', budget.code + ' · ' + budget.branch),
            element('p', budget.applicant_name + ' · ছাড় হয়েছে ৳' + budget.released + ' / ৳' + budget.amount),
        );
        if (budget.status === 'pending' && data.role === 'cfo') {
            releaseForm(article, budget, 'কিস্তি বা অবশিষ্ট টাকা ছাড়', 'budget_release', [
                ['percent', 'অনুমোদিত বাজেটের শতাংশ (খালি রাখলে পুরো অবশিষ্ট)','','text',false],
                ['date', 'রিলিজের তারিখ', data.today, 'date'],
                ['reference', 'রিলিজ রেফারেন্স'],
                ['note', 'মন্তব্য', '', 'text', false],
            ], { expectedStage: 3, expectedReleased: budget.released });
        }
        if (budget.status === 'released') {
            article.append(element('p', budget.released_on + ' · ' + budget.release_reference));
            if (['gm','cfo'].includes(data.role)) {
                releaseForm(article, budget, 'মন্তব্য যোগ করুন', 'release_remark', [['note','মন্তব্য']]);
            }
            if (data.isAdmin) {
                const current = { expectedReference: budget.release_reference };
                releaseForm(article, budget, 'শেষ রিলিজ সংশোধন', 'release_correct', [
                    ['date','রিলিজের তারিখ',budget.released_on,'date'],
                    ['reference','রিলিজ রেফারেন্স',budget.release_reference],
                    ['note','সংশোধনের কারণ'],
                ], current);
                releaseForm(article, budget, 'শেষ রিলিজ বাতিল', 'release_void', [['note','বাতিলের কারণ']], current);
            }
        }
        section.append(article);
    }
}

if (status) {
    const outcomes = await Promise.allSettled([renderNotices(), renderAccess(), renderReleases()]);
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

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

function parseAttendanceCsv(text) {
    const rows = [];
    let row = [];
    let fieldValue = '';
    let quoted = false;
    let afterQuote = false;
    const csv = text.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
    for (let i = 0; i <= csv.length; i++) {
        const char = csv[i] ?? '\n';
        if (quoted) {
            if (char === '"' && csv[i + 1] === '"') {
                fieldValue += '"';
                i++;
            } else if (char === '"') {
                quoted = false;
                afterQuote = true;
            } else fieldValue += char;
        } else if (char === ',' || char === '\n') {
            row.push(fieldValue);
            fieldValue = '';
            afterQuote = false;
            if (char === '\n') {
                if (row.some(Boolean)) rows.push(row);
                row = [];
            }
        } else if (char === '"' && !fieldValue && !afterQuote) quoted = true;
        else {
            if (afterQuote || char === '"') throw new Error('CSV ফাইলের উদ্ধৃতি ঠিক করুন।');
            fieldValue += char;
        }
    }
    if (quoted || rows.shift()?.map(value => value.trim().toLowerCase()).join(',') !== 'email,date,status,note') {
        throw new Error('CSV-এর প্রথম সারি দিন: email,date,status,note');
    }
    if (!rows.length || rows.length > 200 || rows.some(row => row.length !== 4)) throw new Error('সঠিক চার কলামে ১ থেকে ২০০টি রেকর্ড দিন।');
    return rows.map(([email,date,status,note]) => ({ email,date,status,note }));
}

function importForm() {
    const section = element('section');
    const form = element('form');
    const file = element('input');
    file.type = 'file';
    file.accept = '.csv,text/csv';
    file.required = true;
    section.append(element('h3', 'হাজিরা আমদানি'), element('p', 'Excel থেকে CSV UTF-8 করে সেভ করুন। কলাম রাখুন email, date, status, note। তারিখ YYYY-MM-DD।'));
    field(form, 'CSV ফাইল', file);
    const preview = element('div');
    file.addEventListener('change', () => preview.replaceChildren());
    saveForm(form, 'প্রিভিউ দেখুন', async () => {
        preview.replaceChildren();
        const selected = file.files[0];
        if (selected.size > 40000) throw new Error('একবারে ছোট CSV ফাইল দিন।');
        const records = parseAttendanceCsv(await selected.text());
        const result = await request('', { action: 'attendance_import', records, apply: false });
        for (const entry of result.rows) {
            const line = element('p', [entry.row, entry.email, entry.date, entry.error || 'ঠিক আছে'].join(' · '));
            if (entry.error) line.style.color = '#a11a23';
            preview.append(line);
        }
        if (result.valid === records.length) {
            const confirm = element('form');
            saveForm(confirm, 'নতুন হাজিরা আমদানি করুন', async () => {
                await request('', { action: 'attendance_import', records, apply: true });
                preview.replaceChildren(element('p', records.length + 'টি রেকর্ড আমদানি হয়েছে।'));
            });
            preview.append(confirm);
        }
    });
    section.append(form, preview);
    return section;
}

function backupForm(driveAvailable) {
    const form = element('form');
    const password = element('input');
    password.type = 'password';
    password.minLength = 14;
    password.maxLength = 100;
    password.required = true;
    password.autocomplete = 'new-password';
    form.append(element('h3', 'এনক্রিপ্টেড ব্যাকআপ'));
    field(form, 'ব্যাকআপ পাসফ্রেজ', password);
    const destinations = [['download', 'ZIP ডাউনলোড']];
    if (driveAvailable) destinations.push(['drive', 'Google Drive']);
    const destination = select('destination', destinations);
    if (driveAvailable) field(form, 'ব্যাকআপ রাখুন', destination);
    saveForm(form, 'ব্যাকআপ নিন', async () => {
        if (destination.value === 'drive') {
            await request('', { action: 'operations_backup', backupPassword: password.value, destination: 'drive' });
            password.value = '';
            return;
        }
        const response = await fetch('./api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ action: 'operations_backup', backupPassword: password.value }),
        });
        if (!response.ok) throw new Error((await response.json()).error || 'ব্যাকআপ তৈরি করা যায়নি।');
        const url = URL.createObjectURL(await response.blob());
        const link = element('a');
        link.href = url;
        link.download = 'Office_Daily_' + new Date().toISOString().replace(/[:.]/g, '-') + '.zip';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        password.value = '';
    });
    return form;
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
    section.replaceChildren(element('h2', 'অপশনের অ্যাক্সেস'), data.users.length ? form : element('p', 'কোনো সক্রিয় ইউজার নেই।'), backupForm(data.backupDriveAvailable), importForm());
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
            if (budget.voucher) article.append(element('p', 'ভাউচার: ' + budget.voucher + ' · ব্যয় ৳' + budget.actual + ' · ফেরত ৳' + budget.returned));
            if (data.isAdmin || data.role === 'cfo') {
                releaseForm(article, budget, 'বাজেট নিষ্পত্তি', 'finance_settle', [
                    ['voucher','বিল বা ভাউচার রেফারেন্স',budget.voucher ?? ''],
                    ['actual','প্রকৃত ব্যয়',budget.actual ?? budget.amount],
                    ['returned','অব্যয়িত টাকা ফেরত',budget.returned ?? '0.00'],
                    ['note','মন্তব্য',budget.settlement_note ?? '', 'text', false],
                ]);
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

function showPayroll(container, items) {
    const table = element('table');
    const heading = element('tr');
    for (const title of ['নাম','Basic','Medical','অন্যান্য','কর্তন','নিট','অবস্থা']) heading.append(element('th', title));
    table.append(heading);
    for (const item of items) {
        const row = element('tr');
        for (const key of ['name','basic_salary','medical_allowance','other_allowance','deductions','net_salary','payment_status']) row.append(element('td', item[key]));
        table.append(row);
    }
    const box = element('div');
    box.style.overflowX = 'auto';
    box.append(table);
    container.append(box);
}

function payrollBudgetForm(article, batch, finalize, refresh) {
    const details = element('details');
    const label = finalize ? 'চূড়ান্ত করে বাজেটে যোগ' : 'বাজেটে যোগ';
    details.append(element('summary', label));
    const form = element('form');
    form.className = 'hr-form-grid';
    const method = select('method', [['Cash','Cash'],['Bank','Bank'],['bKash','bKash']]);
    const fields = element('div');
    const update = () => {
        fields.replaceChildren();
        if (method.value === 'Cash') releaseField(fields, 'recipient', 'গ্রহণকারীর নাম');
        if (method.value === 'bKash') releaseField(fields, 'mobile', 'বিকাশ নম্বর');
        if (method.value === 'Bank') {
            releaseField(fields, 'bank', 'ব্যাংক');
            releaseField(fields, 'accountName', 'অ্যাকাউন্টের নাম');
            releaseField(fields, 'accountNumber', 'অ্যাকাউন্ট নম্বর');
        }
    };
    method.addEventListener('change', update);
    update();
    field(form, 'পেমেন্ট মাধ্যম', method);
    form.append(fields);
    saveForm(form, label, async () => {
        await request('', { action: finalize ? 'finance_finalize' : 'finance_budget', id: batch.id, autoBudget: finalize, ...Object.fromEntries(new FormData(form)) });
        await refresh();
    });
    details.append(form);
    article.append(details);
}

async function renderFinance(month = new Date(Date.now() + 6 * 3600000).toISOString().slice(0, 7)) {
    const data = await request('finance&month=' + encodeURIComponent(month));
    const section = document.querySelector('#operations-finance');
    section.replaceChildren(element('h2', data.isHead ? 'Finance Dashboard' : 'ব্রাঞ্চের বেতন যাচাই'));
    section.hidden = false;
    const picker = element('form');
    releaseField(picker, 'month', 'বেতনের মাস', data.month, 'month');
    saveForm(picker, 'মাস দেখুন', () => renderFinance(new FormData(picker).get('month')));
    section.append(picker);
    const refresh = () => renderFinance(data.month);
    if (data.canSubmit && data.payroll.length && !data.batches.length) {
        showPayroll(section, data.payroll);
        const form = element('form');
        saveForm(form, 'যাচাই করে Head Office-এ পাঠান', async () => {
            await request('', { action: 'finance_submit', month: data.month });
            await refresh();
        });
        section.append(form);
    }
    if (!data.batches.length) section.append(element('p', 'পাঠানো বেতন তালিকা নেই।'));
    for (const batch of data.batches) {
        const article = element('article');
        article.append(element('h3', batch.branch_name + ' · ' + batch.month + ' · ৳' + batch.total));
        const details = element('details');
        details.append(element('summary', batch.status === 'finalized' ? 'চূড়ান্ত বেতন' : 'যাচাইয়ের জন্য পাঠানো বেতন'));
        showPayroll(details, batch.snapshot);
        article.append(details);
        if (data.isHead && batch.status === 'submitted') {
            const form = element('form');
            saveForm(form, 'বেতন চূড়ান্ত করুন', async () => {
                await request('', { action: 'finance_finalize', id: batch.id });
                await refresh();
            });
            article.append(form);
        }
        if (data.isHead && !batch.budget_id) payrollBudgetForm(article, batch, batch.status === 'submitted', refresh);
        if (batch.budget_id) article.append(element('p', 'বাজেটে যোগ হয়েছে।'));
        section.append(article);
    }
}

async function renderInbox() {
    const data = await request('inbox');
    const section = document.querySelector('#operations-inbox');
    section.replaceChildren(element('h2', 'অনুমোদনের ইনবক্স'));
    section.hidden = false;
    section.append(element('p', 'অপঠিত চ্যাট · ' + data.unreadChat));
    if (data.canConfigure) {
        const form = element('form');
        releaseField(form,'days','অনুমোদনের সময়সীমা (ক্যালেন্ডার দিন, ০ দিলে বন্ধ)',String(data.dueDays),'number');
        saveForm(form,'সময়সীমা সংরক্ষণ',async () => {
            await request('', { action: 'operations_due_days', days: Number(new FormData(form).get('days')) });
            await renderInbox();
        });
        section.append(form);
    }
    for (const [title, entries] of [['বাজেট অপেক্ষায়', data.budgets], ['ছুটি অপেক্ষায়', data.leaves], ['প্রত্যাখ্যাত বাজেট', data.returned]]) {
        section.append(element('h3', title + ' · ' + entries.length));
        for (const entry of entries) {
            const label = entry.name ? entry.name + ' · ' + entry.start_date + ' থেকে ' + entry.end_date
                : 'BR-' + String(entry.serial).padStart(6, '0') + ' · ' + entry.branch + ' · ' + entry.reason + ' · ' + entry.submitted_on;
            const article = element('article');
            article.append(element('p', label + (entry.due_on ? ' · শেষ তারিখ ' + entry.due_on : '')));
            if (entry.name) for (const [decision, text] of [['approved', 'অনুমোদন'], ['rejected', 'প্রত্যাখ্যান']]) {
                const form = element('form');
                saveForm(form, text, async () => {
                    await request('', { action: 'leave_review', id: entry.id, status: decision });
                    await renderInbox();
                });
                article.append(form);
            }
            section.append(article);
        }
    }
}

async function renderInsights(month = new Date(Date.now() + 6 * 3600000).toISOString().slice(0, 7)) {
    const data = await request('insights&month=' + encodeURIComponent(month));
    const section = document.querySelector('#operations-insights');
    section.replaceChildren(element('h2', 'ব্রাঞ্চ ড্যাশবোর্ড'));
    section.hidden = false;
    const picker = element('form');
    releaseField(picker, 'month', 'মাস', data.month, 'month');
    saveForm(picker, 'মাস দেখুন', () => renderInsights(new FormData(picker).get('month')));
    section.append(picker);
    for (const branch of data.branches) {
        const article = element('article');
        article.append(element('h3', branch.name));
        for (const [label, counts] of [['কাজ', branch.tasks], ['হাজিরা', branch.attendance], ['বাজেট', branch.budget]]) {
            if (!counts.length) continue;
            article.append(element('p', label + ' · ' + counts.map(row =>
                row.status + ' ' + row.total + (row.amount ? ' (৳' + row.amount + ')' : '')
            ).join(' · ')));
        }
        section.append(article);
    }
}

async function renderMfa() {
    const data = await request('mfa');
    const section = document.querySelector('#operations-mfa');
    section.replaceChildren(element('h2', 'অথেনটিকেটর সুরক্ষা'));
    section.hidden = false;
    if (data.enabled) {
        section.append(element('p', 'MFA চালু আছে।'));
        return;
    }
    const form = element('form');
    const password = element('input');
    password.type = 'password';
    password.autocomplete = 'current-password';
    password.required = true;
    field(form, 'বর্তমান পাসওয়ার্ড', password);
    saveForm(form, 'MFA সেটআপ শুরু', async () => {
        const setup = await request('', { action: 'mfa_start', password: password.value });
        const confirm = element('form');
        confirm.append(element('p', 'অথেনটিকেটর অ্যাপে সময়ভিত্তিক অ্যাকাউন্ট যোগ করে এই কী দিন।'));
        confirm.append(element('code', setup.secret));
        const code = element('input');
        code.required = true;
        code.pattern = '[0-9]{6}';
        code.inputMode = 'numeric';
        code.autocomplete = 'one-time-code';
        field(confirm, 'ছয় অঙ্কের কোড', code);
        saveForm(confirm, 'MFA চালু করুন', async () => {
            await request('', { action: 'mfa_enable', code: code.value });
            await renderMfa();
        });
        form.replaceWith(confirm);
    });
    section.append(form);
}

if (status) {
    const outcomes = await Promise.allSettled([renderNotices(), renderAccess(), renderReleases(), renderFinance(), renderInbox(), renderInsights(), renderMfa()]);
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

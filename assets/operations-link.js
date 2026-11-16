const root = document.querySelector('#root');

if (root) {
    const observer = new MutationObserver(() => {
        const top = root.querySelector('.topbar .top-right');
        if (!top || !top.querySelector('.logout-button') || top.querySelector('[data-office-operations]')) return;
        const link = document.createElement('a');
        link.textContent = 'অফিস পরিচালনা';
        link.href = './operations.php';
        link.className = 'btn compact';
        link.dataset.officeOperations = '1';
        top.prepend(link);
    });
    observer.observe(root, { childList: true, subtree: true });
}

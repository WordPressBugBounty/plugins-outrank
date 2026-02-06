// Initialize plugin settings
let outrankPluginSettings = {
    apiKey: document.getElementById('api_key')?.value.trim() || '',
};

// Load settings on page load
function outrankLoadSettings() {
    const input = document.getElementById('api_key');
    if (input) outrankPluginSettings.apiKey = input.value.trim();
    updateStatus();
}

// Display a notification message
function outrankShowNotice(message, type = 'success') {
    const notice = document.getElementById('notice');
    const messageSpan = document.getElementById('notice-message');

    if (!notice || !messageSpan) return;

    notice.className = `notice notice-${type}`;
    messageSpan.textContent = message;
    notice.style.display = 'block';

    setTimeout(() => {
        notice.style.display = 'none';
    }, 4000);
}

// Update status badge based on API key
function updateStatus() {
    const status = document.getElementById('fetch-status');
    if (!status) return;

    if (outrankPluginSettings.apiKey) {
        status.textContent = 'Active';
        status.className = 'status-badge active';
    } else {
        status.textContent = 'Inactive';
        status.className = 'status-badge inactive';
    }
}

// Handle form submission for saving API key
document.getElementById('api-form')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const apiKeyInput = document.getElementById('api_key');
    const saveBtn = document.getElementById('save-btn');
    const form = document.getElementById('api-form');

    const apiKey = apiKeyInput?.value.trim();
    if (!apiKey) {
        outrankShowNotice('❌ Please enter an API key', 'error');
        return;
    }

    if (!saveBtn || !form) return;

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    // Save to JS config
    outrankPluginSettings.apiKey = apiKey;
    updateStatus();
    outrankShowNotice('✅ API key saved successfully!');

    // Temporarily remove event listener to avoid recursion
    form.removeEventListener('submit', arguments.callee);

    // Use setTimeout to allow UI updates before submission
    setTimeout(() => {
        form.submit();
    }, 100);
});

// Load initial settings
outrankLoadSettings();
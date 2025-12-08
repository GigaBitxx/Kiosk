// Function to apply UI settings
function applyUISettings() {
    const settings = JSON.parse(localStorage.getItem('uiSettings')) || {
        font_size: 'medium',
        language: 'en',
        brightness: false,
        high_contrast: false
    };

    // Apply font size
    document.body.classList.remove('font-small', 'font-medium', 'font-large', 'font-xlarge');
    document.body.classList.add('font-' + settings.font_size);

    // Apply brightness
    if (settings.brightness) {
        document.body.classList.add('brightness');
    } else {
        document.body.classList.remove('brightness');
    }

    // Apply high contrast
    if (settings.high_contrast) {
        document.body.classList.add('high-contrast');
    } else {
        document.body.classList.remove('high-contrast');
    }

    // Apply language if supported
    if (settings.language) {
        document.documentElement.lang = settings.language;
    }
}

// Function to save UI settings
function saveUISettings(settings) {
    localStorage.setItem('uiSettings', JSON.stringify(settings));
    applyUISettings();
}

// Function to get current UI settings
function getUISettings() {
    return JSON.parse(localStorage.getItem('uiSettings')) || {
        font_size: 'medium',
        language: 'en',
        brightness: false,
        high_contrast: false
    };
}

// Apply settings when page loads
document.addEventListener('DOMContentLoaded', applyUISettings);

// Listen for storage events to sync settings across tabs
window.addEventListener('storage', function(e) {
    if (e.key === 'uiSettings') {
        applyUISettings();
    }
}); 
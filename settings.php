<?php
require_once 'config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trece Martires Memorial Park</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/images/tmmp-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #f4f6f9;
            min-height: 100vh;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            overflow-x: hidden;
        }

        .settings-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 2rem 0;
        }

        .settings-header {
            position: relative;
            text-align: center;
            margin-bottom: 2rem;
            padding: 0 1rem;
        }

        .back-button {
            position: absolute;
            top: 0;
            left: 1.5rem;
            background: #ffffff;
            border: none;
            border-radius: 25px;
            padding: 0.75rem 1rem;
            box-shadow: 0 10px 20px rgba(15,23,42,0.1);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #2b4c7e;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(15,23,42,0.15);
            color: #1f3659;
        }
        .back-button i {
            font-size: 20px;
        }

        .settings-title {
            font-size: clamp(2rem, 5vw, 2.5rem);
            font-weight: 700;
            color: #1d2a38;
            margin: 0;
        }

        .settings-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
            width: 100%;
        }

        .settings-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(15, 23, 42, 0.1);
        }

        .settings-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1d2a38;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .settings-card h3 i {
            color: #2b4c7e;
        }

        .settings-option {
            padding: 1.25rem 0;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .settings-option:last-child {
            border-bottom: none;
        }

        .settings-option label {
            display: block;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .settings-option select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1e293b;
            background: #ffffff;
            transition: border-color 0.2s ease;
            min-height: 40px;
        }

        .settings-option select:focus {
            outline: none;
            border-color: #2b4c7e;
        }

        .brightness-control {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .brightness-slider {
            flex: 1;
            height: 8px;
            border-radius: 4px;
            background: #e2e8f0;
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }

        .brightness-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #2b4c7e;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(43, 76, 126, 0.3);
        }

        .brightness-slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #2b4c7e;
            cursor: pointer;
            border: none;
            box-shadow: 0 2px 6px rgba(43, 76, 126, 0.3);
        }

        .brightness-value {
            min-width: 50px;
            text-align: center;
            font-weight: 600;
            color: #2b4c7e;
            font-size: 1rem;
        }

        .save-button {
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 1rem 2.5rem;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.3s ease, transform 0.2s ease;
            width: 100%;
            margin-top: 1rem;
        }

        .save-button:hover {
            background: #1f3659;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .settings-wrapper {
                padding: 1rem 0;
            }

            .back-button {
                position: static;
                margin-bottom: 1rem;
                display: inline-flex;
            }

            .settings-header {
                text-align: left;
                margin-bottom: 1.5rem;
            }

            .settings-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="settings-wrapper">
        <div class="settings-header">
            <a href="main.php" class="back-button">
                <i class='bx bx-arrow-back'></i>
                <span>Back</span>
            </a>
            <h1 class="settings-title">Settings</h1>
        </div>

        <div class="settings-container">
            <div class="settings-card">
                <h3>
                    <i class='bx bx-globe'></i>
                    Language Options
                </h3>
                <div class="settings-option">
                    <label for="language">Select Language</label>
                    <select id="language" name="language">
                        <option value="en">English</option>
                        <option value="fil">Filipino</option>
                        <option value="es">Spanish</option>
                    </select>
                </div>
            </div>

            <div class="settings-card">
                <h3>
                    <i class='bx bx-sun'></i>
                    Display Settings
                </h3>
                <div class="settings-option">
                    <label for="brightness">Screen Brightness</label>
                    <div class="brightness-control">
                        <input type="range" id="brightness" class="brightness-slider" min="20" max="100" value="100" step="5">
                        <span class="brightness-value" id="brightnessValue">100%</span>
                    </div>
                </div>
            </div>

            <button class="save-button" onclick="saveSettings()">
                <i class='bx bx-save'></i> Save Settings
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Translation dictionary
        const translations = {
            en: {
                settings: 'Settings',
                back: 'Back',
                languageOptions: 'Language Options',
                selectLanguage: 'Select Language',
                displaySettings: 'Display Settings',
                screenBrightness: 'Screen Brightness',
                saveSettings: 'Save Settings',
                settingsSaved: 'Settings saved successfully!',
                english: 'English',
                filipino: 'Filipino',
                spanish: 'Spanish'
            },
            fil: {
                settings: 'Mga Setting',
                back: 'Bumalik',
                languageOptions: 'Mga Opsyon sa Wika',
                selectLanguage: 'Pumili ng Wika',
                displaySettings: 'Mga Setting sa Display',
                screenBrightness: 'Liwanag ng Screen',
                saveSettings: 'I-save ang Mga Setting',
                settingsSaved: 'Matagumpay na nai-save ang mga setting!',
                english: 'Ingles',
                filipino: 'Filipino',
                spanish: 'Espanyol'
            },
            es: {
                settings: 'Configuración',
                back: 'Volver',
                languageOptions: 'Opciones de Idioma',
                selectLanguage: 'Seleccionar Idioma',
                displaySettings: 'Configuración de Pantalla',
                screenBrightness: 'Brillo de Pantalla',
                saveSettings: 'Guardar Configuración',
                settingsSaved: '¡Configuración guardada con éxito!',
                english: 'Inglés',
                filipino: 'Filipino',
                spanish: 'Español'
            }
        };

        // Apply translations
        function applyTranslations(lang) {
            const trans = translations[lang] || translations.en;
            
            // Update page elements
            document.querySelector('.settings-title').textContent = trans.settings;
            document.querySelector('.back-button span').textContent = trans.back;
            document.querySelector('h3 i.bx-globe').parentElement.innerHTML = `<i class='bx bx-globe'></i> ${trans.languageOptions}`;
            document.querySelector('label[for="language"]').textContent = trans.selectLanguage;
            document.querySelector('h3 i.bx-sun').parentElement.innerHTML = `<i class='bx bx-sun'></i> ${trans.displaySettings}`;
            document.querySelector('label[for="brightness"]').textContent = trans.screenBrightness;
            document.querySelector('.save-button').innerHTML = `<i class='bx bx-save'></i> ${trans.saveSettings}`;
            
            // Update language options
            document.querySelector('option[value="en"]').textContent = trans.english;
            document.querySelector('option[value="fil"]').textContent = trans.filipino;
            document.querySelector('option[value="es"]').textContent = trans.spanish;
        }

        // Load saved settings
        function loadSettings() {
            const savedLanguage = localStorage.getItem('kiosk_language') || 'en';
            const savedBrightness = localStorage.getItem('kiosk_brightness') || '100';
            
            document.getElementById('language').value = savedLanguage;
            document.getElementById('brightness').value = savedBrightness;
            document.getElementById('brightnessValue').textContent = savedBrightness + '%';
            applyBrightness(savedBrightness);
            applyTranslations(savedLanguage);
        }

        // Apply brightness to the screen
        function applyBrightness(value) {
            const overlay = document.getElementById('brightnessOverlay');
            if (!overlay) {
                const newOverlay = document.createElement('div');
                newOverlay.id = 'brightnessOverlay';
                newOverlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, ${(100 - value) / 200});
                    pointer-events: none;
                    z-index: 999999;
                    transition: background 0.3s ease;
                `;
                document.body.appendChild(newOverlay);
            } else {
                overlay.style.background = `rgba(0, 0, 0, ${(100 - value) / 200})`;
            }
        }

        // Update brightness value display
        document.getElementById('brightness').addEventListener('input', function(e) {
            const value = e.target.value;
            document.getElementById('brightnessValue').textContent = value + '%';
            applyBrightness(value);
        });

        // Apply language changes immediately when selected
        document.getElementById('language').addEventListener('change', function(e) {
            const language = e.target.value;
            applyTranslations(language);
        });

        // Save settings
        function saveSettings() {
            const language = document.getElementById('language').value;
            const brightness = document.getElementById('brightness').value;
            
            localStorage.setItem('kiosk_language', language);
            localStorage.setItem('kiosk_brightness', brightness);
            
            // Show success message in selected language
            const trans = translations[language] || translations.en;
            alert(trans.settingsSaved);
            
            // Redirect to main page after a short delay
            setTimeout(() => {
                window.location.href = 'main.php';
            }, 500);
        }

        // Initialize on page load
        loadSettings();

        // Idle timeout redirect to welcome screen
        let idleTimeout;
        const IDLE_LIMIT = 60000; // 60 seconds

        const resetIdleTimer = () => {
            clearTimeout(idleTimeout);
            idleTimeout = setTimeout(() => {
                window.location.href = 'index.php';
            }, IDLE_LIMIT);
        };

        ['mousemove', 'touchstart', 'keydown', 'click'].forEach(evt => {
            window.addEventListener(evt, resetIdleTimer);
        });

        resetIdleTimer();
    </script>
</body>
</html>


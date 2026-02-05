<?php
require_once 'config/database.php';
// Note: Pending assistance marker is shown only on staff and admin dashboards, not on the kiosk.
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }
        html {
            overflow-x: hidden;
        }
        body {
            background: #f4f6f9;
            min-height: 100vh;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
            margin: 0;
            overflow-x: hidden;
            max-width: 100vw;
        }
        .home-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 2rem 0 2.5rem;
        }
        .hero-section {
            position: relative;
            text-align: center;
            margin-bottom: 1rem;
        }
        .action-toggle {
            position: absolute;
            top: 1.5rem;
            left: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .help-toggle {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .action-toggle button,
        .help-toggle button {
            border: none;
            background: transparent;
            border-radius: 30px;
            padding: 0.9rem 1.2rem;
            box-shadow: 0 10px 20px rgba(15,23,42,0.1);
            cursor: pointer;
        }
        .help-toggle button.help-btn {
            box-shadow: none;
        }
        .help-toggle button.help-btn i {
            color: #2b4c7e;
        }
        .hero-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .hero-logo {
            display: inline-flex;
            align-items: center;
            gap: 1.5rem;
        }
        .hero-logo img {
            height: 72px;
            width: auto;
        }
        .hero-eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.4em;
            font-size: 1.2rem;
            color: #8b9bb7;
        }
        .hero-address {
            font-size: 1rem;
            letter-spacing: 0.15em;
            color: #5b6c86;
        }

        .hero-title {
            font-size: clamp(2.4rem, 5vw, 3.5rem);
            font-weight: 600;
            color: #1d2a38;
            margin-bottom: 1.5rem;
        }
        .hero-info {
            position: relative;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto 1.5rem;
            padding: 0 1rem;
            box-sizing: border-box;
            overflow: hidden;
        }
        .info-carousel {
            display: flex;
            align-items: stretch;
            gap: 0;
            padding: 2rem 0;
            cursor: grab;
            user-select: none;
            transition: transform 0.45s ease;
        }
        .info-carousel.dragging {
            cursor: grabbing;
        }
        .info-slide {
            flex: 0 0 100%;
            max-width: 100%;
            padding: 0 2vw;
            box-sizing: border-box;
        }
        .info-panel {
            width: 100%;
            min-height: 300px;
            background: #ffffff;
            border-radius: 0;
            border: 1px solid rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: row;
            overflow: hidden;
        }
        .info-content {
            flex: 1 1 50%;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.75rem;
        }
        .info-content h4 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            color: #1d2a38;
        }
        .info-content p {
            margin: 0;
            color: #4a5568;
            font-size: 1.2rem;
            line-height: 1.5;
        }
        .info-visual {
            flex: 1 1 50%;
            min-height: 220px;
            background-size: cover;
            background-position: center;
            position: relative;
        }
        .info-visual::after {
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.25);
        }
        .info-dots {
            position: absolute;
            top: 1rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            justify-content: center;
            gap: 0.4rem;
            z-index: 2;
        }
        .info-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.45);
            border: 1px solid rgba(23, 32, 56, 0.3);
            transition: transform 0.3s ease, background 0.3s ease;
        }
        .info-dot.active {
            transform: scale(1.2);
            background: #1f3659;
        }
        .info-block h4 {
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
            color: #2b4c7e;
        }
        .info-block p {
            margin: 0;
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .actions-section {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            box-sizing: border-box;
        }
        .primary-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            padding: 0 0 2rem;
            box-sizing: border-box;
        }
        .action-card {
            background: #ffffff;
            border-radius: 0;
            padding: 2.5rem 2rem;
            min-height: 360px;
            box-shadow: 0 25px 55px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(15, 23, 42, 0.1);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .action-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 24px 55px rgba(15, 23, 42, 0.12);
        }
        .action-icon {
            font-size: 4rem;
            color: #2b4c7e;
            margin-bottom: 1.5rem;
        }
        .action-title {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.75rem;
            color: #1d2a38;
        }
        .action-text {
            font-size: 1.2rem;
            color: #4a5568;
            margin-bottom: 1.5rem;
        }
        .action-button {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #2b4c7e;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 2.1rem;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            transition: background 0.3s ease, transform 0.3s ease;
            margin-top: auto;
        }
        .action-card:hover .action-button {
            background: #1f3659;
            transform: translateY(-2px);
        }

        /* PC and large screens: use space, avoid tiny content */
        @media (min-width: 1400px) {
            .home-wrapper {
                padding: 2.5rem 0 3rem;
            }
            .hero-title {
                font-size: clamp(3rem, 4vw, 4rem);
            }
            .hero-info {
                max-width: 1320px;
            }
            .actions-section {
                max-width: 1320px;
            }
            .action-card {
                min-height: 380px;
                padding: 3rem 2.5rem;
            }
            .action-title {
                font-size: 2rem;
            }
            .action-text {
                font-size: 1.25rem;
            }
        }
        @media (min-width: 1920px) {
            .hero-info {
                max-width: 1400px;
            }
            .actions-section {
                max-width: 1400px;
            }
        }
        @media (max-width: 1024px) {
            .home-wrapper {
                padding: 1.5rem 0 2rem;
            }
            .hero-title {
                font-size: clamp(2rem, 4vw, 3rem);
            }
            .info-content {
                padding: 1.75rem;
            }
            .action-card {
                min-height: 320px;
                padding: 2rem 1.5rem;
            }
        }

        @media (max-width: 768px) {
            body {
                overflow-y: auto;
            }
            .home-wrapper {
                padding: 1.25rem 0 1.75rem;
            }
            .action-toggle,
            .help-toggle {
                position: static;
                margin-bottom: 0.75rem;
                gap: 0.75rem;
            }
            .hero-header {
                flex-direction: column;
                text-align: center;
            }
            .hero-logo {
                flex-direction: column;
                gap: 0.5rem;
            }
            .hero-logo img {
                height: 60px;
            }
            .hero-title {
                font-size: clamp(1.8rem, 6vw, 2.2rem);
                padding: 0 0.5rem;
            }
            .hero-info {
                padding: 0;
            }
            .info-panel {
                border-radius: 12px;
                flex-direction: column;
                min-height: 0;
            }
            .info-content {
                padding: 1.25rem 1.5rem 1.5rem;
                gap: 0.6rem;
            }
            .info-content h4 {
                font-size: 1.4rem;
            }
            .info-content p {
                font-size: 1rem;
            }
            .info-visual {
                min-height: 180px;
            }
            .info-dots {
                top: 0.5rem;
            }
            .primary-actions {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .home-wrapper {
                padding: 1rem 0 1.5rem;
            }
            .hero-section {
                margin-bottom: 1rem;
            }
            .hero-title {
                font-size: 1.8rem;
            }
            .hero-eyebrow {
                font-size: 0.9rem;
                letter-spacing: 0.3em;
            }
            .hero-address {
                font-size: 0.85rem;
            }
            .info-carousel {
                padding: 1.25rem 0;
            }
            .info-content {
                padding: 1.25rem;
            }
            .action-card {
                padding: 1.75rem 1.25rem;
                min-height: auto;
            }
            .action-title {
                font-size: 1.4rem;
            }
            .action-text {
                font-size: 1rem;
            }
            .action-button {
                width: 100%;
                justify-content: center;
            }
        }

        /* Settings Modal Styles */
        .settings-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .settings-modal-overlay.active {
            display: block;
            opacity: 1;
        }

        .settings-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: #ffffff;
            border-radius: 16px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 10001;
            box-shadow: 0 25px 55px rgba(15, 23, 42, 0.25);
            transition: transform 0.3s ease;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
        }

        .settings-modal-overlay.active .settings-modal {
            transform: translate(-50%, -50%) scale(1);
        }

        .settings-modal-header {
            position: relative;
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
        }

        .settings-modal-close {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: #5b6c86;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .settings-modal-close:hover {
            background: #f1f5f9;
            color: #1d2a38;
        }

        .settings-modal-title {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 700;
            color: #1d2a38;
            margin: 0;
        }

        .settings-modal-body {
            padding: 2rem;
        }

        .settings-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
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
            color: #4a5568;
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .settings-description {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.6;
            margin: 0 0 1rem 0;
        }


        .settings-option select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1d2a38;
            background: #ffffff;
            transition: border-color 0.2s ease;
            min-height: 40px;
        }

        .settings-option select:focus {
            outline: none;
            border-color: #2b4c7e;
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

        .settings-success-message {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #d1fae5;
            border: 1px solid #10b981;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
            color: #065f46;
            font-weight: 600;
            font-size: 0.95rem;
            animation: slideDown 0.3s ease;
        }

        .settings-success-message i {
            font-size: 1.25rem;
            color: #10b981;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .settings-modal {
                width: 95%;
                max-height: 95vh;
            }

            .settings-modal-header,
            .settings-modal-body {
                padding: 1.5rem;
            }

            .settings-card {
                padding: 1.5rem;
            }
        }

        .help-tooltip-bubble {
            position: absolute;
            top: 1.1rem;
            right: 5.3rem;
            min-width: 190px;
            max-width: 230px;
            padding: 0.45rem 0.9rem;
            background: #ffffff;
            border-radius: 999px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.15);
            font-size: 0.8rem;
            color: #1d2a38;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            white-space: nowrap;
            opacity: 0;
            transform: translateY(-4px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 9999;
        }

        .help-tooltip-bubble.visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .help-tooltip-bubble::after {
            content: "";
            position: absolute;
            top: 50%;
            right: -6px;
            transform: translateY(-50%);
            border-width: 6px 0 6px 6px;
            border-style: solid;
            border-color: transparent transparent transparent #ffffff;
            filter: drop-shadow(0 2px 3px rgba(15, 23, 42, 0.18));
        }

        .help-tooltip-text {
            font-size: 0.8rem;
            color: #4a5568;
        }

        /* Help Modal Styles */
        .help-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .help-modal-overlay.active {
            display: block;
            opacity: 1;
        }

        .help-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: #ffffff;
            border-radius: 16px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 10001;
            box-shadow: 0 25px 55px rgba(15, 23, 42, 0.25);
            transition: transform 0.3s ease;
            font-family: 'Raleway', 'Helvetica Neue', sans-serif;
        }

        .help-modal-overlay.active .help-modal {
            transform: translate(-50%, -50%) scale(1);
        }

        .help-modal-header {
            position: relative;
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .help-modal-header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .help-modal-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: #5b6c86;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .help-modal-close:hover {
            background: #f1f5f9;
            color: #1d2a38;
        }

        .help-modal-assistance-btn {
            background: #2b4c7e;
            color: #ffffff;
            border: none;
            border-radius: 999px;
            padding: 0.5rem 1.25rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background 0.2s ease, transform 0.2s ease;
            white-space: nowrap;
        }

        .help-modal-assistance-btn:hover {
            background: #1f3659;
            transform: translateY(-1px);
        }

        .help-modal-assistance-btn i {
            font-size: 1rem;
        }

        .help-modal-title {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 700;
            color: #1d2a38;
            margin: 0;
        }

        .help-modal-body {
            padding: 2rem;
        }

        .help-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(15, 23, 42, 0.1);
        }

        .help-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1d2a38;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .help-card h3 i {
            color: #2b4c7e;
        }

        .help-card p {
            font-size: 1rem;
            color: #4a5568;
            line-height: 1.6;
            margin: 0;
        }

        @media (max-width: 768px) {
            .help-modal {
                width: 95%;
                max-height: 95vh;
            }

            .help-modal-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .help-modal-header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .help-modal-assistance-btn {
                font-size: 0.85rem;
                padding: 0.45rem 1rem;
            }

            .help-modal-header,
            .help-modal-body {
                padding: 1.5rem;
            }

            .help-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container home-wrapper">
        <div class="action-toggle">
                <button onclick="openSettingsModal()" class="btn btn-light" aria-label="Language">
                    <i class='bx bx-globe' style="font-size: 2rem;"></i>
                </button>
            </div>
        <div class="help-toggle">
                <button onclick="openHelpModal()" class="btn btn-light help-btn">
                    <i class='bx bx-help-circle' style="font-size: 2rem;"></i>
                </button>
                <div class="help-tooltip-bubble" id="helpTooltipBubble">
                    <span class="help-tooltip-text">Need help? Tap this button.</span>
                </div>
            </div>
        <section class="hero-section">
            <div class="hero-header">
                <div class="hero-logo">
                    <img src="assets/images/tmmp-logo.png" alt="Trece Martires Memorial Park Logo">
                    <div>
                        <div class="hero-eyebrow">Trece Martires Memorial Park</div>
                        <div class="hero-address">Trece Martires City, Cavite</div>
                    </div>
                </div>
            </div>
            <h1 class="hero-title">"Tulong at Alalay Hanggang sa Kabilang Buhay"</h1>
            <div class="hero-info">
                <div class="info-dots" id="info-dots"></div>
                <div class="info-carousel" id="info-carousel">
                    <div class="info-slide active" data-index="0">
                        <div class="info-panel">
                            <div class="info-visual" style="background-image: url('assets/images/P2.jpg');"></div>
                            <div class="info-content">
                            <h4>Heritage & History</h4>
                            <p>Established after the city's founding years, the memorial park preserves the legacy of Trece Martires' families and civic heroes through carefully planned sections and chapels.</p>
                            </div>
                        </div>
                    </div>
                    <div class="info-slide" data-index="1">
                        <div class="info-panel">
                            <div class="info-visual" style="background-image: url('assets/images/P1.jpg');"></div>
                            <div class="info-content">
                                <h4>Visiting Hours</h4>
                                <p>Open daily from 6:00 AM to 8:00 PM. Candle lighting and evening prayers are welcomed during special observances and city-declared holidays.</p>
                            </div>
                        </div>
                    </div>
                    <div class="info-slide" data-index="2">
                        <div class="info-panel">
                            <div class="info-visual" style="background-image: url('assets/images/P3.jpg'); "></div>
                            <div class="info-content">
                                <h4>Etiquette & Rules</h4>
                                <p>Maintain a respectful silence, keep walkways clear, and dispose of offerings properly. Pets, smoking, and loud music are not permitted within the grounds.</p>
                            </div>
                        </div>
                    </div>
                    <div class="info-slide" data-index="3">
                        <div class="info-panel">
                            <div class="info-visual" style="background-image: url('assets/images/P4.jpg'); "></div>
                            <div class="info-content">
                                <h4>Services & Support</h4>
                                <p>On-site caretakers assist with directions, plot maintenance, contract inquiries, and pastoral coordination for masses or memorial gatherings.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="actions-section">
            <div class="primary-actions">
                <div class="action-card" onclick="window.location.href='search.php'">
                    <i class='bx bx-search-alt-2 action-icon'></i>
                    <div class="action-title">Find a Grave</div>
                    <p class="action-text">Locate a loved one by name, plot, or burial date with our guided search.</p>
                    <button class="action-button">Search Now</button>
                </div>

                <div class="action-card" onclick="window.location.href='map.php'">
                    <i class='bx bx-map action-icon'></i>
                    <div class="action-title">View Cemetery Map</div>
                    <p class="action-text">Navigate the grounds through an interactive map highlighting every section.</p>
                    <button class="action-button">Open Map</button>
                </div>

                <div class="action-card" onclick="window.location.href='feedback.php'">
                    <i class='bx bx-message-dots action-icon'></i>
                    <div class="action-title">Feedback & Survey</div>
                    <p class="action-text">Tell us about your kiosk visit, request assistance, or complete a short survey.</p>
                    <button class="action-button">Give Feedback</button>
                </div>
            </div>
        </section>
    </div>


    <!-- Settings Modal -->
    <div class="settings-modal-overlay" id="settingsModalOverlay" onclick="closeSettingsModal(event)">
        <div class="settings-modal" onclick="event.stopPropagation()">
            <div class="settings-modal-header">
                <button class="settings-modal-close" onclick="closeSettingsModal()" aria-label="Close">
                    <i class='bx bx-x'></i>
                </button>
                <h1 class="settings-modal-title" id="settingsModalTitle">Language</h1>
            </div>
            <div class="settings-modal-body">
                <div class="settings-card">
                    <h3>
                        <i class='bx bx-globe'></i>
                        <span id="languageOptionsTitle">Language</span>
                    </h3>
                    <div class="settings-option">
                        <label for="language" id="selectLanguageLabel">Select Language</label>
                        <select id="language" name="language">
                            <option value="en">English</option>
                            <option value="fil">Filipino</option>
                            <option value="es">Spanish</option>
                        </select>
                    </div>
                </div>

                <div class="settings-success-message" id="settingsSuccessMessage" style="display: none;">
                    <i class='bx bx-check-circle'></i>
                    <span id="settingsSuccessText">Settings saved successfully!</span>
                </div>

                <button class="save-button" onclick="saveSettings()">
                    <i class='bx bx-save'></i> <span id="saveSettingsText">Save Settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="help-modal-overlay" id="helpModalOverlay" onclick="closeHelpModal(event)">
        <div class="help-modal" onclick="event.stopPropagation()">
            <div class="help-modal-header">
                <h1 class="help-modal-title" id="helpModalTitle">Help & Information</h1>
                <div class="help-modal-header-actions">
                    <button class="help-modal-assistance-btn" onclick="window.location.href='assistance.php'">
                        <span style="position:relative; display:inline-flex; align-items:center; gap:0.25rem;">
                            <i class='bx bx-help-circle'></i>
                        </span>
                        <span id="requestAssistanceText">Request Assistance</span>
                    </button>
                    <button class="help-modal-close" onclick="closeHelpModal()" aria-label="Close">
                        <i class='bx bx-x'></i>
                    </button>
                </div>
            </div>
            <div class="help-modal-body">
                <div class="help-card">
                    <h3>
                        <i class='bx bx-search-alt-2'></i>
                        <span id="findGraveHelpTitle">Find a Grave</span>
                    </h3>
                    <p id="findGraveHelpText">Use the search function to locate graves by entering the deceased person's name, plot number, or burial date. The search will provide detailed information and directions to the grave site.</p>
                </div>

                <div class="help-card">
                    <h3>
                        <i class='bx bx-map'></i>
                        <span id="mapHelpTitle">Cemetery Map</span>
                    </h3>
                    <p id="mapHelpText">Navigate through the interactive map to explore different sections of the cemetery. You can zoom in and out, and click on sections to view more details.</p>
                </div>

                <div class="help-card">
                    <h3>
                        <i class='bx bx-message-dots'></i>
                        <span id="feedbackHelpTitle">Feedback</span>
                    </h3>
                    <p id="feedbackHelpText">Share your experience, provide suggestions, or request assistance through the feedback form. Your input helps us improve our services.</p>
                </div>

                <div class="help-card">
                    <h3>
                        <i class='bx bx-globe'></i>
                        <span id="settingsHelpTitle">Language</span>
                    </h3>
                    <p id="settingsHelpText">Choose your preferred language for the kiosk so that instructions and information are easier to understand.</p>
                </div>

                <div class="help-card">
                    <h3>
                        <i class='bx bx-time'></i>
                        <span id="visitingHoursHelpTitle">Visiting Hours</span>
                    </h3>
                    <p id="visitingHoursHelpText">The cemetery is open daily from 6:00 AM to 8:00 PM. Special observances and city-declared holidays may have extended hours for candle lighting and evening prayers.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
        const infoCarousel = document.getElementById('info-carousel');
        const infoSlides = Array.from(document.querySelectorAll('.info-slide'));
        const dotsContainer = document.getElementById('info-dots');
        let currentInfoIndex = 1;
        let isDragging = false;
        let dragStartX = 0;
        let dragDeltaX = 0;

        function renderDots() {
            dotsContainer.innerHTML = '';
            infoSlides.forEach((_, idx) => {
                const dot = document.createElement('span');
                dot.className = 'info-dot' + (idx === currentInfoIndex ? ' active' : '');
                dot.addEventListener('click', () => {
                    currentInfoIndex = idx;
                    updateCarousel();
                });
                dotsContainer.appendChild(dot);
            });
        }

        function applyCarouselTransform(extraPx = 0) {
            const percentageOffset = (extraPx / window.innerWidth) * 100;
            infoCarousel.style.transform = `translateX(calc(-${currentInfoIndex * 100}% + ${percentageOffset}%))`;
        }

        function updateCarousel() {
            infoSlides.forEach((slide, idx) => {
                slide.classList.toggle('active', idx === currentInfoIndex);
            });
            renderDots();
            applyCarouselTransform();
        }

        function startDrag(clientX) {
            isDragging = true;
            dragStartX = clientX;
            dragDeltaX = 0;
            infoCarousel.classList.add('dragging');
        }

        function updateDrag(clientX) {
            if (!isDragging) return;
            dragDeltaX = clientX - dragStartX;
            applyCarouselTransform(dragDeltaX);
        }

        function endDrag(clientX) {
            if (!isDragging) return;
            dragDeltaX = clientX - dragStartX;
            if (Math.abs(dragDeltaX) > 50) {
                if (dragDeltaX < 0) {
                    currentInfoIndex = (currentInfoIndex + 1) % infoSlides.length;
                } else {
                    currentInfoIndex = (currentInfoIndex - 1 + infoSlides.length) % infoSlides.length;
                }
                updateCarousel();
            }
            isDragging = false;
            infoCarousel.classList.remove('dragging');
            dragDeltaX = 0;
            applyCarouselTransform();
        }

        infoCarousel.addEventListener('mousedown', (e) => {
            startDrag(e.clientX);
        });

        infoCarousel.addEventListener('mousemove', (e) => {
            updateDrag(e.clientX);
        });

        infoCarousel.addEventListener('mouseup', (e) => {
            endDrag(e.clientX);
        });

        infoCarousel.addEventListener('mouseleave', () => {
            if (isDragging) {
                isDragging = false;
                infoCarousel.classList.remove('dragging');
                applyCarouselTransform();
            }
        });

        infoCarousel.addEventListener('touchstart', (e) => {
            const touch = e.touches[0];
            startDrag(touch.clientX);
        }, { passive: true });

        infoCarousel.addEventListener('touchmove', (e) => {
            const touch = e.touches[0];
            updateDrag(touch.clientX);
        }, { passive: true });

        infoCarousel.addEventListener('touchend', (e) => {
            const touch = e.changedTouches[0];
            endDrag(touch.clientX);
        });

        // Settings Modal Functions
        function openSettingsModal() {
            const overlay = document.getElementById('settingsModalOverlay');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            loadSettingsModal();
        }

        function closeSettingsModal(event) {
            // If event exists and click was not on overlay itself, don't close
            // (This handles clicks inside modal - modal has stopPropagation)
            if (event && event.target !== event.currentTarget) {
                return;
            }
            const overlay = document.getElementById('settingsModalOverlay');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Help Modal Functions
        function openHelpModal() {
            const overlay = document.getElementById('helpModalOverlay');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            loadHelpModal();
        }

        function closeHelpModal(event) {
            // If event exists and click was not on overlay itself, don't close
            // (This handles clicks inside modal - modal has stopPropagation)
            if (event && event.target !== event.currentTarget) {
                return;
            }
            const overlay = document.getElementById('helpModalOverlay');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        function loadHelpModal() {
            const savedLanguage = localStorage.getItem('kiosk_language') || 'en';
            applyHelpTranslations(savedLanguage);
        }

        // Settings translations dictionary
        const settingsTranslations = {
            en: {
                settings: 'Language',
                languageOptions: 'Language',
                selectLanguage: 'Select Language',
                saveSettings: 'Save Settings',
                settingsSaved: 'Settings saved successfully!',
                english: 'English',
                filipino: 'Filipino',
                spanish: 'Spanish'
            },
            fil: {
                settings: 'Wika',
                languageOptions: 'Wika',
                selectLanguage: 'Pumili ng Wika',
                saveSettings: 'I-save ang Wika',
                settingsSaved: 'Matagumpay na nai-save ang wika!',
                english: 'Ingles',
                filipino: 'Filipino',
                spanish: 'Espanyol'
            },
            es: {
                settings: 'Idioma',
                languageOptions: 'Idioma',
                selectLanguage: 'Seleccionar Idioma',
                saveSettings: 'Guardar Idioma',
                settingsSaved: '¡Idioma guardado con éxito!',
                english: 'Inglés',
                filipino: 'Filipino',
                spanish: 'Español'
            }
        };

        // Apply settings translations
        function applySettingsTranslations(lang) {
            const trans = settingsTranslations[lang] || settingsTranslations.en;
            
            document.getElementById('settingsModalTitle').textContent = trans.settings;
            document.getElementById('languageOptionsTitle').textContent = trans.languageOptions;
            document.getElementById('selectLanguageLabel').textContent = trans.selectLanguage;
            document.getElementById('saveSettingsText').textContent = trans.saveSettings;
            
            // Update language options
            const langSelect = document.getElementById('language');
            langSelect.querySelector('option[value="en"]').textContent = trans.english;
            langSelect.querySelector('option[value="fil"]').textContent = trans.filipino;
            langSelect.querySelector('option[value="es"]').textContent = trans.spanish;
        }

        // Help translations dictionary
        const helpTranslations = {
            en: {
                helpTitle: 'Help & Information',
                findGraveHelpTitle: 'Find a Grave',
                findGraveHelpText: 'Use the search function to locate graves by entering the deceased person\'s name, plot number, or burial date. The search will provide detailed information and directions to the grave site.',
                mapHelpTitle: 'Cemetery Map',
                mapHelpText: 'Navigate through the interactive map to explore different sections of the cemetery. You can zoom in and out, and click on sections to view more details.',
                feedbackHelpTitle: 'Feedback',
                feedbackHelpText: 'Share your experience, provide suggestions, or request assistance through the feedback form. Your input helps us improve our services.',
                settingsHelpTitle: 'Language',
                settingsHelpText: 'Choose your preferred language for the kiosk so that instructions and information are easier to understand.',
                visitingHoursHelpTitle: 'Visiting Hours',
                visitingHoursHelpText: 'The cemetery is open daily from 6:00 AM to 8:00 PM. Special observances and city-declared holidays may have extended hours for candle lighting and evening prayers.',
                requestAssistance: 'Request Assistance'
            },
            fil: {
                helpTitle: 'Tulong at Impormasyon',
                findGraveHelpTitle: 'Maghanap ng Libingan',
                findGraveHelpText: 'Gamitin ang search function upang mahanap ang mga libingan sa pamamagitan ng paglalagay ng pangalan ng namatay, plot number, o petsa ng paglilibing. Ang paghahanap ay magbibigay ng detalyadong impormasyon at direksyon patungo sa lugar ng libingan.',
                mapHelpTitle: 'Mapa ng Sementeryo',
                mapHelpText: 'Mag-navigate sa pamamagitan ng interaktibong mapa upang galugarin ang iba\'t ibang seksyon ng sementeryo. Maaari kang mag-zoom in at out, at mag-click sa mga seksyon upang makita ang mas maraming detalye.',
                feedbackHelpTitle: 'Feedback',
                feedbackHelpText: 'Ibahagi ang inyong karanasan, magbigay ng mga mungkahi, o humingi ng tulong sa pamamagitan ng feedback form. Ang inyong input ay tumutulong sa amin na mapabuti ang aming serbisyo.',
                settingsHelpTitle: 'Wika',
                settingsHelpText: 'Pumili ng paborito ninyong wika para sa kiosk upang mas madali ninyong maunawaan ang mga tagubilin at impormasyon.',
                visitingHoursHelpTitle: 'Oras ng Pagbisita',
                visitingHoursHelpText: 'Ang sementeryo ay bukas araw-araw mula 6:00 AM hanggang 8:00 PM. Ang mga espesyal na pagdiriwang at mga holiday na ipinahayag ng lungsod ay maaaring may pinalawig na oras para sa pag-iilaw ng kandila at mga panalangin sa gabi.',
                requestAssistance: 'Humingi ng Tulong'
            },
            es: {
                helpTitle: 'Ayuda e Información',
                findGraveHelpTitle: 'Buscar una Tumba',
                findGraveHelpText: 'Use la función de búsqueda para localizar tumbas ingresando el nombre del difunto, número de parcela o fecha de entierro. La búsqueda proporcionará información detallada y direcciones al sitio de la tumba.',
                mapHelpTitle: 'Mapa del Cementerio',
                mapHelpText: 'Navegue a través del mapa interactivo para explorar diferentes secciones del cementerio. Puede acercar y alejar, y hacer clic en las secciones para ver más detalles.',
                feedbackHelpTitle: 'Comentarios',
                feedbackHelpText: 'Comparta su experiencia, proporcione sugerencias o solicite asistencia a través del formulario de comentarios. Sus comentarios nos ayudan a mejorar nuestros servicios.',
                settingsHelpTitle: 'Idioma',
                settingsHelpText: 'Elija su idioma preferido para el quiosco para que las instrucciones e información sean más fáciles de entender.',
                visitingHoursHelpTitle: 'Horario de Visitas',
                visitingHoursHelpText: 'El cementerio está abierto diariamente de 6:00 AM a 8:00 PM. Las observancias especiales y los días festivos declarados por la ciudad pueden tener horarios extendidos para el encendido de velas y oraciones vespertinas.',
                requestAssistance: 'Solicitar Ayuda'
            }
        };

        // Apply help translations
        function applyHelpTranslations(lang) {
            const trans = helpTranslations[lang] || helpTranslations.en;
            
            document.getElementById('helpModalTitle').textContent = trans.helpTitle;
            document.getElementById('findGraveHelpTitle').textContent = trans.findGraveHelpTitle;
            document.getElementById('findGraveHelpText').textContent = trans.findGraveHelpText;
            document.getElementById('mapHelpTitle').textContent = trans.mapHelpTitle;
            document.getElementById('mapHelpText').textContent = trans.mapHelpText;
            document.getElementById('feedbackHelpTitle').textContent = trans.feedbackHelpTitle;
            document.getElementById('feedbackHelpText').textContent = trans.feedbackHelpText;
            document.getElementById('settingsHelpTitle').textContent = trans.settingsHelpTitle;
            document.getElementById('settingsHelpText').textContent = trans.settingsHelpText;
            document.getElementById('visitingHoursHelpTitle').textContent = trans.visitingHoursHelpTitle;
            document.getElementById('visitingHoursHelpText').textContent = trans.visitingHoursHelpText;
            document.getElementById('requestAssistanceText').textContent = trans.requestAssistance;
        }

        // Load settings in modal
        function loadSettingsModal() {
            const savedLanguage = localStorage.getItem('kiosk_language') || 'en';
            document.getElementById('language').value = savedLanguage;
            applySettingsTranslations(savedLanguage);
        }

        // Update brightness value display in modal and apply in real-time
        document.addEventListener('DOMContentLoaded', function() {
            const languageSelect = document.getElementById('language');
            if (languageSelect) {
                languageSelect.addEventListener('change', function(e) {
                    const language = e.target.value;
                    applySettingsTranslations(language);
                });
            }
        });

        // Save settings
        function saveSettings() {
            const language = document.getElementById('language').value;
            
            localStorage.setItem('kiosk_language', language);
            
            // Apply translations to main page
            applyTranslations(language);
            
            // Show success message in selected language
            const trans = settingsTranslations[language] || settingsTranslations.en;
            const successMessage = document.getElementById('settingsSuccessMessage');
            const successText = document.getElementById('settingsSuccessText');
            
            successText.textContent = trans.settingsSaved;
            successMessage.style.display = 'flex';
            
            // Hide success message after 3 seconds
            setTimeout(() => {
                successMessage.style.display = 'none';
            }, 3000);
            
            // Close modal after a short delay
            setTimeout(() => {
                closeSettingsModal();
            }, 500);
        }

        // Translation dictionary for main page
        const translations = {
            en: {
                findGrave: 'Find a Grave',
                findGraveDesc: 'Locate a loved one by name, plot, or burial date with our guided search.',
                searchNow: 'Search Now',
                viewMap: 'View Cemetery Map',
                viewMapDesc: 'Navigate the grounds through an interactive map highlighting every section.',
                openMap: 'Open Map',
                feedback: 'Feedback & Survey',
                feedbackDesc: 'Tell us about your kiosk visit, request assistance, or complete a short survey.',
                giveFeedback: 'Give Feedback',
                heritage: 'Heritage & History',
                heritageDesc: 'Established after the city\'s founding years, the memorial park preserves the legacy of Trece Martires\' families and civic heroes through carefully planned sections and chapels.',
                visitingHours: 'Visiting Hours',
                visitingHoursDesc: 'Open daily from 6:00 AM to 8:00 PM. Candle lighting and evening prayers are welcomed during special observances and city-declared holidays.',
                etiquette: 'Etiquette & Rules',
                etiquetteDesc: 'Maintain a respectful silence, keep walkways clear, and dispose of offerings properly. Pets, smoking, and loud music are not permitted within the grounds.',
                services: 'Services & Support',
                servicesDesc: 'On-site caretakers assist with directions, plot maintenance, contract inquiries, and pastoral coordination for masses or memorial gatherings.'
            },
            fil: {
                findGrave: 'Maghanap ng Libingan',
                findGraveDesc: 'Hanapin ang inyong mahal sa buhay sa pamamagitan ng pangalan, plot, o petsa ng paglilibing gamit ang aming gabay na paghahanap.',
                searchNow: 'Maghanap Ngayon',
                viewMap: 'Tingnan ang Mapa ng Sementeryo',
                viewMapDesc: 'Mag-navigate sa lugar sa pamamagitan ng interaktibong mapa na nagpapakita ng bawat seksyon.',
                openMap: 'Buksan ang Mapa',
                feedback: 'Feedback at Survey',
                feedbackDesc: 'Sabihin sa amin ang tungkol sa inyong pagbisita sa kiosk, humingi ng tulong, o kumpletuhin ang isang maikling survey.',
                giveFeedback: 'Magbigay ng Feedback',
                heritage: 'Pamana at Kasaysayan',
                heritageDesc: 'Itinatag pagkatapos ng mga taon ng pagtatatag ng lungsod, ang memorial park ay napananatili ang pamana ng mga pamilya ng Trece Martires at mga civic hero sa pamamagitan ng maingat na planadong mga seksyon at kapilya.',
                visitingHours: 'Oras ng Pagbisita',
                visitingHoursDesc: 'Bukas araw-araw mula 6:00 AM hanggang 8:00 PM. Ang pag-iilaw ng kandila at mga panalangin sa gabi ay tinatanggap sa panahon ng mga espesyal na pagdiriwang at mga holiday na ipinahayag ng lungsod.',
                etiquette: 'Etiketa at Mga Tuntunin',
                etiquetteDesc: 'Panatilihin ang mapagpakumbabang katahimikan, panatilihing malinis ang mga daanan, at itapon nang maayos ang mga alay. Ang mga alaga, paninigarilyo, at malakas na musika ay hindi pinapayagan sa lugar.',
                services: 'Serbisyo at Suporta',
                servicesDesc: 'Ang mga tagapangalaga sa lugar ay tumutulong sa direksyon, pagpapanatili ng plot, mga tanong sa kontrata, at koordinasyon ng pastoral para sa mga misa o pagtitipon sa alaala.'
            },
            es: {
                findGrave: 'Buscar una Tumba',
                findGraveDesc: 'Localice a un ser querido por nombre, parcela o fecha de entierro con nuestra búsqueda guiada.',
                searchNow: 'Buscar Ahora',
                viewMap: 'Ver Mapa del Cementerio',
                viewMapDesc: 'Navegue por los terrenos a través de un mapa interactivo que destaca cada sección.',
                openMap: 'Abrir Mapa',
                feedback: 'Comentarios y Encuesta',
                feedbackDesc: 'Cuéntenos sobre su visita al quiosco, solicite asistencia o complete una breve encuesta.',
                giveFeedback: 'Dar Comentarios',
                heritage: 'Patrimonio e Historia',
                heritageDesc: 'Establecido después de los años fundacionales de la ciudad, el parque conmemorativo preserva el legado de las familias de Trece Martires y los héroes cívicos a través de secciones y capillas cuidadosamente planificadas.',
                visitingHours: 'Horario de Visitas',
                visitingHoursDesc: 'Abierto diariamente de 6:00 AM a 8:00 PM. El encendido de velas y las oraciones vespertinas son bienvenidos durante observancias especiales y días festivos declarados por la ciudad.',
                etiquette: 'Etiqueta y Reglas',
                etiquetteDesc: 'Mantenga un silencio respetuoso, mantenga los caminos despejados y deseche las ofrendas adecuadamente. No se permiten mascotas, fumar ni música alta dentro de los terrenos.',
                services: 'Servicios y Soporte',
                servicesDesc: 'Los cuidadores en el lugar ayudan con direcciones, mantenimiento de parcelas, consultas de contratos y coordinación pastoral para misas o reuniones conmemorativas.'
            }
        };

        // Apply translations
        function applyTranslations(lang) {
            const trans = translations[lang] || translations.en;
            
            // Translate action cards
            const cards = document.querySelectorAll('.action-card');
            if (cards.length >= 3) {
                cards[0].querySelector('.action-title').textContent = trans.findGrave;
                cards[0].querySelector('.action-text').textContent = trans.findGraveDesc;
                cards[0].querySelector('.action-button').textContent = trans.searchNow;
                
                cards[1].querySelector('.action-title').textContent = trans.viewMap;
                cards[1].querySelector('.action-text').textContent = trans.viewMapDesc;
                cards[1].querySelector('.action-button').textContent = trans.openMap;
                
                cards[2].querySelector('.action-title').textContent = trans.feedback;
                cards[2].querySelector('.action-text').textContent = trans.feedbackDesc;
                cards[2].querySelector('.action-button').textContent = trans.giveFeedback;
            }
            
            // Translate info slides
            const slides = document.querySelectorAll('.info-slide');
            if (slides.length >= 4) {
                slides[0].querySelector('.info-content h4').textContent = trans.heritage;
                slides[0].querySelector('.info-content p').textContent = trans.heritageDesc;
                
                slides[1].querySelector('.info-content h4').textContent = trans.visitingHours;
                slides[1].querySelector('.info-content p').textContent = trans.visitingHoursDesc;
                
                slides[2].querySelector('.info-content h4').textContent = trans.etiquette;
                slides[2].querySelector('.info-content p').textContent = trans.etiquetteDesc;
                
                slides[3].querySelector('.info-content h4').textContent = trans.services;
                slides[3].querySelector('.info-content p').textContent = trans.servicesDesc;
            }
        }

        // Load and apply saved settings
        function loadSettings() {
            const savedLanguage = localStorage.getItem('kiosk_language') || 'en';

            // Apply language translations
            applyTranslations(savedLanguage);
        }

        // Help tooltip bubble logic
        document.addEventListener('DOMContentLoaded', function () {
            const helpButton = document.querySelector('.help-toggle .help-btn');
            const helpBubble = document.getElementById('helpTooltipBubble');
            let helpBubbleDismissed = false;
            let wasScrolledDown = false;

            function showHelpBubble() {
                if (!helpBubble || helpBubbleDismissed) return;
                helpBubble.classList.add('visible');
                if (helpBubble._hideTimer) {
                    clearTimeout(helpBubble._hideTimer);
                }
                helpBubble._hideTimer = setTimeout(() => {
                    hideHelpBubble();
                }, 6000);
            }

            function hideHelpBubble() {
                if (!helpBubble) return;
                helpBubble.classList.remove('visible');
            }

            // Show when page first loads
            showHelpBubble();

            // Show again when user scrolls back to the top after scrolling down
            window.addEventListener('scroll', function () {
                if (window.scrollY > 20) {
                    wasScrolledDown = true;
                    return;
                }
                if (wasScrolledDown && window.scrollY <= 20) {
                    wasScrolledDown = false;
                    showHelpBubble();
                }
            });

            // Dismiss when help button is clicked
            if (helpButton) {
                helpButton.addEventListener('click', function () {
                    helpBubbleDismissed = true;
                    hideHelpBubble();
                });
            }
        });

        updateCarousel();
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
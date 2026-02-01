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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/ui-settings.css">
    <style>
        html, body {
            height: 100%;
        }
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            overflow: hidden;
            background-size: cover;
            background-position: center;
            color: white;
            font-family: 'Playfair Display', 'Cinzel', serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            cursor: pointer;
        }
        .welcome-container {
            text-align: center;
            padding: 2rem;
            position: relative;
            z-index: 1;
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }
        .welcome-container::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(180deg, rgba(7, 17, 26, 0.65) 0%, rgba(7, 17, 26, 0.35) 60%, rgba(7, 17, 26, 0.65) 100%);
            z-index: -1;
        }
        .welcome-container video {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.75;
            object-fit: cover;
        }
        .welcome-title {
            display: inline-flex;
            align-items: center;
            gap: 1rem;
            font-size: 56px;
            font-family: 'Cinzel', 'Playfair Display', serif;
            font-weight: 600;
            margin-bottom: 2rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        .welcome-title img {
            height: 70px;
            width: auto;
        }
        .welcome-message {
            font-size: 24px;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin-bottom: 3rem;
            line-height: 1.6;
        }
        .touch-indicator {
            font-size: 32px;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255, 255, 255, 0.95);
            text-shadow: 2px 2px 8px rgba(0,0,0,0.5);
            margin-top: 2rem;
            padding: 20px 40px;
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50px;
            backdrop-filter: blur(10px);
            animation: pulse 2s ease-in-out infinite;
            pointer-events: none;
        }
        @keyframes pulse {
            0%, 100% {
                opacity: 0.9;
                transform: scale(1);
            }
            50% {
                opacity: 1;
                transform: scale(1.02);
            }
        }
        .btn-continue {
            display: none;
        }
        .clock {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.45);
            color: white;
            padding: 12px 20px;
            border-radius: 24px;
            font-size: 18px;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            z-index: 1000;
            text-align: right;
            line-height: 1.3;
            min-width: 170px;
        }
        .clock-date {
            font-size: 13px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            opacity: 0.85;
        }
        .clock-day {
            font-size: 15px;
            font-weight: 600;
            opacity: 0.9;
        }
        .clock-time {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
        html {
            overflow-x: hidden;
        }
        body {
            max-width: 100vw;
        }
        /* PC and large screens: scale up so content doesn't look tiny */
        @media (min-width: 1440px) {
            .welcome-title {
                font-size: 64px;
                gap: 1.25rem;
            }
            .welcome-title img {
                height: 80px;
            }
            .welcome-message {
                font-size: 26px;
                margin-bottom: 3.5rem;
            }
            .touch-indicator {
                font-size: 28px;
                padding: 22px 44px;
            }
            .clock {
                font-size: 20px;
                padding: 14px 24px;
                min-width: 200px;
            }
        }
        @media (min-width: 1920px) {
            .welcome-title {
                font-size: 72px;
            }
            .welcome-title img {
                height: 90px;
            }
            .welcome-message {
                font-size: 28px;
            }
            .touch-indicator {
                font-size: 30px;
                padding: 24px 48px;
            }
        }
        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .welcome-title {
                font-size: 42px;
            }
            .welcome-message {
                font-size: 20px;
                padding: 0 1rem;
            }
            .touch-indicator {
                font-size: 26px;
                padding: 16px 32px;
            }
            .btn-continue {
                padding: 18px 40px;
                font-size: 22px;
            }
        }
        @media (max-width: 768px) {
            body {
                overflow-y: auto;
            }
            .welcome-container {
                padding: 1.5rem 1.25rem;
                justify-content: flex-start;
            }
            .welcome-title {
                flex-direction: column;
                text-align: center;
                gap: 0.75rem;
                font-size: 32px;
                margin-bottom: 1.5rem;
            }
            .welcome-title img {
                height: 56px;
            }
            .welcome-message {
                font-size: 18px;
                margin-bottom: 2rem;
            }
            .touch-indicator {
                font-size: 20px;
                padding: 14px 28px;
                margin-top: 1.5rem;
            }
            .btn-continue {
                padding: 14px 32px;
                font-size: 18px;
                letter-spacing: 0.06em;
            }
            .clock {
                position: static;
                margin-top: 1.5rem;
                align-self: flex-end;
            }
        }
        @media (max-width: 480px) {
            .welcome-container {
                padding: 1.25rem 1rem;
            }
            .welcome-title {
                font-size: 26px;
            }
            .welcome-message {
                font-size: 16px;
            }
            .touch-indicator {
                font-size: 18px;
                padding: 12px 24px;
                margin-top: 1rem;
            }
            .btn-continue {
                width: 100%;
                max-width: 320px;
                text-align: center;
                padding: 12px 24px;
                font-size: 16px;
            }
            .clock {
                width: 100%;
                max-width: 220px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <video autoplay loop muted playsinline>
            <source src="assets/videos/kiosk.mp4" type="video/mp4">
        </video>
        <h1 class="welcome-title">
            <img src="assets/images/tmmp-logo.png" alt="Trece Martires Memorial Park Logo">
            Trece Martires Memorial Park
        </h1>
        <p class="welcome-message">
        Where every visit honors memory, faith, and family—find guidance, comfort, and the resting place of those you cherish.
        </p>
        <div class="touch-indicator">Touch the screen to start</div>
        <a href="main.php" class="btn btn-continue">TOUCH SCREEN TO BEGIN</a>
    </div>

    <div class="clock" id="clock"></div>

    <script>
        function updateClock() {
            const now = new Date();
            const dateString = now.toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
            const dayString = now.toLocaleDateString('en-US', { weekday: 'long' });
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('clock').innerHTML = `
                <div class="clock-date">${dateString}</div>
                <div class="clock-day">${dayString}</div>
                <div class="clock-time">${timeString}</div>
            `;
        }
        
        // Update clock immediately and then every second
        updateClock();
        setInterval(updateClock, 1000);

        // Make entire screen clickable/touchable for kiosk device
        function navigateToMain() {
            window.location.href = 'main.php';
        }

        // Handle both click and touch events
        document.body.addEventListener('click', navigateToMain);
        document.body.addEventListener('touchend', function(e) {
            e.preventDefault();
            navigateToMain();
        });
    </script>
    <script src="assets/js/ui-settings.js"></script>
</body>
</html> 
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ((int) ($_SESSION['user_id'] ?? 0) > 0 && ($_SESSION['user_role'] ?? '') === 'admin') {
    header('Location: ../admin/admin.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Mindanao Travel & Tours</title>
    <style>    
                :root {
                --sky: #0EA5E9;
                --sky-dark: #0369A1;
                --ink: #0F1724;
                --muted: #64748B;
                --line: #DCE8EF;
                --soft: #F0FAFF;
                --white: #FFFFFF;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                }

                * {
                box-sizing: border-box;
                }

                html, body {
                min-height: 100%;
                margin: 0;
                }

                body {
                background: var(--soft);
                color: var(--ink);
                overflow-x: hidden;
                }

                /* Layout */
                .login-page {
                min-height: 100vh;
                display: grid;
                grid-template-columns: minmax(0, 1.15fr) minmax(390px, .85fr);
                }

                /* Visual Section */
                .visual {
                position: relative;
                min-height: 100vh;
                overflow: hidden;
                background: #0b3045;
                }

                .visual::after {
                content: "";
                position: absolute;
                inset: 0;
                background: linear-gradient(90deg, rgba(3, 31, 52, .68), rgba(3, 31, 52, .12) 76%),
                            linear-gradient(0deg, rgba(3, 31, 52, .46), transparent 55%);
                z-index: 1;
                }

                .visual-slides,
                .visual-slide {
                position: absolute;
                inset: 0;
                }

                .visual-slide {
                background-size: cover;
                background-position: center;
                opacity: 0;
                transform: scale(1.04);
                transition: opacity 900ms ease, transform 5s ease;
                }

                .visual-slide.active {
                opacity: 1;
                transform: scale(1);
                }

                /* Branding */
                .brand {
                position: absolute;
                top: 34px;
                left: clamp(24px, 6vw, 86px);
                z-index: 2;
                display: flex;
                align-items: center;
                gap: 12px;
                color: #fff;
                }

                .brand-mark {
                display: grid;
                place-items: center;
                width: 52px;
                height: 52px;
                border-radius: 12px;
                background: #fff;
                border: 1px solid rgba(255, 255, 255, .7);
                overflow: hidden;
                }

                .brand-mark img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                }

                .brand strong {
                display: block;
                font-size: 17px;
                }

                .brand small {
                display: block;
                margin-top: 2px;
                color: rgba(255, 255, 255, .75);
                }

                /* Visual Copy */
                .visual-copy {
                position: absolute;
                left: clamp(24px, 6vw, 86px);
                bottom: clamp(80px, 12vh, 140px);
                z-index: 2;
                max-width: 580px;
                color: #fff;
                }

                .eyebrow {
                font-size: 12px;
                font-weight: 800;
                letter-spacing: 2px;
                text-transform: uppercase;
                color: #BAE6FD;
                }

                .visual-copy h1 {
                margin: 14px 0 16px;
                font-size: clamp(38px, 5vw, 70px);
                line-height: 1.02;
                letter-spacing: -2px;
                }

                .visual-copy p {
                max-width: 500px;
                margin: 0;
                color: rgba(255, 255, 255, .84);
                font-size: 16px;
                line-height: 1.65;
                }

                /* Slide Dots */
                .slide-dots {
                position: absolute;
                left: clamp(24px, 6vw, 86px);
                bottom: 40px;
                z-index: 3;
                display: flex;
                gap: 7px;
                }

                .slide-dot {
                width: 7px;
                height: 7px;
                border: 0;
                border-radius: 99px;
                background: rgba(255, 255, 255, .5);
                padding: 0;
                }

                .slide-dot.active {
                width: 26px;
                background: #fff;
                }

                /* Form Side */
                .form-side {
                display: grid;
                place-items: center;
                padding: 32px;
                background: linear-gradient(135deg, #F0FAFF, #fff);
                }

                .form-wrap {
                width: min(100%, 410px);
                }

                .mobile-brand {
                display: none;
                }

                /* Form Header */
                .form-header h2 {
                margin: 0;
                font-size: 30px;
                letter-spacing: -.7px;
                }

                .form-header p {
                margin: 9px 0 28px;
                color: var(--muted);
                line-height: 1.55;
                }

                /* Fields */
                .field {
                display: block;
                margin-bottom: 16px;
                }

                .field span {
                display: block;
                margin-bottom: 7px;
                font-size: 13px;
                font-weight: 700;
                }

                .field input {
                width: 100%;
                height: 48px;
                padding: 0 14px;
                border: 1px solid var(--line);
                border-radius: 11px;
                background: #fff;
                color: var(--ink);
                font: inherit;
                outline: none;
                transition: border-color 200ms, box-shadow 200ms;
                }

                .field input:focus {
                border-color: var(--sky);
                box-shadow: 0 0 0 4px rgba(14, 165, 233, .13);
                }

                /* Password Row */
                .password-row {
                position: relative;
                }

                .password-row input {
                padding-right: 74px;
                }

                .show-password {
                position: absolute;
                right: 10px;
                bottom: 8px;
                border: 0;
                background: transparent;
                color: var(--sky-dark);
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
                }

                /* Options */
                .form-options {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                margin: 2px 0 22px;
                font-size: 13px;
                }

                .remember {
                display: flex;
                align-items: center;
                gap: 7px;
                color: var(--muted);
                }

                .remember input {
                accent-color: var(--sky);
                }

                /* Links */
                a {
                color: var(--sky-dark);
                font-weight: 700;
                text-decoration: none;
                }

                a:hover {
                text-decoration: underline;
                }

                /* Submit Button */
                .submit {
                width: 100%;
                height: 50px;
                border: 0;
                border-radius: 11px;
                background: var(--sky);
                color: #fff;
                font: inherit;
                font-weight: 800;
                cursor: pointer;
                box-shadow: 0 10px 22px rgba(14, 165, 233, .2);
                transition: background 200ms, transform 200ms, box-shadow 200ms;
                }

                .submit:hover {
                background: var(--sky-dark);
                transform: translateY(-2px);
                box-shadow: 0 13px 26px rgba(3, 105, 161, .22);
                }

                /* Divider */
                .divider {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 25px 0;
                color: #94A3B8;
                font-size: 12px;
                }

                .divider::before,
                .divider::after {
                content: "";
                height: 1px;
                flex: 1;
                background: var(--line);
                }

                /* Social Buttons */
                .social {
                width: 100%;
                height: 46px;
                border: 1px solid var(--line);
                border-radius: 11px;
                background: #fff;
                color: var(--ink);
                font: inherit;
                font-weight: 700;
                cursor: pointer;
                transition: border-color 200ms, background 200ms;
                }

                .social:hover {
                border-color: #9BD8F2;
                background: #F8FDFF;
                }

                /* Signup */
                .signup {
                text-align: center;
                margin: 24px 0 0;
                color: var(--muted);
                font-size: 13px;
                }

                /* Message */
                .message {
                min-height: 20px;
                margin: 12px 0 0;
                text-align: center;
                color: var(--sky-dark);
                font-size: 13px;
                }

                /* Footer Note */
                .footer-note {
                text-align: center;
                color: #94A3B8;
                font-size: 11px;
                margin-top: 34px;
                }

                /* Responsive */
                @media (max-width: 820px) {
                .login-page {
                    display: block;
                }
                .visual {
                    min-height: 390px;
                }
                .visual-copy {
                    bottom: 48px;
                }
                }
    </style>
</head>
<body>
    <main class="login-page">
        <section class="visual" aria-label="Mindanao travel highlights">
            <div class="visual-slides" aria-hidden="true">
                <div class="visual-slide active" style="background-image:url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1600&q=80')"></div>
                <div class="visual-slide" style="background-image:url('https://images.unsplash.com/photo-1530789253388-582c481c54b0?auto=format&fit=crop&w=1600&q=80')"></div>
                <div class="visual-slide" style="background-image:url('https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?auto=format&fit=crop&w=1600&q=80')"></div>
                <div class="visual-slide" style="background-image:url('https://images.unsplash.com/photo-1518509562904-e7ef99cdcc86?auto=format&fit=crop&w=1600&q=80')"></div>
            </div>
            <div class="brand">
                <div class="brand-mark"><img src="../assets/logo/logo.png" alt="MTR logo"></div>
                <div><strong>Mindanao Travel & Tours</strong><small>Plan something unforgettable</small></div>
            </div>
            <div class="visual-copy">
                <div class="eyebrow">Your next story starts here</div>
                <h1>Find your way to wonder.</h1>
                <p>Explore beautiful islands, vibrant culture, and unforgettable adventures across Mindanao — all in one place.</p>
            </div>
            <div class="slide-dots" id="slideDots" aria-hidden="true"></div>
        </section>

        <section class="form-side">
            <div class="form-wrap">
                <div class="mobile-brand brand">
                    <div class="brand-mark"><img src="../assets/logo/logo.png" alt="MTR logo"></div>
                    <div><strong>Mindanao Travel & Tours</strong><small>Plan something unforgettable</small></div>
                </div>
                <div class="form-header">
                    <h2>Welcome back</h2>
                    <p>Sign in to continue planning your perfect Mindanao escape.</p>
                </div>
                <form id="loginForm">
                    <label class="field">
                        <span>Email address</span>
                        <input id="email" type="email" placeholder="you@example.com" autocomplete="email" required>
                    </label>
                    <label class="field password-row">
                        <span>Password</span>
                        <input id="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button class="show-password" type="button" id="togglePassword">Show</button>
                    </label>
                    <div class="form-options">
                        
                        <a href="#" id="forgotLink">Forgot password?</a>
                    </div>
                    <button class="submit" type="submit">Sign in to dashboard</button>
                    <div class="message" id="message" role="status"></div>
                </form>
                <div class="signup">
                    Don’t have an account? <a href="signup.php">Create account</a>
                </div>
                <div class="footer-note">Safe, simple, and made for memorable journeys.</div>
            </div>
        </section>
    </main>

    <script>
        const loginForm = document.querySelector('#loginForm');
        const loginMessage = document.querySelector('#message');
        const passwordInput = document.querySelector('#password');
        const togglePassword = document.querySelector('#togglePassword');

        togglePassword.addEventListener('click', () => {
            const isPasswordVisible = passwordInput.type === 'text';
            passwordInput.type = isPasswordVisible ? 'password' : 'text';
            togglePassword.textContent = isPasswordVisible ? 'Show' : 'Hide';
            togglePassword.setAttribute('aria-label', isPasswordVisible ? 'Show password' : 'Hide password');
        });

        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            loginMessage.textContent = 'Signing you in...';

            try {
                const response = await fetch('../api/loginbck.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: document.querySelector('#email').value,
                        password: document.querySelector('#password').value
                    })
                });
                const result = await response.json();
                if (result.requires_verification) {
                    window.location.href = `verify.php?email=${encodeURIComponent(result.email)}&sent=${result.email_sent ? 1 : 0}`;
                    return;
                }
                if (!response.ok) throw new Error(result.error || 'Unable to sign in.');
                const role = String(result.user.role || '').trim().toLowerCase();
                if (role === 'admin') {
                    window.location.href = '../admin/admin.php';
                } else if (role === 'employee') {
                    window.location.href = '../employee/dashboard.php';
                } else if (role === 'driver') {
                    window.location.href = '../driver/driverdashboard.php';
                } else {
                    window.location.href = 'dashboard.php';
                }
            } catch (error) {
                loginMessage.textContent = error.message;
            }
        });
    </script>

</body>
</html>
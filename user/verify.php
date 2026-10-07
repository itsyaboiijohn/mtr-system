<?php
$email = strtolower(trim((string) ($_GET['email'] ?? '')));
$sent = ($_GET['sent'] ?? '1') !== '0';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify email | Mindanao Travel & Tours</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #0f1724; background: #f0faff; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 24px; background: radial-gradient(circle at 10% 0, #d8f4ff, transparent 40%), #f0faff; }
        .card { width: min(100%, 460px); padding: 34px; border: 1px solid #dce8ef; border-radius: 16px; background: #fff; box-shadow: 0 20px 50px rgba(2, 6, 23, .08); }
        h1 { margin: 0 0 9px; font-size: 28px; }
        p { color: #64748b; line-height: 1.5; font-size: 14px; }
        input { width: 100%; height: 54px; margin: 12px 0; border: 1px solid #dce8ef; border-radius: 9px; font: inherit; font-size: 24px; letter-spacing: 8px; text-align: center; }
        input:focus { outline: 3px solid rgba(14, 165, 233, .15); border-color: #0ea5e9; }
        button.primary { width: 100%; height: 48px; border: 0; border-radius: 9px; background: #0ea5e9; color: #fff; font: inherit; font-weight: 800; cursor: pointer; }
        button.primary:hover { background: #0369a1; }
        button.link { border: 0; background: none; color: #0369a1; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
        button:disabled { opacity: .6; cursor: default; }
        .message { min-height: 22px; margin-top: 12px; color: #b42318; font-size: 13px; text-align: center; }
        .message.ok { color: #15803d; }
        .row { display: flex; justify-content: space-between; margin-top: 14px; }
        .row a { color: #0369a1; font-size: 13px; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Verify your email</h1>
        <p>Enter the 6-digit code we sent to <strong id="emailLabel"></strong>. It expires in 10 minutes.</p>
        <form id="verifyForm">
            <input id="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
            <button class="primary" type="submit">Verify and continue</button>
        </form>
        <div class="message<?= $sent ? ' ok' : '' ?>" id="message" role="status"><?= $sent ? '' : 'We could not send the email yet. Press "Resend code".' ?></div>
        <div class="row"><button class="link" id="resend" type="button">Resend code</button><a href="login.php">Back to sign in</a></div>
    </main>
    <script>
        const email = <?= json_encode($email) ?>;
        const message = document.querySelector('#message');
        document.querySelector('#emailLabel').textContent = email;
        if (!email) window.location.href = 'signup.php';
        const call = async (body) => {
            const response = await fetch('../api/verify-email.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, ...body }) });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Request failed.');
            return result;
        };
        const show = (text, ok) => { message.textContent = text; message.classList.toggle('ok', ok); };
        document.querySelector('#verifyForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            show('Verifying...', true);
            try {
                const result = await call({ action: 'verify', code: document.querySelector('#code').value });
                const role = String(result.user.role || '').toLowerCase();
                window.location.href = role === 'driver' ? '../driver/driverdashboard.php' : role === 'employee' ? '../employee/dashboard.php' : 'dashboard.php';
            } catch (error) { show(error.message, false); }
        });
        document.querySelector('#resend').addEventListener('click', async (event) => {
            event.target.disabled = true;
            try { show((await call({ action: 'resend' })).message, true); } catch (error) { show(error.message, false); }
            setTimeout(() => { event.target.disabled = false; }, 60000);
        });
    </script>
</body>
</html>

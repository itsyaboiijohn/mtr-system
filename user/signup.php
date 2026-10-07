<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account | Mindanao Travel & Tours</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #0f1724; background: #f0faff; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 24px; background: radial-gradient(circle at 10% 0, #d8f4ff, transparent 40%), #f0faff; }
        .card { width: min(100%, 620px); padding: 34px; border: 1px solid #dce8ef; border-radius: 16px; background: #fff; box-shadow: 0 20px 50px rgba(2, 6, 23, .08); }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; color: #0f1724; text-decoration: none; }
        .logo { width: 44px; height: 44px; overflow: hidden; border-radius: 10px; background: #fff; }
        .logo img { width: 100%; height: 100%; object-fit: contain; }
        .brand strong, .brand small { display: block; }
        .brand small { margin-top: 3px; color: #64748b; font-size: 12px; }
        h1 { margin: 0; font-size: 30px; }
        .intro { margin: 9px 0 25px; color: #64748b; line-height: 1.5; }
        .account-choice { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 24px; }
        .account-choice label { position: relative; margin: 0; }
        .account-choice input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .account-option { display: block; min-height: 76px; padding: 14px; border: 1px solid #dce8ef; border-radius: 10px; cursor: pointer; }
        .account-option strong, .account-option small { display: block; }
        .account-option strong { margin-bottom: 5px; font-size: 14px; }
        .account-option small { color: #64748b; font-size: 12px; font-weight: 400; line-height: 1.4; }
        .account-choice input:checked + .account-option { border-color: #0ea5e9; background: #f0faff; box-shadow: 0 0 0 3px rgba(14, 165, 233, .12); }
        .form-section { margin: 25px 0 20px; padding-top: 22px; border-top: 1px solid #dce8ef; }
        .form-section h2 { margin: 0; font-size: 18px; }
        .form-section p { margin: 6px 0 18px; color: #64748b; font-size: 13px; line-height: 1.5; }
        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
        input[type="file"] { width: 100%; min-height: 46px; margin-top: 7px; padding: 10px; border: 1px solid #dce8ef; border-radius: 9px; background: #fff; font: inherit; font-size: 12px; }
        .check-field { display: flex; align-items: flex-start; gap: 9px; padding-top: 8px; font-size: 12px; line-height: 1.45; }
        .check-field input { width: 16px; height: 16px; flex: 0 0 auto; margin: 1px 0 0; }
        .hidden { display: none; }
        label { display: block; margin-bottom: 15px; font-size: 13px; font-weight: 700; }
        input:not([type="radio"]):not([type="file"]):not([type="checkbox"]) { width: 100%; height: 46px; margin-top: 7px; padding: 0 13px; border: 1px solid #dce8ef; border-radius: 9px; font: inherit; }
        input:focus { outline: 3px solid rgba(14, 165, 233, .15); border-color: #0ea5e9; }
        button { width: 100%; height: 48px; margin-top: 7px; border: 0; border-radius: 9px; background: #0ea5e9; color: #fff; font: inherit; font-weight: 800; cursor: pointer; }
        button:hover { background: #0369a1; }
        .message { min-height: 22px; margin-top: 13px; color: #b42318; font-size: 13px; text-align: center; }
        .back { display: block; margin-top: 22px; color: #0369a1; font-size: 13px; font-weight: 700; text-align: center; text-decoration: none; }
        @media (max-width: 560px) { .card { padding: 26px 20px; } .account-choice, .field-grid { grid-template-columns: 1fr; } h1 { font-size: 27px; } }
    </style>
</head>
<body>
    <main class="card">
        <a class="brand" href="dashboard.html">
            <span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span>
            <span><strong>Mindanao Travel & Tours</strong><small>Plan your next escape</small></span>
        </a>
        <h1>Create your account</h1>
        <p class="intro">Choose how you will use Mindanao Travel & Tours.</p>
        <form id="registerForm" enctype="multipart/form-data">
            <div class="account-choice" role="radiogroup" aria-label="Account type">
                <label><input type="radio" name="role" value="customer" checked><span class="account-option"><strong>Customer</strong><small>Plan trips, save packages, and manage bookings.</small></span></label>
                <label><input type="radio" name="role" value="driver"><span class="account-option"><strong>Company driver</strong><small>Apply to drive MTR-owned vehicles for touring guests and trips.</small></span></label>
            </div>
            <div class="field-grid">
                <label>Full name<input name="full_name" type="text" autocomplete="name" required></label>
                <label>Email address<input name="email" type="email" autocomplete="email" required></label>
                <label>Mobile number<input name="phone" type="tel" autocomplete="tel"></label>
                <label>Password<input name="password" type="password" minlength="8" autocomplete="new-password" required></label>
                <label>Confirm password<input name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required></label>
            </div>
            <section class="form-section hidden" id="driverDetails" aria-labelledby="driverDetailsTitle">
                <h2 id="driverDetailsTitle">Driver documents</h2>
                <p>Submit your driver qualification documents for review. MTR owns the vehicles, and an admin will assign a company vehicle after approval.</p>
                <div class="field-grid">
                    <label>Training certificate copy (image or PDF)<input name="training_certificate_image" type="file" accept="image/*,application/pdf"></label>
                    <label>Driver license photo<input name="license_image" type="file" accept="image/*"></label>
                    <label>Test-drive certificate or result<input name="test_drive_image" type="file" accept="image/*,application/pdf"></label>
                    <label class="check-field"><input name="training_completed" type="checkbox" value="yes"> I confirm that I completed and passed the required training at the MTR main office.</label>
                </div>
            </section>
            <button type="submit">Create account</button>
            <div class="message" id="message" role="status"></div>
        </form>
        <a class="back" href="login.php">Already have an account? Sign in</a>
    </main>
    <script>
        const form = document.querySelector('#registerForm');
        const message = document.querySelector('#message');
        const driverDetails = document.querySelector('#driverDetails');
        const roleInputs = document.querySelectorAll('input[name="role"]');
        const driverFields = driverDetails.querySelectorAll('input:not([type="checkbox"])');
        const setAccountType = () => {
            const isDriver = form.elements.role.value === 'driver';
            driverDetails.classList.toggle('hidden', !isDriver);
            driverFields.forEach((field) => { field.required = isDriver; });
        };
        roleInputs.forEach((input) => input.addEventListener('change', setAccountType));
        setAccountType();
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const values = Object.fromEntries(new FormData(form));
            if (values.password !== values.password_confirmation) {
                message.textContent = 'Passwords do not match.';
                return;
            }
            message.textContent = 'Creating your account...';
            try {
                const response = await fetch('../api/register.php', { method: 'POST', body: new FormData(form) });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error(responseText.trim() || `Registration server returned HTTP ${response.status}.`);
                }
                if (!response.ok) throw new Error(result.error || 'Registration failed.');
                message.textContent = 'Account created. Redirecting to email verification...';
                window.location.href = `verify.php?email=${encodeURIComponent(result.email)}&sent=${result.email_sent ? 1 : 0}`;
            } catch (error) {
                message.textContent = error.message;
            }
        });
    </script>
</body>
</html>

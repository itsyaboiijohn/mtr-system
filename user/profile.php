<?php
require_once __DIR__ . '/../api/auth.php';
$database = require_once __DIR__ . '/../api/db_con.php';
$userId = require_user_auth($_SERVER['REQUEST_METHOD'] === 'POST');

$profileStmt = $database->prepare('SELECT User_ID, role, full_name, email, phone, created_at FROM users WHERE User_ID = ? LIMIT 1');
$profileStmt->execute([$userId]);
$profileUser = $profileStmt->fetch();

if (!$profileUser) {
	$_SESSION = [];
	header('Location: login.php');
	exit;
}

$profileMessage = '';
$profileError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
	$fullName = trim((string) ($_POST['full_name'] ?? ''));
	$email = strtolower(trim((string) ($_POST['email'] ?? '')));
	$phone = trim((string) ($_POST['phone'] ?? ''));

	if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$profileError = 'Please provide your full name and a valid email address.';
	} else {
		$emailCheck = $database->prepare('SELECT User_ID FROM users WHERE email = ? AND User_ID <> ? LIMIT 1');
		$emailCheck->execute([$email, $userId]);

		if ($emailCheck->fetch()) {
			$profileError = 'That email address is already in use.';
		} else {
			$updateProfile = $database->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE User_ID = ?');
			$updateProfile->execute([$fullName, $email, $phone !== '' ? $phone : null, $userId]);
			$_SESSION['user_name'] = $fullName;
			$_SESSION['user_email'] = $email;
			$profileMessage = 'Your profile details were saved.';
		}
	}

	$profileStmt->execute([$userId]);
	$profileUser = $profileStmt->fetch() ?: $profileUser;
}

$nameParts = preg_split('/\s+/', trim((string) $profileUser['full_name']), 2);
$initials = strtoupper(substr($nameParts[0] ?? 'U', 0, 1) . substr($nameParts[1] ?? '', 0, 1));
$initials = $initials !== '' ? $initials : 'U';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Profile | Mindanao Travel & Tours</title>
	<style>
		:root { --bg:#f0faff; --card:#fff; --ink:#0f1724; --muted:#64748b; --line:#dce8ef; --accent:#0ea5e9; --accent-dark:#0369a1; --soft:#e6f9ff; --green:#047857; --shadow:0 18px 45px rgba(2,6,23,.07); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
		* { box-sizing:border-box; }
		html { scroll-behavior:smooth; }
		body { min-height:100vh; margin:0; background:radial-gradient(circle at 90% 0,#d8f4ff 0,transparent 34%),var(--bg); color:var(--ink); }
		a { color:inherit; text-decoration:none; } button,input,select { font:inherit; } button { cursor:pointer; }
		.page { width:min(1160px,calc(100% - 40px)); margin:auto; padding:26px 0 55px; }
		.topbar { display:flex; align-items:center; justify-content:space-between; gap:24px; margin-bottom:48px; }
		.brand { display:flex; align-items:center; gap:12px; }.logo { display:grid; width:44px; height:44px; place-items:center; overflow:hidden; border:1px solid rgba(14,165,233,.14); border-radius:12px; background:#fff; box-shadow:0 8px 20px rgba(2,6,23,.05); }.logo img { width:100%; height:100%; object-fit:contain; }.brand strong,.brand small { display:block; }.brand strong { font-size:15px; }.brand small { margin-top:3px; color:var(--muted); font-size:12px; }
		.top-actions { display:flex; align-items:center; gap:20px; }.back-link { color:var(--accent-dark); font-size:13px; font-weight:700; }.back-link:hover { text-decoration:underline; }.nav-links { display:flex; align-items:center; gap:18px; color:var(--muted); font-size:13px; font-weight:700; }.nav-links a:hover,.nav-links a.active { color:var(--accent-dark); }.cart-link { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }.cart-link:hover { border-color:var(--accent); background:var(--soft); }.cart-count { display:grid; width:19px; height:19px; place-items:center; border-radius:50%; background:var(--accent); color:#fff; font-size:10px; }
		.intro { max-width:720px; margin-bottom:27px; }.eyebrow { margin:0 0 8px; color:var(--accent-dark); font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }.intro h1 { margin:0; font-size:clamp(31px,4vw,48px); letter-spacing:-1px; line-height:1.05; }.intro p:last-child { margin:12px 0 0; color:var(--muted); line-height:1.6; }
		.profile-layout { display:grid; grid-template-columns:280px minmax(0,1fr); gap:22px; align-items:start; }.card { border:1px solid var(--line); border-radius:14px; background:var(--card); box-shadow:var(--shadow); }
		.identity-card { position:sticky; top:18px; padding:24px; text-align:center; }.avatar { display:grid; width:92px; height:92px; margin:0 auto 15px; place-items:center; border:5px solid #e5f8ff; border-radius:50%; background:linear-gradient(135deg,#0ea5e9,#0369a1); color:#fff; font-size:30px; font-weight:800; }.identity-card h2 { margin:0; font-size:20px; }.identity-card .email { margin:5px 0 18px; color:var(--muted); font-size:12px; }.member-badge { display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border-radius:6px; background:#ecfdf5; color:var(--green); font-size:11px; font-weight:800; }.member-badge span { width:6px; height:6px; border-radius:50%; background:#10b981; }.profile-nav { display:grid; gap:4px; margin-top:25px; padding-top:18px; border-top:1px solid var(--line); text-align:left; }.profile-nav a { padding:10px 11px; border-radius:7px; color:var(--muted); font-size:12px; font-weight:700; }.profile-nav a:hover,.profile-nav a.active { background:var(--soft); color:var(--accent-dark); }
		.content { display:grid; gap:22px; }.section-card { padding:24px; }.section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:20px; }.section-heading h2 { margin:0; font-size:19px; }.section-heading p { margin:5px 0 0; color:var(--muted); font-size:12px; line-height:1.5; }.edit-button,.save-button { min-height:35px; padding:0 12px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }.edit-button:hover { border-color:var(--accent); background:var(--soft); }.save-button { border-color:var(--accent); background:var(--accent); color:#fff; }.save-button:hover { background:var(--accent-dark); }.save-button[hidden] { display:none; }
		.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:17px 14px; }.field label { display:block; margin-bottom:7px; color:var(--muted); font-size:11px; font-weight:800; }.field input,.field select { width:100%; height:43px; padding:0 11px; border:1px solid var(--line); border-radius:8px; outline:none; background:#fafdfe; color:var(--ink); font-size:13px; }.field input:focus,.field select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(14,165,233,.12); }.field input:disabled,.field select:disabled { cursor:default; color:var(--ink); opacity:1; }.field.full { grid-column:1/-1; }
		.form-message { margin:0 0 16px; padding:10px 12px; border-radius:8px; font-size:12px; }.form-message.error { background:#fef2f2; color:#b91c1c; }.form-message.success { background:#ecfdf5; color:var(--green); }
		.stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:22px; }.stat { padding:14px; border:1px solid var(--line); border-radius:9px; background:#fafdfe; }.stat strong,.stat span { display:block; }.stat strong { color:var(--accent-dark); font-size:20px; }.stat span { margin-top:4px; color:var(--muted); font-size:11px; }
		.preference-list { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }.preference { display:flex; align-items:center; gap:9px; padding:12px; border:1px solid var(--line); border-radius:9px; color:var(--muted); font-size:12px; font-weight:700; }.preference input { width:16px; height:16px; accent-color:var(--accent); }.preference:has(input:checked) { border-color:rgba(14,165,233,.55); background:#f7fdff; color:var(--accent-dark); }
		.booking { display:grid; grid-template-columns:72px minmax(0,1fr) auto; gap:14px; align-items:center; padding:13px; border:1px solid var(--line); border-radius:10px; }.booking-image { width:72px; height:65px; border-radius:7px; background:url('https://images.unsplash.com/photo-1530789253388-582c481c54b0?auto=format&fit=crop&w=240&q=75') center/cover; }.booking h3 { margin:0 0 5px; font-size:14px; }.booking p { margin:0; color:var(--muted); font-size:11px; line-height:1.45; }.status { padding:6px 8px; border-radius:5px; background:#ecfdf5; color:var(--green); font-size:10px; font-weight:800; white-space:nowrap; }.booking-link { display:inline-block; margin-top:7px; color:var(--accent-dark); font-size:11px; font-weight:800; }.booking-link:hover { text-decoration:underline; }
		.booking.no-image { grid-template-columns:1fr; }
		.toast { position:fixed; right:22px; bottom:22px; padding:12px 16px; border-radius:8px; background:var(--ink); color:#fff; font-size:12px; opacity:0; pointer-events:none; transform:translateY(12px); transition:opacity .2s,transform .2s; }.toast.show { opacity:1; transform:translateY(0); }
		@media (max-width:800px) { .profile-layout { grid-template-columns:1fr; }.identity-card { position:static; text-align:left; }.avatar { margin:0 0 15px; }.profile-nav { grid-template-columns:repeat(3,1fr); text-align:center; }.identity-card .member-badge { position:absolute; top:26px; right:24px; } }
		@media (max-width:560px) { .page { width:min(100% - 28px,500px); padding-top:20px; }.topbar { align-items:flex-start; margin-bottom:38px; }.top-actions { align-items:flex-end; flex-direction:column-reverse; gap:10px; }.nav-links { display:none; }.intro h1 { font-size:34px; }.section-card { padding:18px; }.form-grid,.preference-list,.stats { grid-template-columns:1fr; }.field.full { grid-column:auto; }.booking { grid-template-columns:58px 1fr; }.booking-image { width:58px; height:58px; }.booking .status { grid-column:2; justify-self:start; }.identity-card .member-badge { position:static; }.profile-nav { grid-template-columns:1fr; text-align:left; } }
	</style>
</head>
<body>
	<main class="page">
		<header class="topbar">
			<a class="brand" href="dashboard.php"><span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span><span><strong>Mindanao Travel & Tours</strong><small>Your next story starts here</small></span></a>
			<div class="top-actions"><a class="back-link" href="dashboard.php">← Back to dashboard</a><a class="cart-link" href="cart.php">Trip cart</a></div>
		</header>

		<section class="intro"><p class="eyebrow">Your account</p><h1>Make every trip feel like yours.</h1><p>Keep your details, preferences, and upcoming plans in one place so your next Mindanao escape is easier to shape.</p></section>

		<div class="profile-layout">
			<aside class="card identity-card">
				<div class="avatar" aria-label="Profile avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
				<h2><?= htmlspecialchars($profileUser['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
				<p class="email"><?= htmlspecialchars($profileUser['email'], ENT_QUOTES, 'UTF-8') ?></p>
				<span class="member-badge"><span></span> <?= htmlspecialchars(ucfirst($profileUser['role']), ENT_QUOTES, 'UTF-8') ?> account</span>
				<nav class="profile-nav" aria-label="Profile sections"><a class="active" href="#details">Personal details</a><a href="#preferences">Travel preferences</a><a href="#bookings">My bookings</a></nav>
			</aside>

			<div class="content">
				<section class="card section-card" id="details">
					<div class="section-heading"><div><h2>Personal details</h2><p>These details are stored in your account.</p></div><div><button class="edit-button" id="editButton" type="button">Edit details</button><button class="save-button" id="saveButton" type="submit" form="profileForm" hidden>Save changes</button></div></div>
					<?php if ($profileError): ?><p class="form-message error"><?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8') ?></p><?php elseif ($profileMessage): ?><p class="form-message success"><?= htmlspecialchars($profileMessage, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
					<form class="form-grid" id="profileForm" method="POST">
						<input type="hidden" name="action" value="update_profile">
						<div class="field full"><label for="fullName">Full name</label><input id="fullName" name="full_name" value="<?= htmlspecialchars($profileUser['full_name'], ENT_QUOTES, 'UTF-8') ?>" disabled required></div>
						<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= htmlspecialchars($profileUser['email'], ENT_QUOTES, 'UTF-8') ?>" disabled required></div>
						<div class="field"><label for="phone">Mobile number</label><input id="phone" name="phone" type="tel" value="<?= htmlspecialchars($profileUser['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" disabled></div>
						<div class="field"><label for="createdAt">Member since</label><input id="createdAt" value="<?= htmlspecialchars(date('F Y', strtotime($profileUser['created_at'])), ENT_QUOTES, 'UTF-8') ?>" disabled></div>
					</form>
				</section>

				<section class="card section-card" id="preferences">
					<div class="section-heading"><div><h2>Travel preferences</h2><p>Tell us what makes a good day away for you.</p></div></div>
					<div class="stats"><div class="stat"><strong>—</strong><span>Trips completed</span></div><div class="stat"><strong>—</strong><span>Places explored</span></div><div class="stat"><strong>—</strong><span>Saved plans</span></div></div>
					<div class="preference-list"><label class="preference"><input type="checkbox" checked> Beaches & islands</label><label class="preference"><input type="checkbox" checked> Local food</label><label class="preference"><input type="checkbox"> Mountain trails</label><label class="preference"><input type="checkbox" checked> Slow mornings</label><label class="preference"><input type="checkbox"> Cultural tours</label><label class="preference"><input type="checkbox"> Water activities</label></div>
				</section>

				<section class="card section-card" id="bookings">
					<div class="section-heading"><div><h2>Upcoming booking</h2><p>Your next adventure is already taking shape.</p></div><a class="booking-link" href="cart.php">View trip cart</a></div>
					<article class="booking no-image"><div><h3>No booking data connected yet</h3><p>Your bookings section is ready for a future bookings query.</p></div></article>
				</section>
			</div>
		</div>
	</main>
	<div class="toast" id="toast" role="status"><?= htmlspecialchars($profileMessage, ENT_QUOTES, 'UTF-8') ?></div>
	<script>
		(() => {
			const form = document.querySelector('#profileForm');
			const controls = form.querySelectorAll('input, select');
			const editButton = document.querySelector('#editButton');
			const saveButton = document.querySelector('#saveButton');
			const toast = document.querySelector('#toast');
			let toastTimer;
			editButton.addEventListener('click', () => {
				controls.forEach((control) => { control.disabled = false; });
				editButton.hidden = true;
				saveButton.hidden = false;
				controls[0].focus();
			});
			form.addEventListener('submit', () => {
				controls.forEach((control) => { control.disabled = false; });
				editButton.hidden = false;
				saveButton.hidden = true;
			});
			if (toast.textContent.trim() !== '') {
				toast.classList.add('show');
				clearTimeout(toastTimer);
				toastTimer = setTimeout(() => toast.classList.remove('show'), 2600);
			}
		})();
	</script>
</body>
</html>

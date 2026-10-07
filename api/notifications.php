<?php

function mtrNotificationUrl(string $path, array $query = []): string
{
    $baseUrl = rtrim((string) (getenv('MTR_APP_URL') ?: ''), '/');
    if ($baseUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    }
    if ($baseUrl === '') {
        return '';
    }
    return $baseUrl . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}

function mtrAgentWhatsAppUrl(): string
{
    $digits = '09765756031';
    if (str_starts_with($digits, '0')) {
        $digits = '63' . substr($digits, 1);
    }
    return 'https://wa.me/' . $digits;
}

function mtrNotificationSenderEmail(): string
{
    return trim((string) (getenv('MTR_MAIL_FROM') ?: 'bitlgdagret@gmail.com'));
}

function mtrSendNotificationEmail(string $recipient, string $recipientName, string $subject, string $htmlBody, array $attachments = []): bool
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        return false;
    }

    $from = mtrNotificationSenderEmail();
    $messageBody = '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#172b4d">' . $htmlBody . '</body></html>';
    $headers = [
        'MIME-Version: 1.0',
        'From: Mindanao Travel Hub <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION
    ];
    if ($attachments) {
        $boundary = '=_MTR_' . bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $parts = [
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $messageBody
        ];
        foreach ($attachments as $attachment) {
            $path = (string) ($attachment['path'] ?? '');
            $fileName = basename((string) ($attachment['name'] ?? 'refund-receipt'));
            $mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
            if ($path === '' || !is_file($path) || !is_readable($path) || !preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mimeType)) {
                error_log('MTR notification email attachment is missing or invalid.');
                return false;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                error_log('MTR notification email attachment could not be read.');
                return false;
            }
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $mimeType . '; name="' . addcslashes($fileName, '"\\') . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . addcslashes($fileName, '"\\') . '"';
            $parts[] = '';
            $parts[] = chunk_split(base64_encode($contents));
        }
        $parts[] = '--' . $boundary . '--';
        $parts[] = '';
        $messageBody = implode("\r\n", $parts);
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    }

    $sent = mail(
        $recipient,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $messageBody,
        implode("\r\n", $headers)
    );
    if (!$sent) {
        error_log('MTR notification email failed for ' . $recipient . ': ' . json_encode(error_get_last()));
    }
    return $sent;
}

function mtrEmailName(string $name): string
{
    return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
}

function mtrSendPlanApprovalEmail(array $booking): bool
{
    $name = mtrEmailName((string) ($booking['customer_name'] ?? 'Customer'));
    $title = mtrEmailName((string) ($booking['package_name'] ?? 'Custom travel plan'));

    return mtrSendNotificationEmail(
        (string) ($booking['email'] ?? ''),
        (string) ($booking['customer_name'] ?? ''),
        'Your Mindanao Travel Hub plan is ready for payment',
        '<p>Hello ' . $name . ',</p><p>Your plan, <strong>' . $title . '</strong>, has been approved by our admin team. You can now proceed to payment.</p><p>Please sign in to the Mindanao Travel Hub app, open your bookings, and select the approved plan to submit your payment receipt.</p><p>Thank you,<br>Mindanao Travel Hub</p>'
    );
}

function mtrSendExtensionApprovalEmail(array $extension): bool
{
    $name = mtrEmailName((string) ($extension['customer_name'] ?? 'Customer'));
    $title = mtrEmailName((string) ($extension['package_name'] ?? 'Travel plan'));
    $bookingUrl = mtrNotificationUrl('user/payment.php', ['extension_id' => (int) ($extension['extension_id'] ?? 0)]);
    $paymentLink = $bookingUrl
        ? '<p><a href="' . htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:11px 16px;background:#0369a1;color:#fff;text-decoration:none;border-radius:7px;font-weight:bold">Submit extension payment</a></p>'
        : '';
    $requestedDate = mtrEmailName((string) ($extension['requested_departure_date'] ?? 'Not provided'));
    $amount = number_format((float) ($extension['approved_amount'] ?? 0), 2);

    return mtrSendNotificationEmail(
        (string) ($extension['email'] ?? ''),
        (string) ($extension['customer_name'] ?? ''),
        'Your Mindanao Travel Hub trip extension was approved',
        '<p>Hello ' . $name . ',</p><p>Your mid-trip extension request for <strong>' . $title . '</strong> was approved by our admin team.</p><p><strong>Extra days:</strong> ' . (int) ($extension['extra_days'] ?? 0) . '<br><strong>New departure date:</strong> ' . $requestedDate . '<br><strong>Extension amount:</strong> PHP ' . $amount . '</p><p>Please submit the extension payment to confirm the additional days.</p>' . $paymentLink . '<p>Thank you,<br>Mindanao Travel Hub</p>'
    );
}

function mtrSendPaymentVerifiedEmail(array $booking): bool
{
    $name = mtrEmailName((string) ($booking['customer_name'] ?? 'Customer'));
    $title = mtrEmailName((string) ($booking['package_name'] ?? 'Travel plan'));
    $receiptUrl = mtrNotificationUrl('user/booking.php');
    $receiptLink = $receiptUrl ? '<p><a href="' . htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:11px 16px;background:#047857;color:#fff;text-decoration:none;border-radius:7px;font-weight:bold">View your booking receipt</a></p>' : '';
    $proofPath = trim((string) ($booking['receipt_file'] ?? ''));
    $proofUrl = $proofPath ? mtrNotificationUrl(ltrim(preg_replace('/^(?:\.\.\/)+/', '', $proofPath), '/')) : '';
    $proofLink = $proofUrl ? '<p><a href="' . htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') . '">Open the uploaded payment proof</a></p>' : '';
    $agentPhone = '09765756031';
    $whatsappUrl = mtrAgentWhatsAppUrl();
    $contactBlock = $whatsappUrl
        ? '<p>Your travel agent is available on WhatsApp: <a href="' . htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($booking['agent_phone'] ?: $agentPhone), ENT_QUOTES, 'UTF-8') . '</a></p>'
        : '';
    $detail = static fn ($value, $fallback = 'Not provided') => mtrEmailName(trim((string) ($value ?? '')) ?: $fallback);
    $agentEmail = trim((string) ($booking['agent_email'] ?? ''));
    $agentBlock = '<p><strong>Your travel agent</strong><br>' . $detail($booking['agent_name'], 'Mindanao Travel Hub Admin') . ($agentEmail ? '<br><strong>Email:</strong> ' . mtrEmailName($agentEmail) : '') . '</p>';
    $hotelMapsLink = trim((string) ($booking['accommodation_maps_link'] ?? ''));
    $hotelMapsBlock = filter_var($hotelMapsLink, FILTER_VALIDATE_URL) ? '<br><a href="' . htmlspecialchars($hotelMapsLink, ENT_QUOTES, 'UTF-8') . '">Open hotel location in Maps</a>' : '';
    $customDetails = trim((string) ($booking['custom_details'] ?? ''));
    $customDetailsBlock = $customDetails !== '' ? '<h3>Plan notes</h3><p>' . nl2br(mtrEmailName($customDetails)) . '</p>' : '';
    $tripDetails = '<h3>Trip details</h3><p><strong>Travel date:</strong> ' . $detail($booking['travel_date']) . '<br><strong>Guests:</strong> ' . (int) ($booking['guest_count'] ?? 0) . '<br><strong>Destinations:</strong> ' . $detail($booking['destination_names']) . '<br><strong>Hotel:</strong> ' . $detail($booking['hotel_name'], $booking['accommodation_name'] ?: 'Not provided') . '<br><strong>Hotel address:</strong> ' . $detail($booking['accommodation_address']) . $hotelMapsBlock . '<br><strong>Pickup:</strong> ' . $detail($booking['pickup_address'] ?: $booking['pickup_location']) . '<br><strong>Departure:</strong> ' . $detail($booking['departure_location']) . '<br><strong>Customer-selected vehicle:</strong> ' . $detail($booking['requested_vehicle_name'], 'No preference') . '</p>' . $customDetailsBlock;
    $driverName = trim((string) ($booking['driver_name'] ?? ''));
    $driverBlock = $driverName !== ''
        ? '<h3>Assigned driver</h3><p><strong>Name:</strong> ' . $detail($driverName) . '<br><strong>Phone:</strong> ' . $detail($booking['driver_phone']) . '</p>'
        : '<h3>Driver assignment</h3><p>Your driver will be confirmed before your trip.</p>';

    return mtrSendNotificationEmail(
        (string) ($booking['email'] ?? ''),
        (string) ($booking['customer_name'] ?? ''),
        'Payment confirmed for your Mindanao Travel Hub booking',
        '<p>Hello ' . $name . ',</p><p>Your payment for <strong>' . $title . '</strong> has been verified. Your booking is now confirmed.</p><p><strong>Receipt number:</strong> MTR-' . (int) $booking['booking_id'] . '<br><strong>Amount paid:</strong> PHP ' . number_format((float) ($booking['amount'] ?? $booking['total_amount'] ?? 0), 2) . '<br><strong>Reference:</strong> ' . mtrEmailName((string) ($booking['reference_number'] ?? 'N/A')) . '</p>' . $tripDetails . $driverBlock . $agentBlock . $receiptLink . $proofLink . $contactBlock . '<p>Thank you,<br>Mindanao Travel Hub</p>'
    );
}

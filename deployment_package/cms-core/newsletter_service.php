<?php
/**
 * Green AVIC CMS — Newsletter Campaign & Dispatch Service
 * 
 * Manages email campaign creation, responsive HTML rendering,
 * bulk delivery loop with duplicate-send prevention, and delivery logs.
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Render a production-grade, responsive HTML email for a published article.
 */
function render_newsletter_email(array $article, string $unsubscribeUrl): string {
    $title       = htmlspecialchars($article['title'] ?? 'Green AVIC Update');
    $category    = htmlspecialchars($article['category'] ?? 'Sustainability & Eco-Impact');
    $author      = htmlspecialchars($article['author'] ?? 'Green AVIC');
    $excerpt     = htmlspecialchars($article['excerpt'] ?? '');
    $body        = $article['body'] ?? '';
    $readingTime = (int)($article['reading_time'] ?? 3);
    $publishedAt = !empty($article['published_at']) ? date('F j, Y', strtotime($article['published_at'])) : date('F j, Y');
    $coverImage  = !empty($article['cover_image']) ? $article['cover_image'] : '';
    $coverAlt    = htmlspecialchars($article['cover_alt'] ?? $title);
    $webUrl      = NEWSLETTER_URL;

    // Convert relative cover image URL to absolute if needed
    if (!empty($coverImage) && !str_starts_with($coverImage, 'http://') && !str_starts_with($coverImage, 'https://')) {
        $coverImage = rtrim(NEWSLETTER_URL, '/') . '/' . ltrim($coverImage, '/');
    }

    $logoUrl = rtrim(MAIN_SITE_URL, '/') . '/assets/images/brand/greenavic-logo-real.png';

    // Build Email HTML
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
    <style>
        body { margin: 0; padding: 0; background-color: #F4F7F4; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-spacing: 0; border-collapse: collapse; }
        img { border: 0; -ms-interpolation-mode: bicubic; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .body-content p { font-size: 16px; line-height: 1.7; color: #333333; margin-bottom: 20px; }
        .body-content h2 { font-size: 22px; color: #0D3D2F; margin-top: 32px; margin-bottom: 12px; font-weight: 700; line-height: 1.3; }
        .body-content h3 { font-size: 18px; color: #0D3D2F; margin-top: 24px; margin-bottom: 10px; font-weight: 700; }
        .body-content blockquote { border-left: 4px solid #4CAF50; margin: 24px 0; padding: 12px 20px; background-color: #F8FAF8; color: #0D3D2F; font-style: italic; }
        .body-content ul, .body-content ol { margin: 0 0 20px 24px; padding: 0; color: #333333; line-height: 1.7; }
        .body-content li { margin-bottom: 8px; }
        .body-content a { color: #4CAF50; text-decoration: underline; }
        .body-content img { max-width: 100% !important; height: auto !important; border-radius: 8px; margin: 20px 0; display: block; }
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; border-radius: 0 !important; }
            .content-padding { padding: 24px 18px !important; }
            .header-padding { padding: 20px 18px !important; }
            .title-text { font-size: 24px !important; line-height: 1.3 !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F4F7F4;">
    <!-- Preheader preview text for inbox -->
    <div style="display: none; font-size: 1px; color: #F4F7F4; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;">
        <?php echo $excerpt; ?>
    </div>

    <center style="width: 100%; background-color: #F4F7F4; padding: 24px 0;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; margin: 0 auto;">
            
            <!-- 1. Header Bar -->
            <tr>
                <td style="background-color: #0D3D2F; padding: 24px 32px; border-radius: 12px 12px 0 0; text-align: center;">
                    <a href="<?php echo MAIN_SITE_URL; ?>" target="_blank" style="text-decoration: none;">
                        <img src="<?php echo $logoUrl; ?>" alt="Green AVIC" width="160" style="max-height: 48px; width: auto; display: block; margin: 0 auto;">
                    </a>
                    <div style="font-size: 12px; color: #B8F542; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-top: 10px;">
                        News &amp; Insights
                    </div>
                </td>
            </tr>

            <!-- 2. Main Article Body Container -->
            <tr>
                <td class="content-padding" style="background-color: #ffffff; padding: 36px 36px 28px; border-left: 1px solid #E6EAE7; border-right: 1px solid #E6EAE7;">
                    
                    <!-- Category & Meta -->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 16px;">
                        <tr>
                            <td>
                                <span style="display: inline-block; background-color: #E8F5E9; color: #2E7D32; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; padding: 4px 12px; border-radius: 20px;">
                                    <?php echo $category; ?>
                                </span>
                            </td>
                            <td align="right" style="font-size: 13px; color: #888888;">
                                <?php echo $publishedAt; ?> &bull; <?php echo $readingTime; ?> min read
                            </td>
                        </tr>
                    </table>

                    <!-- Headline -->
                    <h1 class="title-text" style="font-size: 28px; line-height: 1.3; color: #0D3D2F; font-weight: 700; margin: 0 0 16px;">
                        <?php echo $title; ?>
                    </h1>

                    <!-- Author Byline -->
                    <div style="font-size: 14px; color: #666666; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #EEF2EE;">
                        By <strong style="color: #0D3D2F;"><?php echo $author; ?></strong>
                    </div>

                    <!-- Cover Image (if present) -->
                    <?php if (!empty($coverImage)): ?>
                    <div style="margin-bottom: 28px;">
                        <img src="<?php echo htmlspecialchars($coverImage); ?>" alt="<?php echo $coverAlt; ?>" width="528" style="width: 100%; max-width: 528px; height: auto; border-radius: 10px; display: block;">
                    </div>
                    <?php endif; ?>

                    <!-- Excerpt Callout -->
                    <?php if (!empty($excerpt)): ?>
                    <div style="background-color: #F8FAF8; border-left: 4px solid #4CAF50; padding: 14px 18px; border-radius: 0 8px 8px 0; margin-bottom: 28px; font-size: 16px; font-style: italic; color: #2E5A44; line-height: 1.6;">
                        <?php echo $excerpt; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Full Rich Body Content -->
                    <div class="body-content">
                        <?php echo $body; ?>
                    </div>

                    <!-- Web Version CTA Button -->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-top: 36px; margin-bottom: 16px;">
                        <tr>
                            <td align="center">
                                <a href="<?php echo $webUrl; ?>" target="_blank" style="display: inline-block; background-color: #4CAF50; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 700; padding: 14px 32px; border-radius: 30px; letter-spacing: 0.5px;">
                                    Read Online on Green AVIC &rarr;
                                </a>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>

            <!-- 3. Brand Mission Footer Card -->
            <tr>
                <td style="background-color: #F9FBF9; padding: 24px 36px; border-left: 1px solid #E6EAE7; border-right: 1px solid #E6EAE7; border-top: 1px solid #E6EAE7;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                            <td>
                                <div style="font-size: 14px; font-weight: 700; color: #0D3D2F; margin-bottom: 6px;">Care for yourself. Protect the planet.</div>
                                <div style="font-size: 13px; color: #666666; line-height: 1.5;">
                                    Green AVIC creates 100% biodegradable sanitary pads and promotes menstrual health education across communities.
                                </div>
                            </td>
                            <td align="right" style="padding-left: 16px;">
                                <a href="<?php echo MAIN_SITE_URL; ?>/products" target="_blank" style="display: inline-block; background-color: #0D3D2F; color: #ffffff; text-decoration: none; font-size: 12px; font-weight: 700; padding: 8px 16px; border-radius: 20px; white-space: nowrap;">
                                    Shop Pads
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <!-- 4. Mandatory Anti-Spam & Unsubscribe Footer -->
            <tr>
                <td style="background-color: #0D3D2F; color: #A0C0B7; padding: 28px 32px; border-radius: 0 0 12px 12px; text-align: center; font-size: 12px; line-height: 1.6;">
                    <div style="margin-bottom: 12px;">
                        <a href="https://x.com/GreenAVIC" target="_blank" style="color: #B8F542; text-decoration: none; margin: 0 8px;">X (@GreenAVIC)</a> &bull;
                        <a href="https://instagram.com/green.avic" target="_blank" style="color: #B8F542; text-decoration: none; margin: 0 8px;">Instagram (@green.avic)</a> &bull;
                        <a href="<?php echo MAIN_SITE_URL; ?>" target="_blank" style="color: #B8F542; text-decoration: none; margin: 0 8px;">greenavic.org</a>
                    </div>
                    <p style="margin: 0 0 12px; color: #C8DFD7;">
                        You received this publication because you subscribed to updates from Green AVIC.<br>
                        Empowering women sustainably through eco-friendly innovation.
                    </p>
                    <p style="margin: 0;">
                        <a href="<?php echo htmlspecialchars($unsubscribeUrl); ?>" target="_blank" style="color: #ffffff; text-decoration: underline; font-weight: 600;">
                            Unsubscribe with one click
                        </a>
                        &bull;
                        <a href="<?php echo MAIN_SITE_URL; ?>/#contact" target="_blank" style="color: #A0C0B7; text-decoration: underline;">
                            Contact Us
                        </a>
                    </p>
                </td>
            </tr>

        </table>
    </center>
</body>
</html>
    <?php
    return ob_get_clean();
}

/**
 * Dispatch newsletter email campaign for a given article to all active subscribers.
 * 
 * Guarantees duplicate-send prevention using MariaDB unique index on (campaign_id, subscriber_id).
 */
function dispatch_newsletter_campaign(PDO $pdo, int $articleId, array $articleData): array {
    $mailer = new GreenAvicMailer();

    if (!$mailer->isConfigured()) {
        return [
            'attempted' => false,
            'reason'    => 'SMTP credentials are not configured in cms-core/.env'
        ];
    }

    // Set unlimited execution time for delivery loop
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    if (function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    $subject = $articleData['title'] ?? 'Green AVIC News & Insights';

    // 1. Create campaign record
    $campStmt = $pdo->prepare("
        INSERT INTO newsletter_campaigns (article_id, subject, status, total_subscribers, sent_count, failed_count, created_at)
        VALUES (?, ?, 'sending', 0, 0, 0, NOW())
    ");
    $campStmt->execute([$articleId, $subject]);
    $campaignId = (int)$pdo->lastInsertId();

    // 2. Fetch all active subscribers
    $subStmt = $pdo->prepare("SELECT id, email, token FROM subscribers WHERE status = 'active' ORDER BY id ASC");
    $subStmt->execute();
    $subscribers = $subStmt->fetchAll();

    $totalSubscribers = count($subscribers);

    $updateTotalStmt = $pdo->prepare("UPDATE newsletter_campaigns SET total_subscribers = ? WHERE id = ?");
    $updateTotalStmt->execute([$totalSubscribers, $campaignId]);

    if ($totalSubscribers === 0) {
        $finishStmt = $pdo->prepare("UPDATE newsletter_campaigns SET status = 'sent', completed_at = NOW() WHERE id = ?");
        $finishStmt->execute([$campaignId]);
        return [
            'attempted'         => true,
            'campaign_id'       => $campaignId,
            'total_subscribers' => 0,
            'sent_count'        => 0,
            'failed_count'      => 0,
            'message'           => 'No active subscribers to send to.'
        ];
    }

    $sentCount = 0;
    $failedCount = 0;

    $recordDeliveryStmt = $pdo->prepare("
        INSERT INTO newsletter_deliveries (campaign_id, subscriber_id, email, status, error_message, sent_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = VALUES(status), error_message = VALUES(error_message), sent_at = VALUES(sent_at)
    ");

    $checkDeliveryStmt = $pdo->prepare("
        SELECT id, status FROM newsletter_deliveries WHERE campaign_id = ? AND subscriber_id = ? LIMIT 1
    ");

    foreach ($subscribers as $sub) {
        $subId = (int)$sub['id'];
        $subEmail = $sub['email'];
        $subToken = $sub['token'];

        // Prevent duplicate sending if script was interrupted and resumed
        $checkDeliveryStmt->execute([$campaignId, $subId]);
        $existing = $checkDeliveryStmt->fetch();
        if ($existing && $existing['status'] === 'sent') {
            $sentCount++;
            continue;
        }

        $unsubscribeUrl = NEWSLETTER_URL . '/unsubscribe?token=' . urlencode($subToken);
        $htmlBody = render_newsletter_email($articleData, $unsubscribeUrl);

        $result = $mailer->send($subEmail, $subject, $htmlBody, $unsubscribeUrl);

        if ($result['success']) {
            $sentCount++;
            $recordDeliveryStmt->execute([$campaignId, $subId, $subEmail, 'sent', null]);
        } else {
            $failedCount++;
            $recordDeliveryStmt->execute([$campaignId, $subId, $subEmail, 'failed', substr((string)$result['error'], 0, 490)]);
        }

        // Brief delay (50ms) to prevent slamming SMTP servers
        usleep(50000);
    }

    $finalStatus = ($sentCount > 0 || $failedCount === 0) ? 'sent' : 'failed';
    $finishStmt = $pdo->prepare("
        UPDATE newsletter_campaigns 
        SET status = ?, sent_count = ?, failed_count = ?, completed_at = NOW() 
        WHERE id = ?
    ");
    $finishStmt->execute([$finalStatus, $sentCount, $failedCount, $campaignId]);

    return [
        'attempted'         => true,
        'campaign_id'       => $campaignId,
        'total_subscribers' => $totalSubscribers,
        'sent_count'        => $sentCount,
        'failed_count'      => $failedCount,
        'status'            => $finalStatus
    ];
}

/**
 * Retry failed deliveries for a specific campaign.
 */
function retry_failed_campaign_deliveries(PDO $pdo, int $campaignId): array {
    $mailer = new GreenAvicMailer();
    if (!$mailer->isConfigured()) {
        return ['success' => false, 'error' => 'SMTP credentials not configured in cms-core/.env'];
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $campStmt = $pdo->prepare("SELECT * FROM newsletter_campaigns WHERE id = ? LIMIT 1");
    $campStmt->execute([$campaignId]);
    $campaign = $campStmt->fetch();
    if (!$campaign) {
        return ['success' => false, 'error' => 'Campaign record not found.'];
    }

    // Fetch published article
    $artStmt = $pdo->prepare("SELECT * FROM articles WHERE id = ? LIMIT 1");
    $artStmt->execute([$campaign['article_id']]);
    $article = $artStmt->fetch();
    if (!$article) {
        // Fallback to latest published article
        $artStmt = $pdo->query("SELECT * FROM articles WHERE status = 'published' ORDER BY published_at DESC LIMIT 1");
        $article = $artStmt->fetch();
    }
    if (!$article) {
        return ['success' => false, 'error' => 'Associated article content not found.'];
    }

    $failStmt = $pdo->prepare("
        SELECT d.id as delivery_id, d.subscriber_id, s.email, s.token, s.status as sub_status
        FROM newsletter_deliveries d
        JOIN subscribers s ON d.subscriber_id = s.id
        WHERE d.campaign_id = ? AND d.status = 'failed' AND s.status = 'active'
    ");
    $failStmt->execute([$campaignId]);
    $failedList = $failStmt->fetchAll();

    if (empty($failedList)) {
        return ['success' => true, 'retried_success' => 0, 'still_failed' => 0, 'message' => 'No eligible failed deliveries to retry.'];
    }

    $subject = $campaign['subject'];
    $retriedSuccess = 0;
    $stillFailed = 0;

    $updateDeliveryStmt = $pdo->prepare("
        UPDATE newsletter_deliveries 
        SET status = ?, error_message = ?, sent_at = NOW() 
        WHERE id = ?
    ");

    foreach ($failedList as $row) {
        $unsubscribeUrl = NEWSLETTER_URL . '/unsubscribe?token=' . urlencode($row['token']);
        $htmlBody = render_newsletter_email($article, $unsubscribeUrl);

        $result = $mailer->send($row['email'], $subject, $htmlBody, $unsubscribeUrl);
        if ($result['success']) {
            $retriedSuccess++;
            $updateDeliveryStmt->execute(['sent', null, $row['delivery_id']]);
        } else {
            $stillFailed++;
            $updateDeliveryStmt->execute(['failed', substr((string)$result['error'], 0, 490), $row['delivery_id']]);
        }
        usleep(50000);
    }

    // Update campaign counters
    $recountStmt = $pdo->prepare("
        UPDATE newsletter_campaigns
        SET sent_count = (SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id = ? AND status = 'sent'),
            failed_count = (SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id = ? AND status = 'failed')
        WHERE id = ?
    ");
    $recountStmt->execute([$campaignId, $campaignId, $campaignId]);

    return [
        'success'         => true,
        'retried_success' => $retriedSuccess,
        'still_failed'    => $stillFailed,
        'message'         => "Retry complete: {$retriedSuccess} succeeded, {$stillFailed} failed."
    ];
}


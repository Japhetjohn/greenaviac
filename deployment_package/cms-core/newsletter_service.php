<?php
/**
 * Green AVIC CMS — Newsletter Campaign & Queue Dispatch Service
 * 
 * Implements:
 * - Controlled batch processing respecting Hostinger's 100 emails / rolling 24 hours limit.
 * - Double opt-in active subscriber filtering.
 * - Queueing with duplicate-send prevention and retry handling.
 * - Responsive HTML email generation.
 */

defined('GREENAVIC_CORE') or define('GREENAVIC_CORE', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const HOSTINGER_24H_LIMIT = 100;

/**
 * Calculate the number of emails sent in the rolling 24-hour window
 */
function get_rolling_24h_sent_count(PDO $pdo): int {
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) 
            FROM newsletter_deliveries 
            WHERE status = 'sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Rolling 24h count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calculate remaining SMTP sending quota for the current rolling 24 hours
 */
function get_available_smtp_quota(PDO $pdo): int {
    $used = get_rolling_24h_sent_count($pdo);
    return max(0, HOSTINGER_24H_LIMIT - $used);
}

/**
 * Render a production-grade, responsive HTML email for a published article
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
                        You received this publication because you confirmed your email subscription with Green AVIC.<br>
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
 * Create campaign and queue all eligible confirmed ('active') subscribers.
 * Prevents accidental duplicate campaigns if publish button is clicked multiple times.
 */
function create_newsletter_campaign(PDO $pdo, int $articleId, array $articleData): array {
    $mailer = new GreenAvicMailer();
    if (!$mailer->isConfigured()) {
        return [
            'attempted' => false,
            'reason'    => 'Hostinger SMTP credentials are not configured in cms-core/.env'
        ];
    }

    // 1. Debounce Check: Prevent duplicate campaign if triggered within last 60 seconds for same article
    $recentStmt = $pdo->prepare("
        SELECT id, status, total_subscribers, sent_count, failed_count, pending_count 
        FROM newsletter_campaigns 
        WHERE article_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    $recentStmt->execute([$articleId]);
    $existingCampaign = $recentStmt->fetch();

    if ($existingCampaign) {
        // Campaign was already created seconds ago; proceed directly to process queue
        $batchResult = process_campaign_queue($pdo, 15);
        return [
            'attempted'         => true,
            'campaign_id'       => (int)$existingCampaign['id'],
            'total_subscribers' => (int)$existingCampaign['total_subscribers'],
            'batch_result'      => $batchResult,
            'message'           => 'Campaign already in progress. Batch processed.'
        ];
    }

    $subject = $articleData['title'] ?? 'Green AVIC News & Insights';

    // 2. Fetch all CONFIRMED ('active') subscribers only (Double Opt-In enforcement)
    $subStmt = $pdo->prepare("SELECT id, email, token FROM subscribers WHERE status = 'active' ORDER BY id ASC");
    $subStmt->execute();
    $subscribers = $subStmt->fetchAll();

    $totalSubscribers = count($subscribers);

    // 3. Create Campaign Record
    $campStmt = $pdo->prepare("
        INSERT INTO newsletter_campaigns (article_id, subject, status, total_subscribers, sent_count, failed_count, pending_count, created_at)
        VALUES (?, ?, 'pending', ?, 0, 0, ?, NOW())
    ");
    $campStmt->execute([$articleId, $subject, $totalSubscribers, $totalSubscribers]);
    $campaignId = (int)$pdo->lastInsertId();

    if ($totalSubscribers === 0) {
        $finishStmt = $pdo->prepare("UPDATE newsletter_campaigns SET status = 'sent', completed_at = NOW() WHERE id = ?");
        $finishStmt->execute([$campaignId]);
        return [
            'attempted'         => true,
            'campaign_id'       => $campaignId,
            'total_subscribers' => 0,
            'sent_count'        => 0,
            'failed_count'      => 0,
            'pending_count'     => 0,
            'message'           => 'No confirmed active subscribers to send to.'
        ];
    }

    // 4. Populate Delivery Queue with status 'pending'
    $queueStmt = $pdo->prepare("
        INSERT INTO newsletter_deliveries (campaign_id, subscriber_id, email, status, attempts, created_at)
        VALUES (?, ?, ?, 'pending', 0, NOW())
        ON DUPLICATE KEY UPDATE id = id
    ");

    foreach ($subscribers as $sub) {
        $queueStmt->execute([$campaignId, (int)$sub['id'], $sub['email']]);
    }

    // 5. Process initial batch immediately up to available 24h quota
    $batchResult = process_campaign_queue($pdo, 15);

    return [
        'attempted'         => true,
        'campaign_id'       => $campaignId,
        'total_subscribers' => $totalSubscribers,
        'batch_result'      => $batchResult
    ];
}

/**
 * Process next batch from campaign delivery queue, strictly respecting Hostinger's 100 emails / rolling 24h limit.
 * Can be called via Cron or Admin trigger.
 */
function process_campaign_queue(PDO $pdo, int $batchLimit = 15): array {
    $mailer = new GreenAvicMailer();
    if (!$mailer->isConfigured()) {
        return ['processed' => 0, 'error' => 'SMTP not configured in cms-core/.env'];
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }

    // 1. Check rolling 24-hour quota limit
    $availableQuota = get_available_smtp_quota($pdo);
    if ($availableQuota <= 0) {
        return [
            'processed'       => 0,
            'quota_exhausted' => true,
            'available_quota' => 0,
            'message'         => 'Hostinger 100 emails / 24h rolling limit reached. Processing paused until quota window resets.'
        ];
    }

    // 2. Determine actual batch limit
    $batchSize = min($batchLimit, $availableQuota);

    // 3. Find oldest unfinished campaign
    $campStmt = $pdo->query("
        SELECT id, article_id, subject, status 
        FROM newsletter_campaigns 
        WHERE status IN ('pending', 'sending') 
        ORDER BY id ASC 
        LIMIT 1
    ");
    $campaign = $campStmt->fetch();

    if (!$campaign) {
        return ['processed' => 0, 'message' => 'No pending campaigns in queue.'];
    }

    $campaignId = (int)$campaign['id'];

    // Update campaign status to 'sending'
    $pdo->prepare("UPDATE newsletter_campaigns SET status = 'sending' WHERE id = ? AND status = 'pending'")->execute([$campaignId]);

    // 4. Fetch published article content
    $artStmt = $pdo->prepare("SELECT * FROM articles WHERE id = ? LIMIT 1");
    $artStmt->execute([$campaign['article_id']]);
    $article = $artStmt->fetch();

    if (!$article) {
        // Fallback to latest live article
        $artStmt = $pdo->query("SELECT * FROM articles WHERE status = 'published' ORDER BY published_at DESC LIMIT 1");
        $article = $artStmt->fetch();
    }

    if (!$article) {
        return ['processed' => 0, 'error' => 'Associated article content not found.'];
    }

    // 5. Fetch next batch of pending deliveries
    $queueStmt = $pdo->prepare("
        SELECT d.id as delivery_id, d.subscriber_id, d.email, d.attempts, s.token, s.status as subscriber_status
        FROM newsletter_deliveries d
        LEFT JOIN subscribers s ON d.subscriber_id = s.id
        WHERE d.campaign_id = ? AND d.status = 'pending' AND d.attempts < 3
        ORDER BY d.id ASC
        LIMIT ?
    ");
    $queueStmt->bindValue(1, $campaignId, PDO::PARAM_INT);
    $queueStmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $queueStmt->execute();
    $items = $queueStmt->fetchAll();

    if (empty($items)) {
        // No more pending items; finalize campaign status
        finalize_campaign_status($pdo, $campaignId);
        return ['processed' => 0, 'message' => 'Campaign queue complete.'];
    }

    $updateDeliveryStmt = $pdo->prepare("
        UPDATE newsletter_deliveries 
        SET status = ?, attempts = attempts + 1, error_message = ?, sent_at = ? 
        WHERE id = ?
    ");

    $processedCount = 0;
    $successCount   = 0;
    $failureCount   = 0;
    $subject        = $campaign['subject'];

    foreach ($items as $item) {
        $deliveryId = (int)$item['delivery_id'];
        $email      = $item['email'];
        $token      = $item['token'] ?? '';
        $subStatus  = $item['subscriber_status'] ?? '';

        // Exclude if recipient unsubscribed before batch was picked up
        if ($subStatus !== 'active') {
            $updateDeliveryStmt->execute(['failed', 'Subscriber is no longer active', null, $deliveryId]);
            $failureCount++;
            $processedCount++;
            continue;
        }

        $unsubscribeUrl = NEWSLETTER_URL . '/unsubscribe?token=' . urlencode($token);
        $htmlBody = render_newsletter_email($article, $unsubscribeUrl);

        $result = $mailer->send($email, $subject, $htmlBody, $unsubscribeUrl);

        if ($result['success']) {
            $updateDeliveryStmt->execute(['sent', null, date('Y-m-d H:i:s'), $deliveryId]);
            $successCount++;
        } else {
            $err = substr((string)($result['error'] ?? 'SMTP Error'), 0, 490);
            $nextStatus = ($item['attempts'] >= 2) ? 'failed' : 'pending';
            $updateDeliveryStmt->execute([$nextStatus, $err, null, $deliveryId]);
            $failureCount++;
        }

        $processedCount++;
        usleep(100000); // 100ms throttle between emails
    }

    // 6. Recalculate campaign totals
    finalize_campaign_status($pdo, $campaignId);

    return [
        'processed'       => $processedCount,
        'sent'            => $successCount,
        'failed'          => $failureCount,
        'available_quota' => get_available_smtp_quota($pdo),
        'campaign_id'     => $campaignId
    ];
}

/**
 * Helper to recount deliveries and finalize campaign state
 */
function finalize_campaign_status(PDO $pdo, int $campaignId): void {
    $countsStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_cnt,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_cnt,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt
        FROM newsletter_deliveries 
        WHERE campaign_id = ?
    ");
    $countsStmt->execute([$campaignId]);
    $counts = $countsStmt->fetch();

    $sent    = (int)($counts['sent_cnt'] ?? 0);
    $failed  = (int)($counts['failed_cnt'] ?? 0);
    $pending = (int)($counts['pending_cnt'] ?? 0);

    $isDone = ($pending === 0);
    $finalStatus = $isDone ? ($sent > 0 ? 'sent' : 'failed') : 'sending';

    $upStmt = $pdo->prepare("
        UPDATE newsletter_campaigns 
        SET sent_count = ?, failed_count = ?, pending_count = ?, status = ?, completed_at = ?
        WHERE id = ?
    ");
    $completedAt = $isDone ? date('Y-m-d H:i:s') : null;
    $upStmt->execute([$sent, $failed, $pending, $finalStatus, $completedAt, $campaignId]);
}

/**
 * Retry failed deliveries for a specific campaign
 */
function retry_failed_campaign_deliveries(PDO $pdo, int $campaignId): array {
    $mailer = new GreenAvicMailer();
    if (!$mailer->isConfigured()) {
        return ['success' => false, 'error' => 'SMTP credentials not configured in cms-core/.env'];
    }

    // Reset failed deliveries where subscriber is still active back to 'pending'
    $resetStmt = $pdo->prepare("
        UPDATE newsletter_deliveries d
        JOIN subscribers s ON d.subscriber_id = s.id
        SET d.status = 'pending', d.attempts = 0, d.error_message = NULL
        WHERE d.campaign_id = ? AND d.status = 'failed' AND s.status = 'active'
    ");
    $resetStmt->execute([$campaignId]);
    $resetCount = $resetStmt->rowCount();

    if ($resetCount === 0) {
        return ['success' => true, 'reset_count' => 0, 'message' => 'No eligible failed deliveries to retry.'];
    }

    // Update campaign status back to sending
    $pdo->prepare("
        UPDATE newsletter_campaigns 
        SET status = 'sending', completed_at = NULL 
        WHERE id = ?
    ")->execute([$campaignId]);

    // Process next available batch immediately
    $batch = process_campaign_queue($pdo, 15);

    return [
        'success'      => true,
        'reset_count'  => $resetCount,
        'batch_result' => $batch,
        'message'      => "{$resetCount} failed deliveries reset to queue."
    ];
}

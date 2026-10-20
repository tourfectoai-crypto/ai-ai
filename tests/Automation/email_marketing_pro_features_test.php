<?php
// php tests/Automation/email_marketing_pro_features_test.php
// منطق خالص لميزات: تصنيف الفتح، أفضل وقت، الحملات المتكررة، مساعد AI، بلوكات المحرر.
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailEngagementService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailRecurringCampaignService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailAiWriterService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailTemplateEditorService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailPlatformBridge.php';

function check(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } echo "PASS: {$message}\n"; }
date_default_timezone_set('UTC');

// ---------- open classification ----------
check(EmailEngagementService::classifyOpen('Mozilla/5.0', 3600) === 'apple_mpp', 'Apple MPP proxy UA is a machine open');
check(EmailEngagementService::classifyOpen('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120', 2) === 'scanner', 'open 2s after delivery is a security scanner');
check(EmailEngagementService::classifyOpen('Mimecast/1.0', 600) === 'scanner', 'known scanner UA');
check(EmailEngagementService::classifyOpen('', 600) === 'scanner', 'empty UA is not counted as human');
check(EmailEngagementService::classifyOpen('Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 1) === 'human', 'Gmail image proxy = real open even when fast');
check(EmailEngagementService::classifyOpen('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 900) === 'human', 'real iPhone mail client open');
check(EmailEngagementService::classifyOpen('Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0)', null) === 'human', 'Outlook desktop open');

// ---------- send-time slots ----------
$now = strtotime('2026-10-20 14:30:00');
check(date('Y-m-d H:i', EmailEngagementService::nextSlot(18, $now)) === '2026-10-20 18:00', 'best hour later today');
check(date('Y-m-d H:i', EmailEngagementService::nextSlot(9, $now)) === '2026-10-21 09:00', 'best hour already passed -> tomorrow');
check(date('Y-m-d H:i', EmailEngagementService::nextSlot(14, $now)) === '2026-10-21 14:00', 'same hour already started -> tomorrow');

// ---------- daily series ----------
$filled = EmailEngagementService::fillDays(['sent' => [['d' => '2026-10-02', 'n' => 5]], 'revenue' => [['d' => '2026-10-01', 'n' => '99.50']]], '2026-10-01', 3);
check($filled['days'] === ['2026-10-01', '2026-10-02', '2026-10-03'], 'series has every day');
check($filled['sent'] === [0, 5, 0] && $filled['revenue'] === [99.5, 0.0, 0.0], 'missing days are zero');

// ---------- recurring campaigns ----------
$t = gmmktime(9, 0, 0, 1, 31, 2026);
check(gmdate('Y-m-d H:i', EmailRecurringCampaignService::nextOccurrence('daily', $t)) === '2026-02-01 09:00', 'daily recurrence');
check(gmdate('Y-m-d H:i', EmailRecurringCampaignService::nextOccurrence('weekly', $t)) === '2026-02-07 09:00', 'weekly recurrence');
check(gmdate('Y-m-d H:i', EmailRecurringCampaignService::nextOccurrence('monthly', $t)) === '2026-02-28 09:00', 'monthly on the 31st clamps to end of February');
check(gmdate('Y-m-d', EmailRecurringCampaignService::nextOccurrence('monthly', gmmktime(9, 0, 0, 12, 15, 2026))) === '2027-01-15', 'monthly rolls over the year');
$threw = false; try { EmailRecurringCampaignService::nextOccurrence('hourly', $t); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'unsupported recurrence rejected');

// ---------- AI writer ----------
$ai = new EmailAiWriterService(fn ($p) => ['success' => true, 'data' => "1. عرض الصيف لـ {{first_name}}\n2) رحلات النيل\n- الأقصر وأسوان\n\"خصم الحجز المبكر\"\n• شرم الشيخ\nسطر سادس"]);
$r = $ai->assist('subjects', 'عرض صيفي');
check($r['success'] && count($r['suggestions']) === 5 && $r['suggestions'][0] === 'عرض الصيف لـ {{first_name}}', 'subject suggestions are cleaned and capped at 5');
check($r['suggestions'][3] === 'خصم الحجز المبكر', 'quotes stripped from suggestions');

$ai = new EmailAiWriterService(fn ($p) => ['success' => true, 'data' => "```html\n<h2 onclick=\"x()\">مرحبا {{first_name}}</h2><script>alert(1)</script><p>نص</p><a href=\"javascript:alert(1)\">احجز</a><iframe src=\"x\"></iframe>\n```"]);
$html = $ai->assist('body', 'رحلة')['html'];
check(strpos($html, '<script') === false && strpos($html, 'onclick') === false && strpos($html, 'iframe') === false, 'AI HTML is sanitized (script/on*/iframe removed)');
check(strpos($html, 'javascript:') === false && strpos($html, '{{first_name}}') !== false && strpos($html, '```') === false, 'javascript links neutralized, variables kept, code fence removed');
check(!(new EmailAiWriterService(fn ($p) => ['success' => true, 'data' => 'x']))->assist('delete_all', 'x')['success'], 'unknown AI action rejected');
check(!(new EmailAiWriterService(fn ($p) => ['success' => false, 'error' => 'quota']))->assist('rewrite', 'نص')['success'], 'AI provider failure surfaces as error');
check(strpos(EmailAiWriterService::buildPrompt('body', 'x', ['company_name' => 'Nile <b>Tours</b>']), '<b>') === false, 'company name is stripped of tags in the prompt');

// ---------- builder blocks ----------
check(EmailTemplateEditorService::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10') === 'dQw4w9WgXcQ', 'youtube watch url');
check(EmailTemplateEditorService::youtubeId('https://youtu.be/dQw4w9WgXcQ') === 'dQw4w9WgXcQ' && EmailTemplateEditorService::youtubeId('https://vimeo.com/1') === '', 'short youtube url / non-youtube');
$video = EmailTemplateEditorService::videoHtml(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'caption' => '<b>جولة</b>']);
check(strpos($video, 'img.youtube.com/vi/dQw4w9WgXcQ') !== false && strpos($video, '<b>') === false, 'video block uses YouTube thumbnail and escapes caption');
check(strpos(EmailTemplateEditorService::videoHtml(['url' => 'javascript:alert(1)']), 'javascript') === false, 'video block rejects non-http url');
$cols = EmailTemplateEditorService::columnsHtml(['left_title' => 'الأقصر', 'left_url' => 'https://a.com/x', 'left_button' => 'احجز', 'right_title' => '<script>', 'right_url' => 'javascript:1', 'right_button' => 'x']);
check(strpos($cols, 'https://a.com/x') !== false && strpos($cols, '<script>') === false && strpos($cols, 'javascript') === false, 'columns block escapes text and drops unsafe links');

// ---------- bridge: new steps metadata ----------
check(isset(EmailPlatformBridge::stepTypes()['send_whatsapp'], EmailPlatformBridge::stepTypes()['request_review']), 'WhatsApp and review steps registered');
check(EmailPlatformBridge::subscriberPhone(['attributes' => '{"phone":"+20 100-123-4567"}'], []) === '201001234567', 'phone from contact attributes normalized');
check(EmailPlatformBridge::subscriberPhone(['attributes' => null], ['customer_phone' => '+44 7700 900123']) === '447700900123', 'booking phone preferred');

echo "All email marketing pro feature checks passed\n";

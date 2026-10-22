<?php
/**
 * Tourfecto - الطيار الآلي للسوشيال
 *
 * كل شهر (في run_day) بيجهّز خطة الشهر الجاي لوحده:
 *   ذكاء الحجوزات/الإيرادات (الموسم + مهلة الحجز) + الرحلات النشطة وطلبها + روابط صفحاتها
 *   + ملف الشركة المؤكد + صور العميل ← خطة شهرية كاملة (نفس SocialMonthlyPlanService بكل ضماناتها)
 *
 * بعد ما الخطة تتولد:
 *   - auto_approve + الباقة فيها auto_pilot ← تتعتمد وتتجدول للنشر أوتوماتيك
 *   - غير كده ← إشعار للعميل يراجع ويعتمد بضغطة
 * التكلفة نفسها بتاعة الخطة اليدوية (marketing_tool من الباقة أو المحفظة) ومفيش تكرار لنفس الشهر.
 */
final class SocialAutopilotService
{
    private Database $db;
    private PDO $pdo;
    private SocialMonthlyPlanService $plans;

    public function __construct(?Database $db = null, ?SocialMonthlyPlanService $plans = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->pdo = $this->db->getConnection();
        $this->plans = $plans ?? new SocialMonthlyPlanService($this->db);
    }

    private function q(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }

    public function ready(): bool
    {
        return (bool) $this->q("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='social_autopilot_settings'")->fetchColumn();
    }

    /** الإعدادات + معاينة الإشارات للشهر الجاي (اللي هيشوفها العميل قبل ما يفعّل) */
    public function overview(int $uid, int $wid): array
    {
        $this->own($uid, $wid);
        $settings = $this->ready() ? ($this->q('SELECT * FROM social_autopilot_settings WHERE website_id=? AND user_id=?', [$wid, $uid])->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        if ($settings) $settings['connection_ids'] = json_decode((string) $settings['connection_ids'], true) ?: [];
        $month = self::nextMonth();
        return [
            'installed' => $this->ready(),
            'settings' => $settings,
            'next_month' => $month,
            'signals' => SocialMonthlyPlanService::growthBrief($this->db, $uid, $wid, $month, (int) ($settings['post_count'] ?? 12)),
            'can_auto_approve' => self::planAllowsAutopilot($uid),
            'pages' => $this->plans->connectedPageIds($uid, $wid),
            'photos' => count($this->plans->usableMediaIds($uid, 4)),
        ];
    }

    public function save(int $uid, int $wid, array $in): array
    {
        if (!$this->ready()) throw new InvalidArgumentException('Install the social autopilot SQL update first.');
        $this->own($uid, $wid);
        $count = filter_var($in['post_count'] ?? 12, FILTER_VALIDATE_INT);
        if (!in_array($count, [4, 8, 12, 16, 20], true)) throw new InvalidArgumentException('Choose 4, 8, 12, 16 or 20 posts per page');
        $time = (string) ($in['publish_time'] ?? '18:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) throw new InvalidArgumentException('Choose a valid publish time');
        $tz = (string) ($in['timezone'] ?? 'UTC');
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Choose a valid timezone');
        $lang = (string) ($in['language'] ?? 'en');
        if (!in_array($lang, ['ar', 'en'], true)) throw new InvalidArgumentException('Choose Arabic or English');
        $mix = in_array($in['mix'] ?? 'mix', ['mix', 'photo', 'design'], true) ? (string) ($in['mix'] ?? 'mix') : 'mix';
        $day = filter_var($in['run_day'] ?? 20, FILTER_VALIDATE_INT);
        if ($day === false || $day < 10 || $day > 26) throw new InvalidArgumentException('Choose a preparation day between 10 and 26');
        $pages = array_values(array_unique(array_map('intval', array_filter((array) ($in['connection_ids'] ?? []), fn ($v) => is_scalar($v) && ctype_digit((string) $v)))));
        $allowed = $this->plans->connectedPageIds($uid, $wid);
        if (array_diff($pages, $allowed)) throw new InvalidArgumentException('A selected page is unavailable or disconnected');
        $enabled = filter_var($in['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $auto = filter_var($in['auto_approve'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($auto && !self::planAllowsAutopilot($uid)) throw new InvalidArgumentException('Automatic approval requires a plan that includes Autopilot. Plans will be prepared for your review instead.');
        $this->q('INSERT INTO social_autopilot_settings(website_id,user_id,enabled,connection_ids,post_count,publish_time,timezone,language,mix,run_day,auto_approve,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),connection_ids=VALUES(connection_ids),post_count=VALUES(post_count),publish_time=VALUES(publish_time),timezone=VALUES(timezone),language=VALUES(language),mix=VALUES(mix),run_day=VALUES(run_day),auto_approve=VALUES(auto_approve),updated_at=UTC_TIMESTAMP()',
            [$wid, $uid, (int) $enabled, json_encode($pages), $count, $time, $tz, $lang, $mix, $day, (int) $auto]);
        return $this->overview($uid, $wid);
    }

    /** الكرون: تجهيز الخطط المستحقة واعتماد اللي جاهز. بيرجع ملخص للّوج. */
    public function runDue(?DateTimeImmutable $now = null): array
    {
        if (!$this->ready()) return ['skipped' => 'not_installed'];
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $month = self::nextMonth($now);
        $out = ['created' => 0, 'approved' => 0, 'waiting_review' => 0, 'failed' => 0];
        foreach ($this->q('SELECT * FROM social_autopilot_settings WHERE enabled=1 ORDER BY website_id LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $uid = (int) $s['user_id'];
            $wid = (int) $s['website_id'];
            try {
                if ($s['last_month_key'] === $month && $s['last_plan_id']) {
                    $r = $this->finish($s);
                    if ($r) $out[$r]++;
                    continue;
                }
                // فشل قبل كده الشهر ده (صور ناقصة/رصيد/ربط): نعيد مرة في اليوم بعد ما العميل يصلّح السبب
                if ($s['last_month_key'] === $month && $s['last_status'] === 'failed' && $s['last_run_at'] && strtotime($s['last_run_at'] . ' UTC') > $now->getTimestamp() - 86400) continue;
                $local = $now->setTimezone(new DateTimeZone($s['timezone']));
                if ((int) $local->format('j') < (int) $s['run_day']) continue;
                $plan = $this->createPlan($s, $month);
                $this->q("UPDATE social_autopilot_settings SET last_month_key=?,last_plan_id=?,last_status='generating',last_error=NULL,last_run_at=UTC_TIMESTAMP() WHERE website_id=?", [$month, (int) $plan['plan']['id'], $wid]);
                $out['created']++;
            } catch (Throwable $e) {
                $out['failed']++;
                $msg = mb_substr(CustomerError::message($e->getMessage(), 422), 0, 480);
                $this->q("UPDATE social_autopilot_settings SET last_month_key=?,last_status='failed',last_error=?,last_run_at=UTC_TIMESTAMP() WHERE website_id=?", [$month, $msg, $wid]);
                try { Notification::notify($uid, 'social_autopilot_failed', 'Next month social plan was not prepared', $msg, '/social'); } catch (Throwable $ignored) {}
                Logger::warning('Social autopilot did not prepare a plan', ['website_id' => $wid, 'error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    private function createPlan(array $s, string $month): array
    {
        $uid = (int) $s['user_id'];
        $wid = (int) $s['website_id'];
        $profile = (new BusinessHubService($this->db))->profile($uid, $wid);
        if (!$profile || !empty($profile['needs_reconfirmation']) || empty($profile['facts']['business_name'])) throw new InvalidArgumentException('Confirm your company profile in Business Hub so the autopilot can write accurate posts.');
        $f = $profile['facts'];
        $pages = json_decode((string) $s['connection_ids'], true) ?: $this->plans->connectedPageIds($uid, $wid);
        if (!$pages) throw new InvalidArgumentException('Connect a Facebook or Instagram page for this website.');
        $media = $this->plans->usableMediaIds($uid, 40);
        if (!$media) throw new InvalidArgumentException('Upload real trip photos to your media library so the autopilot can use them.');
        $g = SocialMonthlyPlanService::growthBrief($this->db, $uid, $wid, $month, (int) $s['post_count']);
        $trips = '';
        foreach (($g['focus_trips'] ?? []) as $t) $trips .= '- ' . $t['name'] . ($t['url'] ? ' (' . $t['url'] . ')' : '') . "\n";
        $quote = PlanUsage::check($uid, 'marketing_tool', 'marketing_tool', 'Insufficient credit to generate the monthly plan. Top up your wallet or upgrade your plan.');
        if (empty($quote['ok'])) throw new InvalidArgumentException((string) ($quote['error'] ?? 'Insufficient credit to generate the monthly plan.'));
        $cut = fn (string $v, int $max) => mb_substr(trim($v), 0, $max);
        return $this->plans->create($uid, [
            // محاولة واحدة في اليوم بحد أقصى لكل شهر (نفس المفتاح = نفس الخطة، من غير تكرار خصم)
            'request_key' => 'autopilot-' . $wid . '-' . str_replace('-', '', $month) . '-' . gmdate('Ymd'),
            'website_id' => $wid,
            'post_count' => (int) $s['post_count'],
            'business' => $cut($f['business_name'] . ': ' . ($f['business_facts'] ?? ''), 1000),
            'audience' => $cut(($f['audience'] ?? '') !== '' ? $f['audience'] : implode(', ', $g['markets']['countries'] ?? []) . ' travelers', 500) ?: 'International travelers',
            'goal' => $cut($g['goal'] ?? ($f['goal'] ?? 'Grow bookings'), 500),
            'offers' => $cut(trim(($f['offers'] ?? '') . ($trips !== '' ? "\nFeatured trips this month:\n" . $trips : '')), 1500),
            'cta' => $cut(($f['cta'] ?? '') !== '' ? $f['cta'] : 'Book on our website or message us on WhatsApp', 300),
            'language' => $s['language'],
            'timezone' => $s['timezone'],
            'month' => $month,
            'publish_time' => $s['publish_time'],
            'mix' => $s['mix'],
            'connection_ids' => $pages,
            'media_ids' => $media,
            'quote_hash' => SocialMonthlyPlanService::quoteHash($quote),
        ]);
    }

    /** خطة الشهر اتولدت: اعتماد أوتوماتيك أو تنبيه للمراجعة (مرة واحدة) */
    private function finish(array $s): ?string
    {
        if (in_array($s['last_status'], ['approved', 'waiting_review', 'failed'], true)) return null;
        $uid = (int) $s['user_id'];
        $d = $this->plans->detail($uid, (int) $s['last_plan_id']);
        $status = $d['plan']['status'];
        if (in_array($status, ['queued', 'generating'], true)) return null;
        if ($status === 'failed') {
            // التوليد فشل (والحجز اترجّع): نسجّل السبب ونعيد بكرة بخطة جديدة
            $msg = mb_substr((string) ($d['plan']['error_message'] ?? 'The monthly plan could not be generated.'), 0, 480);
            $this->q("UPDATE social_autopilot_settings SET last_status='failed',last_plan_id=NULL,last_error=?,last_run_at=UTC_TIMESTAMP() WHERE website_id=?", [$msg, (int) $s['website_id']]);
            try { Notification::notify($uid, 'social_autopilot_failed', 'Next month social plan was not prepared', $msg . ' We will retry tomorrow.', '/social'); } catch (Throwable $ignored) {}
            return 'failed';
        }
        if ($status !== 'draft') {
            $this->q("UPDATE social_autopilot_settings SET last_status='approved' WHERE website_id=?", [(int) $s['website_id']]);
            return null;
        }
        if ((int) $s['auto_approve'] === 1 && self::planAllowsAutopilot($uid)) {
            $this->plans->approve($uid, (int) $d['plan']['id'], (int) $d['plan']['version']);
            $this->q("UPDATE social_autopilot_settings SET last_status='approved' WHERE website_id=?", [(int) $s['website_id']]);
            try { Notification::notify($uid, 'social_autopilot_scheduled', 'Next month social plan is scheduled', 'Your posts for ' . $d['plan']['month_key'] . ' were prepared from your bookings and active trips and are scheduled. You can pause or edit them anytime.', '/social'); } catch (Throwable $ignored) {}
            return 'approved';
        }
        $this->q("UPDATE social_autopilot_settings SET last_status='waiting_review' WHERE website_id=?", [(int) $s['website_id']]);
        try { Notification::notify($uid, 'social_autopilot_review', 'Next month social plan is ready for review', 'Posts for ' . $d['plan']['month_key'] . ' were prepared from your bookings and active trips. Review and approve to schedule them.', '/social'); } catch (Throwable $ignored) {}
        return 'waiting_review';
    }

    public static function planAllowsAutopilot(int $uid): bool
    {
        try { return class_exists('FeatureResolver') && FeatureResolver::has($uid, 'auto_pilot'); } catch (Throwable $e) { return false; }
    }

    public static function nextMonth(?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('first day of next month')->format('Y-m');
    }

    private function own(int $uid, int $wid): void
    {
        if (!$this->q('SELECT id FROM websites WHERE id=? AND user_id=?', [$wid, $uid])->fetchColumn()) throw new InvalidArgumentException('Website unavailable');
    }
}

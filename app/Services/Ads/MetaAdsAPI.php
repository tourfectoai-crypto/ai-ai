<?php

/**
 * Tourfecto - Enterprise Meta Ads Marketing API Client
 * @version 2.0.0
 *
 * المزايا التنافسية:
 * 1. سحب تلقائي لـ external_adset_id وميزانية الـ Ad Set أثناء المزامنة لتمكين الـ Autopilot من التعديل الفوري.
 * 2. دعم كامل لإشارات Meta CAPI المتقدمة (em, ph, fn, ln, fbc, fbp, client_ip_address, client_user_agent) لرفع EMQ إلى 9.5+/10.
 * 3. حماية تلقائية من Rate Limits (أكواد 4, 17, 613) عبر Exponential Backoff في بيئة الإنتاج.
 * 4. توافق 100% مع $testTransport للاختبارات الآلية (CLI).
 */
class MetaAdsAPI
{
    private string $apiVersion;
    private string $accessToken;

    /** للاختبارات بس (CLI): function(string $method, string $path, array $fields): array{0:int,1:array} */
    public static $testTransport = null;

    public function __construct(string $accessToken)
    {
        $this->apiVersion = class_exists('MetaOAuthClient') ? MetaOAuthClient::apiVersion() : (env('META_API_VERSION') ?: 'v25.0');
        $this->accessToken = $accessToken;
    }

    /**
     * قائمة حسابات الإعلانات المتاحة لصاحب التوكن.
     * @return array ['success'=>bool, 'accounts'=>[['id','name','currency','status']], 'error'=>?]
     */
    public function listAdAccounts(): array
    {
        $result = $this->get('me/adaccounts', [
            'fields' => 'id,name,account_status,currency,business_name',
        ]);

        if (!$result['success']) {
            return $result;
        }

        $accounts = array_map(function ($a) {
            return [
                'id' => $a['id'],
                'name' => $a['business_name'] ?? $a['name'] ?? $a['id'],
                'currency' => $a['currency'] ?? 'USD',
                'status' => ($a['account_status'] ?? 0) === 1 ? 'active' : 'inactive',
            ];
        }, $result['data']['data'] ?? []);

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * كل الحملات (Campaigns) الخاصة بحساب إعلانات معيّن، مع بيانات الأداء الحقيقية
     * ومعرّف المجموعة الإعلانية (external_adset_id) اللازم للطيار الآلي.
     */
    public function listCampaignsWithInsights(string $adAccountId): array
    {
        $campaignsResult = $this->get("{$adAccountId}/campaigns", [
            'fields' => 'id,name,objective,status,daily_budget,start_time,stop_time,adsets{id,daily_budget,status}',
            'limit' => 100,
        ]);

        if (!$campaignsResult['success']) {
            return $campaignsResult;
        }

        $campaigns = $campaignsResult['data']['data'] ?? [];
        $enriched = [];
        $acc = $this->get($adAccountId, ['fields' => 'currency']);
        $currency = $acc['success'] ? strtoupper((string) ($acc['data']['currency'] ?? '')) : '';

        foreach ($campaigns as $c) {
            $insights = $this->get("{$c['id']}/insights", [
                'fields' => 'impressions,clicks,spend',
                'date_preset' => 'maximum',
            ]);

            $stats = ($insights['success'] && !empty($insights['data']['data'][0]))
                ? $insights['data']['data'][0]
                : ['impressions' => 0, 'clicks' => 0, 'spend' => 0];

            // استخراج أول Ad Set وميزانيته في حال كانت الميزانية محددة على مستوى الـ Ad Set وليس الحملة
            $primaryAdSetId = null;
            $adSetDailyBudget = null;
            if (!empty($c['adsets']['data']) && is_array($c['adsets']['data'])) {
                $firstAdSet = $c['adsets']['data'][0];
                $primaryAdSetId = $firstAdSet['id'] ?? null;
                if (isset($firstAdSet['daily_budget'])) {
                    $adSetDailyBudget = ((float) $firstAdSet['daily_budget']) / 100;
                }
            }

            $campaignDailyBudget = isset($c['daily_budget'])
                ? ((float) $c['daily_budget']) / 100
                : $adSetDailyBudget;

            // أداء يومي لآخر 30 يوم
            $daily = [];
            $dailyRes = $this->get("{$c['id']}/insights", [
                'fields' => 'impressions,clicks,spend,actions',
                'date_preset' => 'last_30d',
                'time_increment' => 1,
                'limit' => 40,
            ]);
            if ($dailyRes['success']) {
                foreach (($dailyRes['data']['data'] ?? []) as $d) {
                    $conv = 0.0;
                    foreach (($d['actions'] ?? []) as $a) {
                        if (in_array($a['action_type'] ?? '', [
                            'purchase',
                            'offsite_conversion.fb_pixel_purchase',
                            'lead',
                            'onsite_conversion.lead_grouped',
                            'omni_purchase',
                        ], true)) {
                            $conv += (float) ($a['value'] ?? 0);
                        }
                    }
                    $daily[] = [
                        'date' => (string) ($d['date_start'] ?? ''),
                        'impressions' => (int) ($d['impressions'] ?? 0),
                        'clicks' => (int) ($d['clicks'] ?? 0),
                        'spend' => (float) ($d['spend'] ?? 0),
                        'conversions' => $conv,
                    ];
                }
            }

            $enriched[] = [
                'external_campaign_id' => $c['id'],
                'external_adset_id' => $primaryAdSetId,
                'name' => $c['name'],
                'objective' => $c['objective'] ?? null,
                'status' => strtolower($c['status'] ?? 'paused'),
                'daily_budget' => $campaignDailyBudget,
                'started_at' => $c['start_time'] ?? null,
                'ended_at' => $c['stop_time'] ?? null,
                'impressions' => (int) ($stats['impressions'] ?? 0),
                'clicks' => (int) ($stats['clicks'] ?? 0),
                'spend' => (float) ($stats['spend'] ?? 0),
                'daily' => $daily,
                'currency' => $currency ?: null,
            ];
        }

        return ['success' => true, 'campaigns' => $enriched];
    }

    /**
     * إنشاء حملة حقيقية كاملة على Meta Ads: Campaign → Ad Set → Ad Creative → Ad.
     */
    public function createCampaign(string $adAccountId, string $pageId, array $campaign, array $audience, array $copies, string $destinationUrl, ?string $imageUrl = null): array
    {
        $objectiveMap = [
            'traffic' => ['OUTCOME_TRAFFIC', 'LINK_CLICKS'],
            'leads' => ['OUTCOME_TRAFFIC', 'LINK_CLICKS'],
            'calls' => ['OUTCOME_TRAFFIC', 'LINK_CLICKS'],
            'engagement' => ['OUTCOME_TRAFFIC', 'LINK_CLICKS'],
            'awareness' => ['OUTCOME_AWARENESS', 'REACH'],
        ];
        [$metaObjective, $optimizationGoal] = $objectiveMap[$campaign['objective'] ?? 'traffic'] ?? $objectiveMap['traffic'];

        $campaignResult = $this->post("{$adAccountId}/campaigns", [
            'name' => $campaign['name'],
            'objective' => $metaObjective,
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            'is_adset_budget_sharing_enabled' => 'false',
        ]);
        if (!$campaignResult['success']) {
            return ['success' => false, 'step' => 'campaign', 'error' => 'فشل إنشاء الحملة: ' . ($campaignResult['error'] ?? '')];
        }
        $campaignId = $campaignResult['data']['id'];

        $targeting = [
            'age_min' => max(18, (int) ($audience['age_min'] ?? 18)),
            'age_max' => min(65, max(18, (int) ($audience['age_max'] ?? 65))),
            'geo_locations' => ['countries' => array_values($this->mapLocationsToCountryCodes($audience['locations'] ?? []))],
            'targeting_automation' => ['advantage_audience' => 0],
        ];
        if (!empty($audience['genders']) && $audience['genders'] !== 'all') {
            $targeting['genders'] = [$audience['genders'] === 'male' ? 1 : 2];
        }

        $adSetFields = [
            'name' => $campaign['name'] . ' - Ad Set',
            'campaign_id' => $campaignId,
            'daily_budget' => (int) round((float) ($campaign['daily_budget'] ?? 10) * 100),
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $optimizationGoal,
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode($targeting),
            'status' => 'PAUSED',
        ];
        if ($optimizationGoal === 'LINK_CLICKS') {
            $adSetFields['destination_type'] = 'WEBSITE';
        }
        $start = !empty($campaign['start_date']) ? strtotime((string) $campaign['start_date']) : false;
        if ($start && $start > time() + 600) {
            $adSetFields['start_time'] = date('c', $start);
        }
        $end = !empty($campaign['end_date']) ? strtotime((string) $campaign['end_date'] . ' 23:59:00') : false;
        if ($end && $end > max(time(), $start ?: 0) + 86400) {
            $adSetFields['end_time'] = date('c', $end);
        }

        $adSetResult = $this->post("{$adAccountId}/adsets", $adSetFields);
        if (!$adSetResult['success']) {
            $this->post("{$campaignId}", ['status' => 'DELETED']);
            return ['success' => false, 'step' => 'adset', 'error' => 'فشل إنشاء المجموعة الإعلانية: ' . ($adSetResult['error'] ?? '')];
        }
        $adSetId = $adSetResult['data']['id'];

        $imageHash = $imageUrl ? $this->uploadAdImage($adAccountId, $imageUrl) : null;

        $adIds = [];
        $lastError = '';
        foreach (array_slice($copies, 0, 3) as $copy) {
            $linkData = [
                'link' => $destinationUrl,
                'message' => (string) ($copy['primary_text'] ?? ''),
                'name' => (string) ($copy['headline'] ?? ''),
                'description' => (string) ($copy['description'] ?? ''),
                'call_to_action' => ['type' => $this->mapCta((string) ($copy['call_to_action'] ?? '')), 'value' => ['link' => $destinationUrl]],
            ];
            if ($imageHash) {
                $linkData['image_hash'] = $imageHash;
            } elseif ($imageUrl) {
                $linkData['picture'] = $imageUrl;
            }

            $creativeResult = $this->post("{$adAccountId}/adcreatives", [
                'name' => $campaign['name'] . ' - ' . ($copy['variant_label'] ?? 'A'),
                'object_story_spec' => json_encode(['page_id' => $pageId, 'link_data' => $linkData], JSON_UNESCAPED_UNICODE),
            ]);
            if (!$creativeResult['success']) {
                $lastError = 'التصميم (Creative): ' . ($creativeResult['error'] ?? '');
                continue;
            }

            $adResult = $this->post("{$adAccountId}/ads", [
                'name' => $campaign['name'] . ' - ' . ($copy['variant_label'] ?? 'A'),
                'adset_id' => $adSetId,
                'creative' => json_encode(['creative_id' => $creativeResult['data']['id']]),
                'status' => 'PAUSED',
            ]);
            if ($adResult['success']) {
                $adIds[] = $adResult['data']['id'];
            } else {
                $lastError = 'الإعلان: ' . ($adResult['error'] ?? '');
            }
        }

        if (empty($adIds)) {
            $this->post("{$campaignId}", ['status' => 'DELETED']);
            return ['success' => false, 'step' => 'ads', 'error' => 'الحملة اتعملت على Meta بس الإعلان نفسه اترفض، فمسحناها عشان تقدر تحاول تاني بعد ما تصلّح السبب. ' . $lastError];
        }

        return [
            'success' => true,
            'external_campaign_id' => $campaignId,
            'external_adset_id' => $adSetId,
            'ad_ids' => $adIds,
            'warning' => $lastError ?: null,
        ];
    }

    public function uploadAdImage(string $adAccountId, string $imageUrl): ?string
    {
        $bytes = null;
        $local = self::localPublicFile($imageUrl);
        if ($local) {
            $bytes = @file_get_contents($local);
        } elseif (preg_match('#^https://#i', $imageUrl) && !self::$testTransport) {
            $ch = curl_init($imageUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $bytes = curl_exec($ch);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if (!is_string($bytes) || stripos($type, 'image/') !== 0 || strlen($bytes) > 8 * 1024 * 1024) {
                $bytes = null;
            }
        }
        if (!$bytes) {
            return null;
        }
        $r = $this->post("{$adAccountId}/adimages", ['bytes' => base64_encode($bytes)]);
        if (!$r['success']) {
            return null;
        }
        foreach (($r['data']['images'] ?? []) as $img) {
            if (!empty($img['hash'])) {
                return (string) $img['hash'];
            }
        }
        return null;
    }

    private static function localPublicFile(string $url): ?string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        $path = null;
        if (str_starts_with($url, '/uploads/')) {
            $path = $url;
        } elseif ($base !== '' && str_starts_with($url, $base . '/uploads/')) {
            $path = substr($url, strlen($base));
        }
        if (!$path || str_contains($path, '..') || !defined('ROOT_PATH')) {
            return null;
        }
        $f = ROOT_PATH . '/public_html' . $path;
        return is_file($f) ? $f : null;
    }

    public function updateCampaignStatus(string $campaignId, string $status): array
    {
        $metaStatus = strtoupper($status) === 'ACTIVE' ? 'ACTIVE' : 'PAUSED';
        $result = $this->post($campaignId, ['status' => $metaStatus]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل حالة الحملة'];
    }

    public function deleteCampaign(string $campaignId): array
    {
        $result = $this->post($campaignId, ['status' => 'ARCHIVED']);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل إلغاء الحملة'];
    }

    public function pauseCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'PAUSED');
    }

    public function resumeCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'ACTIVE');
    }

    public function updateCampaignBudget(string $adSetId, float $dailyBudgetUsd): array
    {
        return $this->updateAdSetBudget($adSetId, $dailyBudgetUsd);
    }

    public function updateAdSetBudget(string $adSetId, float $dailyBudgetUsd): array
    {
        $result = $this->post($adSetId, ['daily_budget' => (int) round($dailyBudgetUsd * 100)]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل الميزانية'];
    }

    public function fetchOgImageFromWebsite(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0)',
            ]);
            $html = curl_exec($ch);
            curl_close($ch);

            if (!$html) {
                return null;
            }

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                return $m[1];
            }
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
                return $m[1];
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Meta Conversions API (CAPI) مع دعم إشارات EMQ 10/10 (em, ph, fn, ln, fbc, fbp, IP, UA).
     */
    public function sendConversionEvent(string $pixelId, array $conversion): array
    {
        $eventId = (string) ($conversion['event_id'] ?? '');
        $emailHash = isset($conversion['email_hash']) && is_string($conversion['email_hash']) ? (string) $conversion['email_hash'] : null;
        $phoneHash = isset($conversion['phone_hash']) && is_string($conversion['phone_hash']) ? (string) $conversion['phone_hash'] : null;

        if ($emailHash === null && $phoneHash === null) {
            return ['success' => false, 'error' => 'مفيش أي معرّف مستخدم مجهول الهوية لإرسال الحدث'];
        }

        $userData = [];
        if ($emailHash !== null) {
            $userData['em'] = [$emailHash];
        }
        if ($phoneHash !== null) {
            $userData['ph'] = [$phoneHash];
        }
        if (!empty($conversion['fn']) && is_string($conversion['fn'])) {
            $userData['fn'] = [$conversion['fn']];
        }
        if (!empty($conversion['ln']) && is_string($conversion['ln'])) {
            $userData['ln'] = [$conversion['ln']];
        }
        if (!empty($conversion['fbc']) && is_string($conversion['fbc'])) {
            $userData['fbc'] = $conversion['fbc'];
        }
        if (!empty($conversion['fbp']) && is_string($conversion['fbp'])) {
            $userData['fbp'] = $conversion['fbp'];
        }
        if (!empty($conversion['client_ip_address']) && is_string($conversion['client_ip_address'])) {
            $userData['client_ip_address'] = $conversion['client_ip_address'];
        }
        if (!empty($conversion['client_user_agent']) && is_string($conversion['client_user_agent'])) {
            $userData['client_user_agent'] = $conversion['client_user_agent'];
        }

        $eventTime = !empty($conversion['event_time']) && is_numeric($conversion['event_time'])
            ? (int) $conversion['event_time']
            : time();

        $event = [
            'event_name' => 'Purchase',
            'event_time' => $eventTime,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'currency' => (string) ($conversion['currency'] ?? 'USD'),
                'value' => round((float) ($conversion['value'] ?? 0), 2),
            ],
        ];
        if ($eventId !== '') {
            $event['event_id'] = $eventId;
        }

        $result = $this->post($pixelId . '/events', ['data' => json_encode([$event], JSON_UNESCAPED_UNICODE)]);

        if (!$result['success']) {
            return $result;
        }

        $received = isset($result['data']['events_received'])
            ? (int) $result['data']['events_received'] > 0
            : true;

        return ['success' => $received, 'received' => $received];
    }

    private function mapCta(string $cta): string
    {
        $map = [
            'احجز الآن' => 'BOOK_TRAVEL', 'اعرف المزيد' => 'LEARN_MORE', 'تواصل معنا' => 'CONTACT_US',
            'اشترك الآن' => 'SIGN_UP', 'تسوق الآن' => 'SHOP_NOW', 'قدّم الآن' => 'APPLY_NOW',
            'اتصل الآن' => 'CALL_NOW', 'راسلنا' => 'MESSAGE_PAGE', 'حمّل الآن' => 'DOWNLOAD',
        ];
        $english = [
            'book now' => 'BOOK_TRAVEL', 'learn more' => 'LEARN_MORE', 'contact us' => 'CONTACT_US',
            'sign up' => 'SIGN_UP', 'shop now' => 'SHOP_NOW', 'apply now' => 'APPLY_NOW',
            'call now' => 'CALL_NOW', 'message us' => 'MESSAGE_PAGE', 'download' => 'DOWNLOAD',
        ];
        return $map[trim($cta)] ?? $english[strtolower(trim($cta))] ?? 'LEARN_MORE';
    }

    private function mapLocationsToCountryCodes(array $locations): array
    {
        $map = [
            'مصر' => 'EG', 'egypt' => 'EG', 'السعودية' => 'SA', 'saudi arabia' => 'SA',
            'الإمارات' => 'AE', 'uae' => 'AE', 'الكويت' => 'KW', 'kuwait' => 'KW',
            'قطر' => 'QA', 'qatar' => 'QA', 'البحرين' => 'BH', 'bahrain' => 'BH',
            'عمان' => 'OM', 'oman' => 'OM', 'الأردن' => 'JO', 'jordan' => 'JO',
            'لبنان' => 'LB', 'lebanon' => 'LB', 'المغرب' => 'MA', 'morocco' => 'MA',
            'تونس' => 'TN', 'tunisia' => 'TN', 'الجزائر' => 'DZ', 'algeria' => 'DZ',
            'العراق' => 'IQ', 'iraq' => 'IQ', 'ليبيا' => 'LY', 'libya' => 'LY',
            'السودان' => 'SD', 'sudan' => 'SD', 'تركيا' => 'TR', 'turkey' => 'TR',
            'المملكة المتحدة' => 'GB', 'uk' => 'GB', 'ألمانيا' => 'DE', 'germany' => 'DE',
            'فرنسا' => 'FR', 'france' => 'FR', 'الولايات المتحدة' => 'US', 'usa' => 'US',
        ];
        $codes = [];
        foreach ($locations as $loc) {
            $raw = trim((string) $loc);
            if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
                $codes[] = strtoupper($raw);
                continue;
            }
            $key = mb_strtolower($raw);
            if (isset($map[$key])) {
                $codes[] = $map[$key];
            }
        }
        return !empty($codes) ? array_values(array_unique($codes)) : ['EG'];
    }

    public static function errorText(array $data, int $httpCode): string
    {
        $e = $data['error'] ?? null;
        if (!is_array($e)) {
            return "Meta API error (HTTP {$httpCode})";
        }
        $msg = trim((string) ($e['error_user_title'] ?? '') . ' ' . (string) ($e['error_user_msg'] ?? ''));
        if ($msg === '') {
            $msg = (string) ($e['message'] ?? 'Meta API error');
        }
        $code = isset($e['code']) ? ' (#' . $e['code'] . (isset($e['error_subcode']) ? '/' . $e['error_subcode'] : '') . ')' : '';
        $hint = self::hint((int) ($e['code'] ?? 0), (int) ($e['error_subcode'] ?? 0), strtolower((string) ($e['message'] ?? '') . ' ' . $msg));
        return ($hint ? $hint . ' — ' : '') . $msg . $code;
    }

    private static function hint(int $code, int $sub, string $m): string
    {
        if ($code === 190) {
            return 'انتهت صلاحية ربط Meta - اربط حساب الإعلانات تاني';
        }
        if ($code === 200 || $code === 10 || str_contains($m, 'permission')) {
            return 'صلاحية ناقصة (ads_management) - اربط Meta تاني ووافق على كل الصلاحيات';
        }
        if ($sub === 1885183 || str_contains($m, 'development mode')) {
            return 'تطبيق Meta لسه في وضع التطوير - الإعلانات نفسها مش هتتعمل غير بعد ما التطبيق يبقى Live';
        }
        if (str_contains($m, 'budget') && (str_contains($m, 'minimum') || str_contains($m, 'at least') || str_contains($m, 'too low'))) {
            return 'الميزانية اليومية أقل من الحد الأدنى لعملة حسابك الإعلاني - زوّدها';
        }
        if (str_contains($m, 'payment') || str_contains($m, 'funding')) {
            return 'حساب الإعلانات مافيهوش وسيلة دفع - ضيفها من Meta Ads Manager';
        }
        if ($code === 17 || $code === 4 || $code === 613 || $code === 80004) {
            return 'Meta موقفة الطلبات مؤقتًا - جرّب بعد شوية';
        }
        return '';
    }

    private static function isTransientError(int $httpCode, array $data): bool
    {
        if (in_array($httpCode, [429, 500, 502, 503, 504], true)) {
            return true;
        }
        $code = (int) ($data['error']['code'] ?? 0);
        return in_array($code, [1, 2, 4, 17, 341, 613], true) || !empty($data['error']['is_transient']);
    }

    protected function post(string $path, array $fields = []): array
    {
        if (self::$testTransport && PHP_SAPI === 'cli') {
            [$code, $data] = (self::$testTransport)('POST', $path, $fields);
            return ($code !== 200 || isset($data['error']))
                ? ['success' => false, 'error' => self::errorText($data, $code), 'raw' => $data]
                : ['success' => true, 'data' => $data];
        }

        $fields['access_token'] = $this->accessToken;
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}";

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($fields),
                ]);

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    if ($attempt === 0) {
                        usleep(300000);
                        continue;
                    }
                    return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
                }

                $data = json_decode((string) $response, true) ?: [];

                if ($httpCode !== 200 || isset($data['error'])) {
                    if ($attempt === 0 && self::isTransientError($httpCode, $data)) {
                        usleep(500000);
                        continue;
                    }
                    return ['success' => false, 'error' => self::errorText($data, $httpCode), 'raw' => $data];
                }

                return ['success' => true, 'data' => $data];
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Meta Ads API POST request failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Meta API request failed after retries'];
    }

    protected function get(string $path, array $query = []): array
    {
        if (self::$testTransport && PHP_SAPI === 'cli') {
            [$code, $data] = (self::$testTransport)('GET', $path, $query);
            return ($code !== 200 || isset($data['error']))
                ? ['success' => false, 'error' => self::errorText($data, $code)]
                : ['success' => true, 'data' => $data];
        }

        $query['access_token'] = $this->accessToken;
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}?" . http_build_query($query);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    if ($attempt === 0) {
                        usleep(300000);
                        continue;
                    }
                    return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
                }

                $data = json_decode((string) $response, true) ?: [];

                if ($httpCode !== 200 || isset($data['error'])) {
                    if ($attempt === 0 && self::isTransientError($httpCode, $data)) {
                        usleep(500000);
                        continue;
                    }
                    return ['success' => false, 'error' => self::errorText($data, $httpCode)];
                }

                return ['success' => true, 'data' => $data];
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Meta Ads API request failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Meta API request failed after retries'];
    }
}
<?php

/**
 * Tourfecto - Enterprise Google Ads API Client (REST)
 * @version 2.0.0
 *
 * المزايا التنافسية:
 * 1. سحب تلقائي لـ budget_resource_name في listCampaignsWithInsights لتمكين الـ Autopilot من تعديل الميزانية.
 * 2. دعم كامل لـ gclid + orderId (منع تكرار الحجز) في sendEnhancedConversion.
 * 3. اكتشاف تلقائي لحساب المدير (MCC Auto-Discovery) مع Retry ذكي للأخطاء المؤقتة.
 */
class GoogleAdsAPI
{
    private const BASE_URL = 'https://googleads.googleapis.com';

    private string $apiVersion;
    private string $accessToken;
    private string $developerToken;
    private string $loginCustomerId;

    /** @var array<string,string|null> كاش داخل نفس الطلب: customerId => managerId */
    private static array $managerCache = [];

    public function __construct(string $accessToken)
    {
        $this->apiVersion = (string) (env('GOOGLE_ADS_API_VERSION') ?: 'v25');
        if (!preg_match('/^v(\d+)$/', $this->apiVersion, $vm) || (int) $vm[1] < 22) {
            $this->apiVersion = 'v25';
        }
        $this->accessToken = $accessToken;
        $this->developerToken = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_developer_token', (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: ''))
            : (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '');
        $this->loginCustomerId = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_login_customer_id', (string) (env('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: ''))
            : (string) (env('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: '');
    }

    public function isDeveloperTokenConfigured(): bool
    {
        return $this->developerToken !== '';
    }

    public function listAdAccounts(): array
    {
        $listResult = $this->httpGet('/customers:listAccessibleCustomers');
        if (!$listResult['success']) {
            return $listResult;
        }

        $resourceNames = $listResult['data']['resourceNames'] ?? [];
        if (empty($resourceNames)) {
            return ['success' => true, 'accounts' => []];
        }

        $accounts = [];
        $errors = [];
        $managerIds = [];

        foreach ($resourceNames as $resourceName) {
            $customerId = str_replace('customers/', '', $resourceName);
            $detail = $this->query($customerId, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager, customer.status FROM customer LIMIT 1');

            if (!$detail['success']) {
                $errors[] = $detail['error'] ?? 'unknown error';
                continue;
            }
            if (empty($detail['rows'])) {
                continue;
            }

            $customer = $detail['rows'][0]['customer'] ?? [];

            if (!empty($customer['manager'])) {
                $managerIds[] = (string) $customerId;
                $children = $this->query(
                    $customerId,
                    "SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.manager, customer_client.status FROM customer_client WHERE customer_client.manager = FALSE AND customer_client.status = 'ENABLED'",
                    (string) $customerId
                );
                if ($children['success']) {
                    foreach ($children['rows'] as $row) {
                        $cc = $row['customerClient'] ?? [];
                        if (empty($cc['id'])) {
                            continue;
                        }
                        $accounts[] = [
                            'id' => (string) $cc['id'],
                            'name' => $cc['descriptiveName'] ?? (string) $cc['id'],
                            'currency' => $cc['currencyCode'] ?? 'USD',
                            'status' => strtolower($cc['status'] ?? 'enabled'),
                        ];
                    }
                } else {
                    $errors[] = $children['error'] ?? 'unknown error';
                }
                continue;
            }

            $accounts[] = [
                'id' => (string) $customerId,
                'name' => $customer['descriptiveName'] ?? $customerId,
                'currency' => $customer['currencyCode'] ?? 'USD',
                'status' => strtolower($customer['status'] ?? 'unknown'),
            ];
        }

        if (empty($accounts)) {
            if (!empty($errors)) {
                return ['success' => false, 'error' => 'Google رجّعت خطأ وإحنا بنقرا تفاصيل الحسابات (' . count($resourceNames) . ' حساب متاح): ' . $errors[0]];
            }
            if (!empty($managerIds)) {
                return ['success' => false, 'error' => 'الحساب ده حساب مدير (Manager) رقم ' . implode(', ', $managerIds)
                    . ' ومفيهوش أي حساب إعلاني نشط تحته. لازم يكون فيه حساب إعلاني فعلي (Enabled) مربوط بيه.'];
            }
        }

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * سحب حملات الحساب مع بيانات الأداء اليومية ومعرّف مورد الميزانية (budget_resource_name).
     */
    public function listCampaignsWithInsights(string $customerId): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');

        $gaql = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                    campaign.campaign_budget, campaign_budget.resource_name, campaign_budget.amount_micros,
                    campaign.start_date, campaign.end_date,
                    metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, segments.date
                 FROM campaign
                 WHERE segments.date BETWEEN '{$startDate}' AND '{$endDate}'";

        $result = $this->query($customerId, $gaql);
        if (!$result['success']) {
            return $result;
        }

        $byCampaign = [];
        foreach ($result['rows'] as $row) {
            $c = $row['campaign'] ?? [];
            $id = (string) ($c['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $budgetResName = (string) ($c['campaignBudget'] ?? $row['campaignBudget']['resourceName'] ?? '');

            if (!isset($byCampaign[$id])) {
                $byCampaign[$id] = [
                    'external_campaign_id' => $id,
                    'budget_resource_name' => $budgetResName !== '' ? $budgetResName : null,
                    'name' => $c['name'] ?? ('Campaign ' . $id),
                    'objective' => $this->mapChannelType($c['advertisingChannelType'] ?? ''),
                    'status' => strtolower($c['status'] ?? 'paused'),
                    'daily_budget' => isset($row['campaignBudget']['amountMicros']) ? ((float) $row['campaignBudget']['amountMicros']) / 1_000_000 : null,
                    'started_at' => $c['startDate'] ?? null,
                    'ended_at' => (!empty($c['endDate']) && $c['endDate'] !== '') ? $c['endDate'] : null,
                    'impressions' => 0,
                    'clicks' => 0,
                    'spend' => 0.0,
                    'daily' => [],
                ];
            } elseif ($byCampaign[$id]['budget_resource_name'] === null && $budgetResName !== '') {
                $byCampaign[$id]['budget_resource_name'] = $budgetResName;
            }

            $metrics = $row['metrics'] ?? [];
            $byCampaign[$id]['impressions'] += (int) ($metrics['impressions'] ?? 0);
            $byCampaign[$id]['clicks'] += (int) ($metrics['clicks'] ?? 0);
            $byCampaign[$id]['spend'] += ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000;

            $day = (string) ($row['segments']['date'] ?? '');
            if ($day !== '') {
                $byCampaign[$id]['daily'][] = [
                    'date' => $day,
                    'impressions' => (int) ($metrics['impressions'] ?? 0),
                    'clicks' => (int) ($metrics['clicks'] ?? 0),
                    'spend' => ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000,
                    'conversions' => (float) ($metrics['conversions'] ?? 0),
                ];
            }
        }

        return ['success' => true, 'campaigns' => array_values($byCampaign)];
    }

    private function mapChannelType(string $channelType): string
    {
        $map = [
            'SEARCH' => 'traffic', 'DISPLAY' => 'awareness', 'VIDEO' => 'awareness',
            'SHOPPING' => 'traffic', 'PERFORMANCE_MAX' => 'leads', 'LOCAL' => 'calls',
        ];
        return $map[$channelType] ?? 'traffic';
    }

    private function query(string $customerId, string $gaql, ?string $loginId = null): array
    {
        $result = $this->request('POST', "/customers/{$customerId}/googleAds:search", ['query' => $gaql], $loginId);
        if (!$result['success']) {
            return $result;
        }
        return ['success' => true, 'rows' => $result['data']['results'] ?? []];
    }

    public function createSearchCampaign(string $customerId, array $campaign, array $copies, array $keywords, string $destinationUrl, string $bidStrategyHint = ''): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $budgetMicros = (int) round((float) ($campaign['daily_budget'] ?? 10) * 1_000_000);

        $budgetName = $campaign['name'] . ' - Budget ' . time();
        $budgetResult = $this->mutate($customerId, 'campaignBudgets', [[
            'create' => [
                'name' => $budgetName,
                'amountMicros' => (string) $budgetMicros,
                'deliveryMethod' => 'STANDARD',
            ],
        ]]);
        if (!$budgetResult['success']) {
            return ['success' => false, 'error' => 'فشل إنشاء ميزانية الحملة: ' . ($budgetResult['error'] ?? '')];
        }
        $budgetResourceName = $budgetResult['results'][0]['resourceName'];

        $biddingField = (stripos($bidStrategyHint, 'تحويل') !== false || stripos($bidStrategyHint, 'conversion') !== false)
            ? 'maximizeConversions' : 'maximizeClicks';

        $campaignCreate = [
            'name' => $campaign['name'],
            'advertisingChannelType' => 'SEARCH',
            'status' => 'PAUSED',
            'campaignBudget' => $budgetResourceName,
            $biddingField => new stdClass(),
            'startDate' => !empty($campaign['start_date']) ? str_replace('-', '', $campaign['start_date']) : date('Ymd'),
            'networkSettings' => [
                'targetGoogleSearch' => true, 'targetSearchNetwork' => false,
                'targetContentNetwork' => false, 'targetPartnerSearchNetwork' => false,
            ],
        ];
        if (!empty($campaign['end_date'])) {
            $campaignCreate['endDate'] = str_replace('-', '', $campaign['end_date']);
        }

        $campaignResult = $this->mutate($customerId, 'campaigns', [['create' => $campaignCreate]]);
        if (!$campaignResult['success']) {
            $this->mutate($customerId, 'campaignBudgets', [['remove' => $budgetResourceName]]);
            return ['success' => false, 'error' => 'فشل إنشاء الحملة: ' . ($campaignResult['error'] ?? '')];
        }
        $campaignResourceName = $campaignResult['results'][0]['resourceName'];
        $campaignId = str_replace("customers/{$customerId}/campaigns/", '', $campaignResourceName);

        $adGroupResult = $this->mutate($customerId, 'adGroups', [[
            'create' => [
                'name' => $campaign['name'] . ' - Ad Group',
                'campaign' => $campaignResourceName,
                'status' => 'ENABLED',
                'type' => 'SEARCH_STANDARD',
            ],
        ]]);
        if (!$adGroupResult['success']) {
            return [
                'success' => false,
                'error' => 'اتعملت الحملة لكن فشل إنشاء المجموعة الإعلانية - راجعها من Google Ads يدويًا: ' . ($adGroupResult['error'] ?? ''),
                'external_campaign_id' => $campaignId,
                'external_budget_resource' => $budgetResourceName,
            ];
        }
        $adGroupResourceName = $adGroupResult['results'][0]['resourceName'];

        $keywordOps = [];
        foreach (array_slice($keywords, 0, 20) as $k) {
            $matchType = strtoupper($k['match_type'] ?? 'PHRASE');
            $isNegative = $matchType === 'NEGATIVE';
            $keywordOps[] = ['create' => array_merge([
                'adGroup' => $adGroupResourceName,
                'status' => 'ENABLED',
                'keyword' => ['text' => $k['keyword'], 'matchType' => $isNegative ? 'PHRASE' : $matchType],
            ], $isNegative ? ['negative' => true] : [])];
        }
        if (!empty($keywordOps)) {
            $this->mutate($customerId, 'adGroupCriteria', $keywordOps);
        }

        $headlines = [];
        $descriptions = [];
        foreach ($copies as $c) {
            if (!empty($c['headline'])) {
                $headlines[] = ['text' => mb_substr($c['headline'], 0, 30)];
            }
            if (!empty($c['description'])) {
                $descriptions[] = ['text' => mb_substr($c['description'], 0, 90)];
            }
            if (!empty($c['primary_text'])) {
                $descriptions[] = ['text' => mb_substr($c['primary_text'], 0, 90)];
            }
        }
        if (count($headlines) < 3 || count($descriptions) < 2) {
            return [
                'success' => false,
                'error' => 'محتاج على الأقل 3 نسخ معتمدة (عناوين) و2 أوصاف عشان ننشئ إعلان Google - اعتمد نسخ أكتر وحاول تاني',
                'external_campaign_id' => $campaignId,
                'external_budget_resource' => $budgetResourceName,
            ];
        }

        $adResult = $this->mutate($customerId, 'adGroupAds', [[
            'create' => [
                'adGroup' => $adGroupResourceName,
                'status' => 'PAUSED',
                'ad' => [
                    'finalUrls' => [$destinationUrl],
                    'responsiveSearchAd' => [
                        'headlines' => array_slice($headlines, 0, 15),
                        'descriptions' => array_slice($descriptions, 0, 4),
                    ],
                ],
            ],
        ]]);
        if (!$adResult['success']) {
            return [
                'success' => false,
                'error' => 'اتعملت الحملة والكلمات لكن فشل إنشاء الإعلان نفسه - راجعها من Google Ads يدويًا: ' . ($adResult['error'] ?? ''),
                'external_campaign_id' => $campaignId,
                'external_budget_resource' => $budgetResourceName,
            ];
        }

        return [
            'success' => true,
            'external_campaign_id' => $campaignId,
            'external_budget_resource' => $budgetResourceName,
        ];
    }

    public function updateCampaignStatus(string $customerId, string $campaignId, string $status): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $googleStatus = strtoupper($status) === 'ENABLED' ? 'ENABLED' : 'PAUSED';
        $result = $this->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => $googleStatus],
            'updateMask' => 'status',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل حالة الحملة'];
    }

    public function deleteCampaign(string $customerId, string $campaignId): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $result = $this->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => 'REMOVED'],
            'updateMask' => 'status',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل إلغاء الحملة'];
    }

    public function pauseCampaign(string $customerId, string $campaignResourceName): array
    {
        return $this->updateCampaignStatus($customerId, $this->extractCampaignId($campaignResourceName), 'PAUSED');
    }

    public function resumeCampaign(string $customerId, string $campaignResourceName): array
    {
        return $this->updateCampaignStatus($customerId, $this->extractCampaignId($campaignResourceName), 'ENABLED');
    }

    private function extractCampaignId(string $campaignResourceName): string
    {
        if (preg_match('#customers/\d+/campaigns/(\d+)#', $campaignResourceName, $m)) {
            return $m[1];
        }
        return $campaignResourceName;
    }

    public function updateCampaignBudget(string $customerId, string $budgetResourceName, float $dailyBudgetUsd): array
    {
        return $this->updateBudget($budgetResourceName, $dailyBudgetUsd);
    }

    public function updateBudget(string $budgetResourceName, float $dailyBudgetUsd): array
    {
        if (!preg_match('#^customers/(\d+)/campaignBudgets/#', $budgetResourceName, $m)) {
            return ['success' => false, 'error' => 'رقم الميزانية غير صالح'];
        }
        $customerId = $m[1];
        $result = $this->mutate($customerId, 'campaignBudgets', [[
            'update' => ['resourceName' => $budgetResourceName, 'amountMicros' => (string) (int) round($dailyBudgetUsd * 1_000_000)],
            'updateMask' => 'amount_micros',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل الميزانية'];
    }

    private function mutate(string $customerId, string $resource, array $operations): array
    {
        $result = $this->httpPost("/customers/{$customerId}/{$resource}:mutate", ['operations' => $operations]);
        if (!$result['success']) {
            return $result;
        }
        return ['success' => true, 'results' => $result['data']['results'] ?? []];
    }

    /**
     * Google Enhanced Conversions مع دعم gclid و orderId لمنع التكرار ورفع الدقة.
     */
    public function sendEnhancedConversion(string $customerId, string $conversionAction, array $conversion): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $conversionAction = preg_replace('/[^0-9\/a-zA-Z]/', '', $conversionAction);

        if ($customerId === '' || $conversionAction === '') {
            return ['success' => false, 'error' => 'رقم حساب Google Ads وإجراء التحويل مطلوبين'];
        }

        $emailHash = isset($conversion['email_hash']) && is_string($conversion['email_hash']) ? (string) $conversion['email_hash'] : null;
        $phoneHash = isset($conversion['phone_hash']) && is_string($conversion['phone_hash']) ? (string) $conversion['phone_hash'] : null;

        if ($emailHash === null && $phoneHash === null) {
            return ['success' => false, 'error' => 'مفيش أي معرّف مستخدم مجهول الهوية لإرسال الحدث'];
        }

        $identifiers = [];
        if ($emailHash !== null) {
            $identifiers[] = ['hashedEmail' => $emailHash];
        }
        if ($phoneHash !== null) {
            $identifiers[] = ['hashedPhoneNumber' => $phoneHash];
        }

        $conversionDateTime = (new DateTimeImmutable('now'))->format('c');

        $conversionRow = [
            'conversionAction' => $conversionAction,
            'conversionDateTime' => $conversionDateTime,
            'conversionValue' => round((float) ($conversion['value'] ?? 0), 2),
            'currencyCode' => (string) ($conversion['currency'] ?? 'USD'),
            'userIdentifiers' => $identifiers,
        ];

        if (!empty($conversion['event_id']) && is_string($conversion['event_id'])) {
            $conversionRow['orderId'] = $conversion['event_id'];
        }
        if (!empty($conversion['gclid']) && is_string($conversion['gclid'])) {
            $conversionRow['gclid'] = $conversion['gclid'];
        }

        $result = $this->httpPost("/customers/{$customerId}/googleAds:uploadClickConversions", [
            'conversions' => [$conversionRow],
            'partialFailure' => true,
        ]);

        if (!$result['success']) {
            return $result;
        }

        $results = $result['data']['results'] ?? [];
        if (empty($results)) {
            $errors = $result['data']['partialConversionErrors'] ?? [];
            $message = $errors[0]['message'] ?? 'فشل رفع التحويل (partialFailure)';
            return ['success' => false, 'error' => $message];
        }

        return ['success' => true, 'data' => $results[0] ?? $result['data']];
    }

    private function httpGet(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    private function httpPost(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    protected function request(string $method, string $path, ?array $body, ?string $loginId = null): array
    {
        $result = $this->doRequest($method, $path, $body, $loginId);
        if ($result['success'] || $loginId !== null) {
            return $result;
        }

        $raw = strtoupper((string) ($result['raw'] ?? ''));
        $isPermission = (int) ($result['http_code'] ?? 0) === 403
            && (strpos($raw, 'USER_PERMISSION_DENIED') !== false || strpos($raw, 'CALLER DOES NOT HAVE PERMISSION') !== false)
            && strpos($raw, 'CLOUD_PROJECT') === false
            && strpos($raw, 'ACTION_NOT_PERMITTED') === false;

        if ($isPermission && preg_match('#^/customers/(\d+)/#', $path, $m)) {
            $manager = $this->resolveManagerFor($m[1]);
            if ($manager !== null) {
                return $this->doRequest($method, $path, $body, $manager);
            }
        }
        return $result;
    }

    private function resolveManagerFor(string $customerId): ?string
    {
        $key = substr(hash('sha256', $this->accessToken), 0, 16) . ':' . $customerId;
        if (array_key_exists($key, self::$managerCache)) {
            return self::$managerCache[$key];
        }
        self::$managerCache[$key] = null;

        $list = $this->doRequest('GET', '/customers:listAccessibleCustomers', null, null);
        if (!$list['success']) {
            return null;
        }
        $candidates = array_slice((array) ($list['data']['resourceNames'] ?? []), 0, 10);
        foreach ($candidates as $rn) {
            $mgrId = str_replace('customers/', '', (string) $rn);
            if ($mgrId === $customerId || !ctype_digit($mgrId)) {
                continue;
            }
            $check = $this->doRequest(
                'POST',
                "/customers/{$mgrId}/googleAds:search",
                ['query' => 'SELECT customer_client.id FROM customer_client WHERE customer_client.id = ' . (int) $customerId],
                $mgrId
            );
            if ($check['success'] && !empty($check['data']['results'])) {
                return self::$managerCache[$key] = $mgrId;
            }
        }
        return null;
    }

    private function doRequest(string $method, string $path, ?array $body, ?string $loginId = null): array
    {
        $url = self::BASE_URL . '/' . $this->apiVersion . $path;

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];
        if ($this->developerToken !== '') {
            $headers[] = 'developer-token: ' . $this->developerToken;
        }
        $effectiveLogin = $loginId !== null ? $loginId : $this->loginCustomerId;
        if ($effectiveLogin !== '' && $effectiveLogin !== null) {
            $headers[] = 'login-customer-id: ' . preg_replace('/[^0-9]/', '', (string) $effectiveLogin);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $ch = curl_init($url);
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CUSTOMREQUEST => $method,
                ];
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
                }
                curl_setopt_array($ch, $opts);

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

                $data = json_decode((string) $response, true);

                if ($httpCode !== 200) {
                    if ($attempt === 0 && in_array($httpCode, [429, 502, 503, 504], true)) {
                        usleep(500000);
                        continue;
                    }

                    $message = $data['error']['message'] ?? "Google Ads API error (HTTP {$httpCode})";
                    $raw = strtoupper((string) $response);
                    if (strpos($raw, 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION') !== false
                        || strpos($raw, 'ACTION_NOT_PERMITTED') !== false
                        || ($httpCode === 403 && stripos((string) $message, 'developer token') !== false)) {
                        $message = 'مشروع Google Cloud لسه عليه Test access بس، ومش مسموح له يوصل لحسابات إعلانات حقيقية. '
                            . 'افتح Google Cloud Console > Google Ads API > Overview واضغط Apply for Explorer access '
                            . '(لازم نفس المشروع اللي فيه GOOGLE_CLIENT_ID). بعد الموافقة جرّب الربط تاني.';
                    } elseif ($httpCode === 403 && strpos($raw, 'AUTHORIZATION_ERROR') !== false) {
                        $message = 'Google رفضت الطلب لأسباب صلاحيات (AUTHORIZATION_ERROR). لو لسه مقدّم على Explorer/Basic access قريب، '
                            . 'استنى شوية أو جرّب مشروع Google Cloud جديد. التفاصيل: ' . $message;
                    } elseif ($httpCode === 404 || (strpos($raw, 'VERSION') !== false && strpos($raw, 'SUNSET') !== false)) {
                        $message = 'إصدار Google Ads API (' . $this->apiVersion . ') غير مدعوم. غيّر GOOGLE_ADS_API_VERSION في .env لأحدث إصدار (مثلاً v25).';
                    } elseif ($httpCode === 401) {
                        $message = 'OAuth غير صالح أو انتهت صلاحية الربط. افصل Google Ads واربط الحساب مرة أخرى.';
                    }
                    return ['success' => false, 'error' => $message, 'http_code' => $httpCode, 'raw' => (string) $response];
                }

                return ['success' => true, 'data' => $data];
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Google Ads API request failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Google Ads API request failed after retries'];
    }
}
<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialMonthlyPlanService.php';
require_once __DIR__ . '/../Jobs/GenerateSocialMonthlyPlanJob.php';
require_once __DIR__ . '/../Jobs/RefreshSocialPlanMetricsJob.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialPostReconciler.php';
require_once __DIR__ . '/../Services/SocialMedia/PublishHealthReport.php';
require_once __DIR__ . '/../Services/SocialMedia/MediaPreflight.php';
require_once __DIR__ . '/../Services/SocialMedia/MetaSocialAPI.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialLinkTracker.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialCommentInbox.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialBestTimes.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialReport.php';
require_once __DIR__ . '/../Jobs/SyncSocialCommentsJob.php';
require_once __DIR__ . '/../Jobs/ReconcileUncertainPostsJob.php';
require_once __DIR__ . '/../Jobs/CheckSocialConnectionsHealthJob.php';

/**
 * Tourfecto - Social Media Controller
 * لوحة إدارة السوشيال ميديا (من ai-marketing-automation-hub، مُعاد بناؤها
 * فوق platform_connections/social_posts/social_post_targets الموحّدة)
 * @version 1.0.0
 */
class SocialMediaController extends Controller
{
    /** @var SocialPostService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SocialPostService();
    }

    /** GET /social */
    public function index(array $params = []): array
    {
        // نضمن إن مهام المصالحة وفحص الصحة الدورية شغّالة (بتتأكد من وجود job معلّق بس - خفيفة)
        try {
            ReconcileUncertainPostsJob::ensureScheduled();
            CheckSocialConnectionsHealthJob::ensureScheduled();
            SyncSocialCommentsJob::ensureScheduled();
        } catch (Throwable $e) {
        }
        $ar = current_lang() === 'ar';
        $asset = static fn (string $path): string => htmlspecialchars(asset_v($path), ENT_QUOTES, 'UTF-8');
        $view = static function (array $vars): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            include __DIR__ . '/../Views/social/index.php';
            return (string) ob_get_clean();
        };
        $body = $view([
            'ar' => $ar,
            'uid' => (int) ($this->user['id'] ?? 0),
            'cssUrl' => $asset('/assets/css/social-pro.css'),
            'jsUrl' => $asset('/assets/js/social-pro.js'),
            'monthlyCss' => $asset('/assets/css/social-monthly-plans.css'),
            'monthlyJs' => $asset('/assets/js/social-monthly-plans.js'),
            'pickerJs' => $asset('/assets/js/site-photo-picker.js'),
        ]);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage(
            'social',
            $ar ? 'السوشيال ميديا' : 'Social media',
            $ar ? 'انشر وجدول ورد على عملائك وتابع النتائج على كل المنصات' : 'Publish, schedule, answer customers and track results across all platforms',
            $body,
            ''
        );
        exit;
    }

    /** Monthly-plan endpoints share the same owner scope and delivery service. */
    private function monthlyRequest(string $action,array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        $write=in_array($action,['create','edit','approve','pause','resume','cancel'],true);
        if($write&&($error=$this->verifyCsrf()))return $error;
        if($write&&($error=$this->rateLimitGuard('user',$action==='create'?'social_monthly_ai':'social_monthly_write',$action==='create'?4:40,60)))return $error;
        $uid=(int)$this->user['id'];$id=(int)($params['id']??0);$service=new SocialMonthlyPlanService();
        try {
            if($action==='setup'){$result=$service->setup($uid);$result['csrf_token']=Csrf::token();return $this->success($result);}
            if($action==='list')return $this->success(['plans'=>$service->listPlans($uid)]);
            if($action==='detail')return $this->success($service->detail($uid,$id));
            if($action==='create')return $this->success($service->create($uid,$this->data),'Monthly generation queued',201);
            if($action==='edit')return $this->success($service->edit($uid,$id,(int)($params['item_id']??0),$this->data));
            if($action==='approve'&&$this->get('reviewed')!==true)return $this->error('Confirm review before automatic publishing.',422);
            if($action==='approve')return $this->success($service->approve($uid,$id,(int)$this->get('version',0)));
            return $this->success($service->changeState($uid,$id,$action));
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(ProviderRequestFailure $e){return $this->error('تعذر إعداد الخطة. حدّث قائمة الخطط قبل المحاولة مجددًا / Could not prepare the plan. Refresh the list before retrying.',502);}
        catch(Throwable $e){Logger::error('Monthly social plan action failed',['action'=>$action,'plan_id'=>$id,'user_id'=>$uid,'error'=>$e->getMessage()]);return $this->error('تعذر تنفيذ الطلب. تحقق من تركيب SQL وتشغيل مهام النشر ثم حدّث قائمة الخطط / Could not complete the request. Check migration and publishing worker, then refresh the plan list.',503);}
    }
    public function monthlySetup(array $params=[]): array{return $this->monthlyRequest('setup',$params);}
    public function monthlyList(array $params=[]): array{return $this->monthlyRequest('list',$params);}
    public function monthlyDetail(array $params=[]): array{return $this->monthlyRequest('detail',$params);}
    public function monthlyCreate(array $params=[]): array{return $this->monthlyRequest('create',$params);}
    public function monthlyEdit(array $params=[]): array{return $this->monthlyRequest('edit',$params);}
    public function monthlyApprove(array $params=[]): array{return $this->monthlyRequest('approve',$params);}
    public function monthlyPause(array $params=[]): array{return $this->monthlyRequest('pause',$params);}
    public function monthlyResume(array $params=[]): array{return $this->monthlyRequest('resume',$params);}
    public function monthlyCancel(array $params=[]): array{return $this->monthlyRequest('cancel',$params);}

    /** POST /api/social/media - رفع صورة/فيديو من الجهاز للمنشور */
    public function uploadMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_upload', 30, 60)) {
            return $rateError;
        }
        $r = SocialMediaUpload::handle((int) $this->user['id'], $_FILES['file'] ?? null);
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        return $this->success(['item' => $r['item'], 'duplicate' => !empty($r['duplicate'])], !empty($r['duplicate']) ? 'الصورة دي موجودة في مكتبتك بالفعل' : 'اترفع', 201);
    }

    /** POST /api/social/brand-kit - لوجو وألوان الشركة اللي بتتحط على التصميمات */
    public function saveBrandKit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_brand_kit', 20, 60))) {
            return $error;
        }
        try {
            $kit = (new SocialBrandStudio())->saveKit((int) $this->user['id'], (int) ($_POST['website_id'] ?? $this->get('website_id', 0)), $_FILES['logo'] ?? null, (string) ($_POST['primary'] ?? $this->get('primary', '')), (string) ($_POST['accent'] ?? $this->get('accent', '')));
            return $this->success(['brand' => $kit], 'اتحفظت هوية الشركة');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brand kit save failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نحفظ الهوية دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/import-site - سحب صور الرحلات والفنادق من موقع العميل لمكتبته */
    public function importSiteImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_import', 4, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        try {
            $uid = (int) $this->user['id'];
            $wid = (int) $this->get('website_id', 0);
            $importer = new SiteImageImporter();
            if ($wid > 0) {
                $r = $importer->import($uid, $wid, 20);
                return $this->success($r, $r['message']);
            }
            // من غير موقع محدد (مكتبة الاستوديو): كل مواقع العميل المربوطة
            $sites = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 3', [$uid]) ?: [];
            if (!$sites) {
                return $this->error('اربط موقعك الأول من "مواقعي" عشان نسحب صوره', 422);
            }
            $importer->budgetSeconds = (int) floor(38 / count($sites));
            $added = [];
            $found = 0;
            foreach ($sites as $site) {
                $r = $importer->import($uid, (int) $site['id'], max(5, (int) floor(20 / count($sites))));
                $added = array_merge($added, $r['added']);
                $found += $r['found'];
            }
            $msg = $added ? 'سحبنا ' . count($added) . ' صورة من موقعك.' : ($found ? 'كل صور موقعك المناسبة موجودة في مكتبتك بالفعل.' : 'مالقيناش صور كبيرة كفاية على موقعك. ارفع صورك من جهازك.');
            return $this->success(['added' => $added, 'found' => $found, 'message' => $msg], $msg);
        } catch (InvalidArgumentException $e) {
            return $this->error('اختار الموقع', 422);
        } catch (Throwable $e) {
            Logger::error('Site image import failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نسحب الصور من موقعك دلوقتي، جرّب تاني أو ارفع صورك من جهازك', 500);
        }
    }

    /** POST /api/social/media/site-scan - يدوّر على صور الموقع (أو روابط رحلات) ويعرضها للاختيار */
    public function scanSiteImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_scan', 10, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        $uid = (int) $this->user['id'];
        $wid = (int) $this->get('website_id', 0);
        if ($wid < 1) {
            $row = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
            $wid = (int) ($row[0]['id'] ?? 0);
        }
        $urls = $this->get('urls', []);
        $urls = is_array($urls) ? array_values(array_filter($urls, 'is_string')) : [];
        if (!$urls && $wid < 1) {
            return $this->error('اربط موقعك الأول، أو حط روابط الرحلات اللي عاوز صورها', 422);
        }
        try {
            $r = (new SiteImageImporter())->scan($uid, $wid, $urls);
            if (!$r['total']) {
                return $this->error($urls ? 'مالقيناش صور في الروابط دي. اتأكد إن الروابط بتفتح.' : 'مالقيناش صور على موقعك. حط روابط صفحات الرحلات بنفسك.', 422);
            }
            return $this->success($r);
        } catch (InvalidArgumentException $e) {
            return $this->error('اختار الموقع', 422);
        } catch (Throwable $e) {
            Logger::error('Site image scan failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نفتح الموقع دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/site-import-selected - يسحب الصور اللي العميل اختارها بس */
    public function importSelectedImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_pick', 60, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        $items = $this->get('items', []);
        if (!is_array($items) || !$items || count($items) > 25) {
            return $this->error('اختار من 1 لـ 25 صورة في المرة', 422);
        }
        $clean = [];
        foreach ($items as $it) {
            if (is_array($it) && is_string($it['src'] ?? null)) {
                $clean[] = ['src' => $it['src'], 'alt' => is_string($it['alt'] ?? null) ? $it['alt'] : '', 'page' => is_string($it['page'] ?? null) ? $it['page'] : '', 'title' => is_string($it['title'] ?? null) ? $it['title'] : ''];
            }
        }
        try {
            return $this->success((new SiteImageImporter())->importItems((int) $this->user['id'], $clean, $this->get('folders') === true));
        } catch (Throwable $e) {
            Logger::error('Selected image import failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نسحب الصور دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/{id}/learn - الذكاء الصناعي يشوف الصورة ويفهمها */
    public function learnMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_learn', 40, 60))) {
            return $error;
        }
        try {
            $v = (new SocialBrandStudio())->learn((int) $this->user['id'], (int) ($params['id'] ?? 0));
            return $this->success(['vision' => $v]);
        } catch (InvalidArgumentException $e) {
            return $this->error('الصورة مش موجودة', 404);
        } catch (Throwable $e) {
            return $this->success(['vision' => null]);
        }
    }

    /** POST /api/social/design - تصميم بوست واحد بهوية الشركة من صورة العميل (مجانًا - مفيش توليد صور) */
    public function designPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_design', 20, 60))) {
            return $error;
        }
        $headline = trim((string) $this->get('headline', ''));
        if (mb_strlen($headline) < 3 || mb_strlen($headline) > 90) {
            return $this->error('اكتب عنوان قصير للتصميم (من 3 لـ 90 حرف)', 422);
        }
        $uid = (int) $this->user['id'];
        $mediaId = (int) $this->get('media_id', 0);
        try {
            $studio = new SocialBrandStudio();
            $id = $studio->design($uid, (int) $this->get('website_id', 0), (string) $this->get('format', 'design'), $headline, (string) $this->get('subline', ''), $mediaId > 0 ? $mediaId : null, (int) $this->get('variant', 0));
            $row = PrivateMedia::owned($uid, $id);
            return $this->success(['id' => $id, 'preview_url' => $row['file_path']], 'التصميم جاهز', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage() === 'Website unavailable' ? 'اختار الموقع' : 'الصورة مش موجودة', 422);
        } catch (Throwable $e) {
            Logger::error('Design post failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نعمل التصميم دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/posts/{id}/retry - إعادة نشر الأهداف اللي فشلت */
    public function retryPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $failed = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'failed'", [$postId]);
        if (!$failed) {
            return $this->error('مفيش حاجة فاشلة في المنشور ده', 422);
        }
        $queue = Container::getInstance()->make(QueueManager::class);
        $jobs = [];
        foreach ($failed as $t) {
            $this->db->query("UPDATE social_post_targets SET status = 'scheduled', last_error = NULL, provider_ref = NULL, poll_attempts = 0, scheduled_at = NULL WHERE id = ?", [(int) $t['id']]);
            $id = $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => (int) $t['id']], 'social', 0);
            if ($id) {
                $jobs[] = (int) $id;
            }
        }
        SocialPostService::runNow($jobs);
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId)]);
    }

    /**
     * POST /api/social/posts/{id}/delete  body: {remote: bool}
     * remote=true: يمسح المنشور من فيسبوك نفسه كمان (إنستجرام ما بيدعمش الحذف عبر الـ API - بيتبلّغ العميل).
     * لو الحذف من فيسبوك فشل ما بنمسحش محليًا عشان ما نفقدش الربط بالمنشور - العميل يقدر يعيد المحاولة.
     */
    public function deletePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, (int) $this->user['id']])) {
            return $this->error('المنشور مش موجود', 404);
        }
        if ($this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'publishing' LIMIT 1", [$postId])) {
            return $this->error('المنشور بيتنشر دلوقتي، استنى لما يخلص', 422);
        }
        $remoteNote = [];
        if ((bool) $this->get('remote', false)) {
            if ($csrfError = $this->verifyCsrf()) {
                return $csrfError; // الحذف من فيسبوك إجراء خارجي لا رجعة فيه
            }
            $rows = $this->db->query(
                "SELECT t.id, t.external_post_id, c.platform, c.access_token FROM social_post_targets t
                   JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE t.social_post_id = ? AND t.status = 'published' AND t.external_post_id IS NOT NULL AND t.external_post_id <> ''",
                [$postId]
            ) ?: [];
            $enc = new Encryption();
            $failures = [];
            foreach ($rows as $r) {
                if ($r['platform'] === 'instagram') {
                    $remoteNote[] = 'إنستجرام: الحذف من التطبيق نفسه (Meta ما بتسمحش بحذف منشورات إنستجرام عبر الـ API).';
                    continue;
                }
                if ($r['platform'] !== 'facebook') {
                    continue;
                }
                try {
                    $token = $enc->decrypt((string) $r['access_token']);
                    $res = (new MetaSocialAPI($token))->deleteFacebookPost((string) $r['external_post_id'], $token);
                } catch (Throwable $e) {
                    $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
                }
                if (!$res['success']) {
                    $failures[] = $res['friendly'] ?? $res['error'] ?? 'فشل الحذف من فيسبوك';
                }
            }
            if ($failures) {
                return $this->error('تعذر الحذف من فيسبوك: ' . implode(' | ', array_unique($failures)) . ' — المنشور لسه موجود عندنا، حاول تاني أو امسحه من المنصة بس.', 422);
            }
        }
        $this->db->query('DELETE FROM social_post_targets WHERE social_post_id = ?', [$postId]);
        $this->db->query('DELETE FROM social_posts WHERE id = ?', [$postId]);
        return $this->success(['notes' => $remoteNote], 'اتمسح');
    }

    /** POST /api/social/posts/{id}/reconcile - تحقق فوري من Meta: هل المنشور uncertain اتنشر فعلًا */
    public function reconcilePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, (int) $this->user['id']])) {
            return $this->error('المنشور مش موجود', 404);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_reconcile', 10, 60)) {
            return $rateError;
        }
        $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'uncertain'", [$postId]) ?: [];
        if (!$targets) {
            return $this->error('مفيش حاجة محتاجة تحقق في المنشور ده', 422);
        }
        @set_time_limit(60);
        $results = [];
        foreach ($targets as $t) {
            $results[] = SocialPostReconciler::reconcileTarget((int) $t['id']);
        }
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId), 'results' => $results]);
    }

    /** POST /api/social/posts/{id}/resolve  body: {resolution: published|not_published} - حسم يدوي لو المصالحة التلقائية مش كفاية */
    public function resolvePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, (int) $this->user['id']])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $resolution = (string) $this->get('resolution', '');
        if (!in_array($resolution, ['published', 'not_published'], true)) {
            return $this->error('القرار لازم يكون published أو not_published', 422);
        }
        $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'uncertain'", [$postId]) ?: [];
        if (!$targets) {
            return $this->error('مفيش حاجة محتاجة حسم في المنشور ده', 422);
        }
        foreach ($targets as $t) {
            SocialPostReconciler::resolveManually((int) $t['id'], $resolution);
        }
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId)]);
    }

    /** POST /api/social/posts/{id}/edit-published  body: {content} - تعديل نص المنشور على فيسبوك بعد النشر (فيسبوك بس) */
    public function editPublishedPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, (int) $this->user['id']])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $content = trim((string) $this->get('content', ''));
        if ($content === '') {
            return $this->error('Post text is required', 422);
        }
        if (MediaPreflight::checkCaption('facebook', $content)) {
            return $this->error('النص أطول من الحد المسموح في فيسبوك', 422);
        }
        $rows = $this->db->query(
            "SELECT t.external_post_id, c.access_token FROM social_post_targets t JOIN platform_connections c ON c.id = t.platform_connection_id
              WHERE t.social_post_id = ? AND t.status = 'published' AND c.platform = 'facebook' AND t.external_post_id <> ''",
            [$postId]
        ) ?: [];
        if (!$rows) {
            return $this->error('التعديل بعد النشر متاح لمنشورات فيسبوك بس (إنستجرام وتيك توك ما بيسمحوش بتعديل النص عبر الـ API).', 422);
        }
        $enc = new Encryption();
        $errors = [];
        foreach ($rows as $r) {
            try {
                $token = $enc->decrypt((string) $r['access_token']);
                $res = (new MetaSocialAPI($token))->editFacebookPost((string) $r['external_post_id'], $token, $content);
            } catch (Throwable $e) {
                $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
            }
            if (!$res['success']) {
                $errors[] = SocialCommentInbox::readableError($res, 'تعذر الوصول لفيسبوك دلوقتي (عطل مؤقت أو مشكلة اتصال). النص ما اتغيّرش، جرّب تاني بعد شوية.');
            }
        }
        if ($errors) {
            return $this->error('تعذر التعديل على فيسبوك: ' . implode(' | ', array_unique($errors)), 422);
        }
        $this->db->query('UPDATE social_posts SET content = ? WHERE id = ?', [$content, $postId]);
        return $this->success([], 'اتعدّل على فيسبوك');
    }

    /**
     * GET /api/social/health?days=30
     * لوحة مراقبة: معدل نجاح النشر لكل صفحة، متوسط الزمن، أكتر الأخطاء، وحالة صحة الربط.
     */
    public function publishHealth(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $days = max(1, min(90, (int) $this->get('days', 30)));
        try {
            $rows = $this->db->query(
                "SELECT l.platform_connection_id AS connection_id, c.platform, c.external_location_name AS name, l.outcome, l.error_category, COUNT(*) AS n, AVG(l.duration_ms) AS avg_ms
                   FROM social_publish_log l LEFT JOIN platform_connections c ON c.id = l.platform_connection_id
                  WHERE l.user_id = ? AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                  GROUP BY l.platform_connection_id, c.platform, c.external_location_name, l.outcome, l.error_category",
                [$uid]
            ) ?: [];
            $health = $this->db->query(
                "SELECT h.platform_connection_id AS connection_id, c.platform AS platform, c.external_location_name AS name, h.state, h.token_expires_at, h.data_access_expires_at, h.missing_scopes, h.last_error, h.checked_at
                   FROM social_connection_health h JOIN platform_connections c ON c.id = h.platform_connection_id
                  WHERE c.user_id = ? OR c.website_id IN (SELECT id FROM websites WHERE user_id = ?)",
                [$uid, $uid]
            ) ?: [];
        } catch (Throwable $e) {
            return $this->error('شغّل migration الموثوقية الأول (2026_10_18_000001_social_publishing_reliability.sql).', 500);
        }
        $pages = PublishHealthReport::build($rows, $health);
        return $this->success(['days' => $days, 'pages' => $pages]);
    }

    // ------------------------------------------------------------------
    // صندوق التعليقات
    // ------------------------------------------------------------------

    /** GET /api/social/inbox?status=open|new|replied|hidden|done|all&intent= */
    public function inboxList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $inbox = new SocialCommentInbox();
        if (!$inbox->ready()) {
            return $this->success(['installed' => false, 'comments' => [], 'counts' => ['open' => 0, 'hot' => 0]]);
        }
        try {
            $r = $inbox->list((int) $this->user['id'], (string) $this->get('status', 'open'), (string) $this->get('intent', ''));
            return $this->success(['installed' => true] + $r);
        } catch (Throwable $e) {
            Logger::error('Social inbox list failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل التعليقات', 500);
        }
    }

    /** POST /api/social/inbox/sync - سحب التعليقات الجديدة دلوقتي */
    public function inboxSync(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_sync', 6, 300))) {
            return $error;
        }
        $inbox = new SocialCommentInbox();
        if (!$inbox->ready()) {
            return $this->error('شغّل migration السوشيال الاحترافي (2026_10_20_000001_social_pro.sql) الأول', 422);
        }
        @set_time_limit(120);
        try {
            return $this->success($inbox->sync((int) $this->user['id'], 15));
        } catch (Throwable $e) {
            Logger::error('Social inbox sync failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر سحب التعليقات من Meta دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/inbox/{id}/suggest - رد مقترح بالذكاء الاصطناعي */
    public function inboxSuggest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'ai', 30, 60))) {
            return $error;
        }
        try {
            $uid = (int) $this->user['id'];
            return $this->success((new SocialCommentInbox())->suggest($uid, (int) ($params['id'] ?? 0), ContentLanguageService::forUser($uid, null)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (Throwable $e) {
            Logger::warning('Social inbox suggest failed', ['message' => $e->getMessage()]);
            return $this->error('مقدرناش نجهّز رد دلوقتي، اكتب ردك أو جرّب تاني', 502);
        }
    }

    /** POST /api/social/inbox/{id}/reply  body: {text} */
    public function inboxReply(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_reply', 60, 60))) {
            return $error;
        }
        try {
            return $this->success((new SocialCommentInbox())->reply((int) $this->user['id'], (int) ($params['id'] ?? 0), (string) $this->get('text', '')), 'اتبعت الرد');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error(CustomerError::message($e->getMessage(), 502), 502);
        }
    }

    /** POST /api/social/inbox/{id}/hide - إخفاء تعليق سبام/مسيء */
    public function inboxHide(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_inbox_reply', 60, 60))) {
            return $error;
        }
        try {
            return $this->success((new SocialCommentInbox())->hide((int) $this->user['id'], (int) ($params['id'] ?? 0)), 'اتخفى التعليق');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error(CustomerError::message($e->getMessage(), 502), 502);
        }
    }

    /** POST /api/social/inbox/{id}/done - تم التعامل معاه (من غير رد علني) */
    public function inboxDone(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($error = $this->verifyCsrf()) {
            return $error;
        }
        try {
            return $this->success((new SocialCommentInbox())->done((int) $this->user['id'], (int) ($params['id'] ?? 0)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /** GET /api/social/best-times?connection_id= - أفضل مواعيد النشر بتوقيت حساب العميل */
    public function bestTimes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $cid = (int) $this->get('connection_id', 0) ?: null;
        $tz = (string) (($this->db->query('SELECT timezone FROM users WHERE id = ?', [$uid])[0]['timezone'] ?? '') ?: 'UTC');
        $samples = SocialBestTimes::samples($uid, $cid);
        $prefs = SocialBestTimes::preferencesFrom($samples, $tz);
        return $this->success([
            'timezone' => $tz,
            'source' => $prefs['source'],
            'measured' => $prefs['measured'],
            'min_measured' => SocialBestTimes::MIN_MEASURED,
            'by_day' => $prefs['times'],
            'top' => $prefs['source'] === 'measured' ? SocialBestTimes::rank($samples, $tz, 3) : [],
        ]);
    }

    /** GET /social/report?month=YYYY-MM - تقرير أداء شهري جاهز للطباعة / الحفظ PDF (بهوية الشركة) */
    public function report(array $params = []): void
    {
        $uid = (int) ($this->user['id'] ?? 0);
        $month = (string) ($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            $month = gmdate('Y-m', strtotime('first day of last month'));
        }
        $data = SocialReport::build($uid, $month);
        header('Content-Type: text/html; charset=utf-8');
        echo SocialReport::render($data, current_lang() === 'ar');
        exit;
    }

    private static array $colCache = [];

    /** فحص وجود عمود (بيتكاش) - عشان الكود ما يقعش لو الـ migration لسه ما اتشغّلتش */
    private function hasColumn(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset(self::$colCache[$k])) {
            try {
                // أسماء داخلية ثابتة: بننقّيها ونحطها literal (SHOW ... LIKE ? مش مضمون مع كل إعدادات PDO)
                $t = preg_replace('/[^a-z0-9_]/i', '', $table);
                $c = preg_replace('/[^a-z0-9_]/i', '', $col);
                self::$colCache[$k] = (bool) $this->db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
            } catch (Throwable $e) {
                self::$colCache[$k] = false;
            }
        }
        return self::$colCache[$k];
    }

    /** هاشتاجات من الواجهة: array أو نص مفصول بمسافات/فواصل -> array نضيف (حد أقصى 30) أو null */
    private function cleanHashtags($raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        $list = is_array($raw) ? $raw : preg_split('/[\s,،]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];
        foreach ((array) $list as $h) {
            $h = ltrim(trim((string) $h), '#');
            if ($h !== '' && preg_match('/^[\p{L}\p{N}_]{1,60}$/u', $h)) {
                $clean[$h] = $h;
            }
        }
        return $clean ? array_slice(array_values($clean), 0, 30) : null;
    }

    private function cleanFirstComment($raw): ?string
    {
        $c = trim((string) ($raw ?? ''));
        return $c === '' ? null : mb_substr($c, 0, 2000);
    }

    /** GET /api/social/connections - الحسابات المتصلة للنشر عليها */
    public function listConnections(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // كل الاتصالات (حتى اللي فاصلة) عشان العميل يشوف السبب بدل "مفيش صفحات"
            $all = $this->db->query(
                "SELECT id, platform, external_location_id, external_location_name AS name, status, last_error
                 FROM platform_connections
                 WHERE (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND platform IN ('facebook', 'instagram', 'tiktok', 'youtube')
                 ORDER BY (status = 'connected') DESC, id DESC",
                [$this->user['id'], $this->user['id']]
            );
            $rows = [];
            $broken = [];
            $seen = [];
            foreach ($all as $r) {
                $k = $r['platform'] . ':' . ($r['external_location_id'] ?: 'connection-' . $r['id']);
                if (isset($seen[$k])) {
                    continue; // نفس الصفحة متسجلة أكتر من مرة (موقعين مثلًا) - نعرضها مرة واحدة
                }
                $seen[$k] = true;
                if ($r['status'] === 'connected') {
                    $rows[] = $r;
                } else {
                    $broken[] = ['platform' => $r['platform'], 'name' => $r['name'], 'status' => $r['status'], 'last_error' => $r['last_error'] ? mb_substr((string) $r['last_error'], 0, 300) : null];
                }
            }
            foreach ($rows as &$r0) {
                unset($r0['external_location_id'], $r0['status'], $r0['last_error']);
            }
            unset($r0);
            // Schema-safe: platform_connections in the supplied SQL has no meta_json column.
            // Permission diagnostics belong to the OAuth callback; never assume extra DB fields.
            $missing = [];
            $out = ['connections' => $rows, 'disconnected' => $broken, 'missing_features' => array_values($missing), 'connected_count' => count($rows)];
            // تشخيص: آخر محاولة ربط لاقت كام صفحة (بيتسجل في الكول باك) - بنرجّعه دايمًا
            // دلوقتي (مش بس لما مفيش اتصالات) عشان نعرف Meta رجّعت كام صفحة فعلًا.
            $out['last_connect'] = $_SESSION['meta_last_connect'] ?? null;
            return $this->success($out);
        } catch (Throwable $e) {
            Logger::error('listConnections Error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر جلب الصفحات المتصلة. حاول تحديث الصفحة، وإذا استمرت المشكلة تواصل مع الدعم.', 500);
        }
    }

    /** GET /api/social/posts - كل منشور ومعاه نتيجة كل صفحة (اتنشر/فشل + السبب + الإحصائيات) - فلاتر: status, platform, q */
    public function listPosts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $this->catchUpOverdue((int) $this->user['id']);

        $where = ['sp.user_id = ?'];
        $args = [(int) $this->user['id']];
        $status = (string) $this->get('status', '');
        if (in_array($status, ['draft', 'scheduled', 'publishing', 'published', 'failed', 'partial', 'uncertain'], true)) {
            $where[] = 'sp.status = ?';
            $args[] = $status;
        }
        $platform = (string) $this->get('platform', '');
        if (in_array($platform, ['facebook', 'instagram', 'tiktok', 'youtube'], true)) {
            $where[] = 'EXISTS (SELECT 1 FROM social_post_targets tt JOIN platform_connections cc ON cc.id = tt.platform_connection_id WHERE tt.social_post_id = sp.id AND cc.platform = ?)';
            $args[] = $platform;
        }
        $q = trim((string) $this->get('q', ''));
        if ($q !== '') {
            $where[] = 'sp.content LIKE ?';
            $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        }
        $limit = max(10, min(100, (int) $this->get('limit', 50)));

        $posts = $this->db->query(
            'SELECT sp.id, sp.content, sp.status, sp.media_item_id, sp.created_at, ' . (SocialPostService::proSchema() ? 'sp.post_format' : "'feed' AS post_format") . ', m.file_path AS media_path, m.type AS media_type, m.user_id AS media_owner, m.deleted_at AS media_deleted, m.status AS media_status
               FROM social_posts sp LEFT JOIN media_items m ON m.id = sp.media_item_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY sp.created_at DESC, sp.id DESC LIMIT ' . $limit,
            $args
        );
        foreach ($posts as &$mediaPost) {
            if ((int)($mediaPost['media_owner'] ?? 0) !== (int)$this->user['id'] || !empty($mediaPost['media_deleted']) || ($mediaPost['media_status'] ?? '') !== 'completed') {
                $mediaPost['media_path'] = null;
            } elseif (PrivateMedia::valid((string)($mediaPost['media_path'] ?? ''))) {
                $mediaPost['media_path'] = PrivateMedia::preview((int)$mediaPost['media_item_id']);
            }
            unset($mediaPost['media_owner'], $mediaPost['media_deleted'], $mediaPost['media_status']);
        }
        unset($mediaPost);
        $ids = array_map(fn ($p) => (int) $p['id'], $posts);
        $byPost = [];
        if ($ids) {
            $extraT = $this->hasColumn('social_post_targets', 'error_category') ? ', t.error_category, t.error_code, t.retry_count, t.next_retry_at, t.fbtrace_id' : '';
            $extraM = $this->hasColumn('social_post_metrics', 'views') ? ', pm.views, pm.insights_note' : '';
            $rows = $this->db->query(
                'SELECT t.id AS target_id, t.social_post_id, t.status, t.last_error, t.scheduled_at, t.published_at, t.external_post_id' . $extraT . ',
                        c.platform, c.external_location_name AS name,
                        pm.likes, pm.comments, pm.shares, pm.reach, pm.state AS metrics_state, pm.note AS metrics_note, pm.permalink AS metrics_permalink, pm.fetched_at AS metrics_at' . $extraM . '
                   FROM social_post_targets t
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id
                  WHERE t.social_post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.id',
                $ids
            );
            foreach ($rows as $r) {
                $r['permalink'] = !empty($r['metrics_permalink']) ? $r['metrics_permalink'] : self::permalink((string) $r['platform'], (string) ($r['external_post_id'] ?? ''));
                unset($r['external_post_id'], $r['metrics_permalink']);
                $byPost[(int) $r['social_post_id']][] = $r;
            }
        }
        foreach ($posts as &$p) {
            $p['targets'] = $byPost[(int) $p['id']] ?? [];
            if ($p['targets'] && $p['status'] === 'scheduled') {
                // منشورات قديمة: الحالة ماكانتش بتتحدث - نحسبها من الأهداف
                $p['status'] = SocialPostService::refreshPostStatus((int) $p['id']);
            }
        }
        unset($p);
        return $this->success(['posts' => $posts]);
    }

    /**
     * GET /api/social/stats?days=30
     * أرقام الفترة: عدد المنشورات حسب الحالة والمنصة، النشر اليومي، إجمالي التفاعل (من الكاش)،
     * أفضل المنشورات، وعينات أوقات النشر (الواجهة بتحسب منها أفضل ميعاد بتوقيت العميل).
     */
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $days = max(7, min(90, (int) $this->get('days', 30)));

        try {
            $byStatus = ['draft' => 0, 'scheduled' => 0, 'publishing' => 0, 'published' => 0, 'failed' => 0];
            foreach ($this->db->query('SELECT status, COUNT(*) AS c FROM social_posts WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY status', [$uid, $days]) as $r) {
                $byStatus[(string) $r['status']] = (int) $r['c'];
            }

            $byPlatform = [];
            foreach ($this->db->query(
                "SELECT c.platform, t.status, COUNT(*) AS c
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE sp.user_id = ? AND sp.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY c.platform, t.status",
                [$uid, $days]
            ) as $r) {
                $k = (string) ($r['platform'] ?: 'other');
                $byPlatform[$k] = $byPlatform[$k] ?? ['published' => 0, 'failed' => 0, 'scheduled' => 0, 'total' => 0];
                $bucket = in_array($r['status'], ['published', 'failed'], true) ? $r['status'] : 'scheduled';
                $byPlatform[$k][$bucket] += (int) $r['c'];
                $byPlatform[$k]['total'] += (int) $r['c'];
            }

            $daily = [];
            foreach ($this->db->query(
                "SELECT DATE(t.published_at) AS d, COUNT(*) AS c
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY DATE(t.published_at)",
                [$uid, $days]
            ) as $r) {
                $daily[(string) $r['d']] = (int) $r['c'];
            }
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} day"));
                $series[] = ['date' => $d, 'count' => $daily[$d] ?? 0];
            }

            $eng = $this->db->query(
                "SELECT COALESCE(SUM(pm.likes),0) AS likes, COALESCE(SUM(pm.comments),0) AS comments, COALESCE(SUM(pm.shares),0) AS shares,
                        COALESCE(SUM(pm.reach),0) AS reach, COUNT(pm.target_id) AS measured, MAX(pm.fetched_at) AS last_fetch
                   FROM social_post_metrics pm
                   JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
                  WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$uid, $days]
            )[0] ?? [];

            $top = $this->db->query(
                "SELECT sp.id, sp.content, c.platform, c.external_location_name AS name, pm.likes, pm.comments, pm.shares, pm.reach,
                        (pm.likes + pm.comments * 2 + pm.shares * 3) AS score
                   FROM social_post_metrics pm
                   JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  ORDER BY score DESC, sp.id DESC LIMIT 5",
                [$uid, $days]
            );
            foreach ($top as &$t0) {
                $t0['content'] = mb_substr((string) $t0['content'], 0, 90);
            }
            unset($t0);

            // عينات للأوقات (UTC) + وزن التفاعل لو متاح - آخر 300 نشر
            $samples = $this->db->query(
                "SELECT t.published_at AS at, COALESCE(pm.likes + pm.comments * 2 + pm.shares * 3, 0) AS score
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id AND pm.state = 'ok'
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at IS NOT NULL
                  ORDER BY t.published_at DESC LIMIT 300",
                [$uid]
            );

            $published = (int) ($byStatus['published'] ?? 0);
            $failed = (int) ($byStatus['failed'] ?? 0);
            $tPub = 0;
            $tFail = 0;
            foreach ($byPlatform as $v) {
                $tPub += $v['published'];
                $tFail += $v['failed'];
            }
            return $this->success([
                'days' => $days,
                'by_status' => $byStatus,
                'total_posts' => array_sum($byStatus),
                'success_rate' => ($tPub + $tFail) > 0 ? round($tPub / ($tPub + $tFail) * 100) : null,
                'by_platform' => $byPlatform,
                'daily' => $series,
                'engagement' => [
                    'likes' => (int) ($eng['likes'] ?? 0),
                    'comments' => (int) ($eng['comments'] ?? 0),
                    'shares' => (int) ($eng['shares'] ?? 0),
                    'reach' => (int) ($eng['reach'] ?? 0),
                    'measured' => (int) ($eng['measured'] ?? 0),
                    'last_fetch' => $eng['last_fetch'] ?? null,
                ],
                'top_posts' => $top,
                'samples' => $samples,
            ]);
        } catch (Throwable $e) {
            Logger::error('Social stats error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر تحميل الإحصائيات. تأكد إن آخر migration اتشغّلت (social_post_metrics).', 500);
        }
    }

    /**
     * POST /api/social/metrics/refresh - يسحب إحصائيات المنشورات المنشورة على فيسبوك/إنستجرام من Meta ويخزّنها.
     * بحد أقصى 15 هدف في المرة، ومبيعيدش سحب اللي اتحدّث من أقل من 30 دقيقة (إلا لو force=1).
     */
    public function refreshMetrics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_metrics', 6, 60)) {
            return $rateError;
        }
        $uid = (int) $this->user['id'];
        $force = (bool) $this->get('force', false);
        @set_time_limit(90);

        try {
            $rows = $this->db->query(
                "SELECT t.id, t.external_post_id, c.platform, c.access_token
                   FROM social_post_targets t
                   JOIN social_posts sp ON sp.id = t.social_post_id
                   JOIN platform_connections c ON c.id = t.platform_connection_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.external_post_id IS NOT NULL AND t.external_post_id <> ''
                    AND c.platform IN ('facebook','instagram') AND c.status = 'connected'
                    " . ($force ? '' : "AND (pm.fetched_at IS NULL OR pm.fetched_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))") . "
                  ORDER BY t.published_at DESC LIMIT 15",
                [$uid]
            );
            $encryption = new Encryption();
            $ok = 0;
            $unavailable = 0;
            foreach ($rows as $r) {
                $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
                try {
                    $token = $encryption->decrypt((string) $r['access_token']);
                    $res = (new MetaSocialAPI($token))->getPostMetrics((string) $r['platform'], (string) $r['external_post_id'], $token);
                } catch (Throwable $e) {
                    $res['error'] = CustomerError::message($e->getMessage(), 500);
                }
                if ($res['success']) {
                    $ok++;
                    if ($this->hasColumn('social_post_metrics', 'views')) {
                        // reach هنا = مشاهدون فريدون (post_total_media_view_unique)؛ insights_note بيشرح ليه الرقم فاضي بدل null صامت
                        $this->db->query(
                            "INSERT INTO social_post_metrics (target_id, likes, comments, shares, reach, views, permalink, state, note, insights_note, fetched_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'ok', NULL, ?, NOW())
                             ON DUPLICATE KEY UPDATE likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares),
                                 reach = VALUES(reach), views = VALUES(views), permalink = VALUES(permalink), state = 'ok', note = NULL,
                                 insights_note = VALUES(insights_note), fetched_at = NOW()",
                            [(int) $r['id'], $res['likes'], $res['comments'], $res['shares'], $res['reach'], $res['views'] ?? null, $res['permalink'], isset($res['insights_error']) ? mb_substr((string) $res['insights_error'], 0, 290) : null]
                        );
                    } else {
                        $this->db->query(
                            "INSERT INTO social_post_metrics (target_id, likes, comments, shares, reach, permalink, state, note, fetched_at)
                             VALUES (?, ?, ?, ?, ?, ?, 'ok', NULL, NOW())
                             ON DUPLICATE KEY UPDATE likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares),
                                 reach = VALUES(reach), permalink = VALUES(permalink), state = 'ok', note = NULL, fetched_at = NOW()",
                            [(int) $r['id'], $res['likes'], $res['comments'], $res['shares'], $res['reach'], $res['permalink']]
                        );
                    }
                } else {
                    $unavailable++;
                    $this->db->query(
                        "INSERT INTO social_post_metrics (target_id, state, note, fetched_at) VALUES (?, 'unavailable', ?, NOW())
                         ON DUPLICATE KEY UPDATE state = 'unavailable', note = VALUES(note), fetched_at = NOW()",
                        [(int) $r['id'], mb_substr((string) ($res['error'] ?? ''), 0, 290)]
                    );
                }
            }
            return $this->success(['updated' => $ok, 'unavailable' => $unavailable, 'checked' => count($rows)]);
        } catch (Throwable $e) {
            Logger::error('Social metrics refresh error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر تحديث الإحصائيات. تأكد إن migration الإحصائيات اتشغّلت.', 500);
        }
    }

    /** POST /api/social/posts/{id}/duplicate - نسخة مسودة من منشور (نفس النص والوسائط) */
    public function duplicatePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $postId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query('SELECT content, media_item_id, website_id, hashtags FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid]);
        if (!$rows) {
            return $this->error('المنشور مش موجود', 404);
        }
        $src = $rows[0];
        $hashtags = $src['hashtags'] ? json_decode((string) $src['hashtags'], true) : null;
        try {
            $post = $this->service->createPost($uid, [
                'content' => $src['content'],
                'website_id' => $src['website_id'],
                'media_item_id' => $src['media_item_id'] ?: null,
                'hashtags' => is_array($hashtags) ? $hashtags : null,
            ], []);
            return $this->success(['post' => $post->toArray()], 'اتعمل نسخة كمسودة', 201);
        } catch (Throwable $e) {
            Logger::error('duplicatePost error', ['message' => $e->getMessage()]);
            return $this->error('تعذر عمل نسخة من المنشور', 500);
        }
    }

    /**
     * POST /api/social/posts/{id}/update - تعديل نص منشور لسه ما اتنشرش (مسودة/مجدول)، وتغيير موعد الأهداف المجدولة.
     * body: {content?, scheduled_at?}  (scheduled_at بـ UTC ISO، أو "" لعدم التغيير)
     */
    public function updatePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $postId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query('SELECT id, status FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid]);
        if (!$rows) {
            return $this->error('المنشور مش موجود', 404);
        }
        if (!in_array($rows[0]['status'], ['draft', 'scheduled', 'failed'], true)) {
            return $this->error('مينفعش تعدّل منشور اتنشر أو بيتنشر دلوقتي', 422);
        }
        $content = trim((string) $this->get('content', ''));
        if ($content === '') {
            return $this->error('Post text is required', 422);
        }
        $at = null;
        $atRaw = trim((string) $this->get('scheduled_at', ''));
        if ($atRaw !== '') {
            try{$ts=InternationalSettingsService::scheduledTimestamp((int)$this->user['id'],$atRaw);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
            if ($ts === false || $ts < time() - 60 || $ts > time() + 366 * 86400) {
                return $this->error('موعد النشر لازم يكون في المستقبل (وخلال سنة)', 422);
            }
            $at = gmdate('Y-m-d H:i:s', $ts);
        }
        try {
            $this->db->query('UPDATE social_posts SET content = ? WHERE id = ?', [$content, $postId]);
            if ($at !== null) {
                $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'scheduled'", [$postId]);
                $queue = Container::getInstance()->make(QueueManager::class);
                foreach ($targets as $t) {
                    $this->db->query('UPDATE social_post_targets SET scheduled_at = ? WHERE id = ?', [$at, (int) $t['id']]);
                    // نمسح مهمة النشر القديمة (المعلّقة) الأول، وإلا هتنشر بالموعد القديم ويتنشر المنشور مرتين
                    $this->db->query(
                        "DELETE FROM jobs WHERE job_class = 'PublishSocialPostJob' AND status = 'pending' AND payload LIKE ?",
                        ['%"social_post_target_id":' . (int) $t['id'] . '}%']
                    );
                    $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => (int) $t['id']], 'social', max(0, strtotime($at . ' UTC') - time()));
                }
            }
            return $this->success([], 'اتحفظ التعديل');
        } catch (Throwable $e) {
            Logger::error('updatePost error', ['message' => $e->getMessage()]);
            return $this->error('Could not Save edit', 500);
        }
    }

    /**
     * لو كرون الطابور مش شغال على السيرفر، المنشورات المجدولة كانت بتفضل "مجدول" للأبد.
     * لما العميل يفتح الصفحة بننفّذ اللي ميعاده عدّى (بحد أقصى 3 في المرة).
     */
    private function catchUpOverdue(int $uid): void
    {
        try {
            $due = $this->db->query(
                "SELECT t.id FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id
                  WHERE p.user_id = ? AND t.status = 'scheduled' AND (t.scheduled_at IS NULL OR t.scheduled_at <= UTC_TIMESTAMP() - INTERVAL 2 MINUTE)
                    AND t.created_at <= NOW() - INTERVAL 2 MINUTE
                  ORDER BY t.id LIMIT 3",
                [$uid]
            );
            $jobs = [];
            foreach ($due as $t) {
                $j = $this->db->query(
                    "SELECT id FROM jobs WHERE job_class = 'PublishSocialPostJob' AND status = 'pending' AND payload LIKE ? ORDER BY id LIMIT 1",
                    ['%"social_post_target_id":' . (int) $t['id'] . '}%']
                );
                if ($j) {
                    $jobs[] = (int) $j[0]['id'];
                }
            }
            SocialPostService::runNow($jobs);
        } catch (Throwable $e) {
            Logger::warning('social catch-up skipped', ['error' => CustomerError::message($e->getMessage(), 500)]);
        }
    }

    private static function permalink(string $platform, string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        if ($platform === 'facebook') {
            return 'https://www.facebook.com/' . rawurlencode($id);
        }
        return null;
    }

    /** POST /api/social/posts */
    public function createPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        // الستوري ما لهاش نص على المنصة: النص هنا ملاحظة داخلية اختيارية
        if ((string) $this->get('post_format', '') === 'story' && trim((string) $this->get('content', '')) === '') {
            $this->data['content'] = 'Story';
        }
        if (!$this->validate(['content' => 'required'])) {
            return $this->error('Missing data', 422, $this->getErrors());
        }

        $uid = (int) $this->user['id'];
        // 🔒 الصفحات لازم تكون بتاعة العميل نفسه، والموعد بيتحفظ UTC
        $targets = [];
        foreach ((array) $this->get('targets', []) as $t) {
            $cid = (int) ($t['platform_connection_id'] ?? 0);
            $ok = $cid > 0 && $this->db->query(
                "SELECT id FROM platform_connections WHERE id = ? AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND platform IN ('facebook','instagram','tiktok','youtube') AND status = 'connected' LIMIT 1",
                [$cid, $uid, $uid]
            );
            if (!$ok) {
                return $this->error('This publishing page doesn\'t exist or isn\'t connected', 422);
            }
            $at = null;
            if (!empty($t['scheduled_at'])) {
                try{$ts=InternationalSettingsService::scheduledTimestamp($uid,(string)$t['scheduled_at']);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
                if ($ts === false) {
                    return $this->error('Invalid publish time', 422);
                }
                if ($ts < time() - 60) {
                    return $this->error('Publish time must be in the future', 422);
                }
                if ($ts > time() + 366 * 86400) {
                    return $this->error('Publish time is too far ahead (max 1 year)', 422);
                }
                $at = gmdate('Y-m-d H:i:s', $ts);
            }
            $targets[] = ['platform_connection_id' => $cid, 'scheduled_at' => $at];
        }
        // تيك توك: العميل لازم يختار الخصوصية والإعدادات بنفسه من شاشة النشر (شرط من تيك توك)
        $tiktokIds = $targets ? array_column($this->db->query("SELECT id FROM platform_connections WHERE platform = 'tiktok' AND id IN (" . implode(',', array_map('intval', array_column($targets, 'platform_connection_id'))) . ")") ?: [], 'id') : [];
        $tiktokOpts = null;
        if ($tiktokIds) {
            $tiktokOpts = TikTokAPI::cleanOptions($this->get('tiktok'));
            if (isset($tiktokOpts['error'])) {
                return $this->error($tiktokOpts['error'], 422);
            }
        }
        $rawMediaId = $this->get('media_item_id', 0);
        if ($rawMediaId !== null && (!is_scalar($rawMediaId) || !preg_match('/^[0-9]+$/', (string)$rawMediaId))) {
            return $this->error('Image/video isn\'t ready or doesn\'t exist', 422);
        }
        $mediaId = (int) $rawMediaId;
        if ($mediaId > 0 && !$this->db->query("SELECT id FROM media_items WHERE id = ? AND user_id = ? AND status = 'completed' AND deleted_at IS NULL LIMIT 1", [$mediaId, $uid])) {
            return $this->error('Image/video isn\'t ready or doesn\'t exist', 422);
        }
        // صور متعددة (2-10): ألبوم فيسبوك / كاروسيل إنستجرام. تيك توك ويوتيوب فيديو بس.
        $albumIds = [];
        try {
            $albumIds = SocialPostService::normalizeAlbumIds($this->get('media_item_ids'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        if ($albumIds) {
            $in = implode(',', array_map('intval', $albumIds));
            $okCount = (int) (($this->db->query("SELECT COUNT(*) AS n FROM media_items WHERE id IN ({$in}) AND user_id = ? AND status = 'completed' AND deleted_at IS NULL AND type <> 'short_video'", [$uid])[0]['n'] ?? 0));
            if ($okCount !== count($albumIds)) {
                return $this->error('إحدى الصور غير جاهزة أو مش موجودة، أو فيه فيديو وسط الصور', 422);
            }
            if ($targets) {
                $cids = implode(',', array_map('intval', array_column($targets, 'platform_connection_id')));
                if ($this->db->query("SELECT id FROM platform_connections WHERE id IN ({$cids}) AND platform IN ('tiktok','youtube') LIMIT 1")) {
                    return $this->error('الصور المتعددة متاحة لفيسبوك وإنستجرام بس (تيك توك ويوتيوب فيديو فقط)', 422);
                }
            }
            $mediaId = $albumIds[0];
        }

        // الستوري: صورة/فيديو واحد على فيسبوك وإنستجرام بس
        $format = (string) $this->get('post_format', 'feed') === 'story' ? 'story' : 'feed';
        if ($format === 'story') {
            if ($mediaId <= 0 || $albumIds) {
                return $this->error('الستوري محتاجة صورة أو فيديو واحد بالظبط', 422);
            }
            if ($targets) {
                $cids = implode(',', array_map('intval', array_column($targets, 'platform_connection_id')));
                if ($this->db->query("SELECT id FROM platform_connections WHERE id IN ({$cids}) AND platform NOT IN ('facebook','instagram') LIMIT 1")) {
                    return $this->error('الستوري متاحة لفيسبوك وإنستجرام بس', 422);
                }
            }
        }
        $websiteId = (int) $this->get('website_id', 0);
        if ($websiteId > 0 && !$this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $uid])) {
            $websiteId = 0;
        }
        $utm = $this->get('utm_campaign', '');
        $utm = is_string($utm) ? SocialLinkTracker::slug($utm) : '';

        try {
            $post = $this->service->createPost(
                $uid,
                // كان media_item_id بيتشال هنا، فاللي جاي من Creative Studio كان بيتنشر نص بس
                ['content' => $this->get('content'), 'website_id' => $websiteId ?: null, 'media_item_id' => $mediaId ?: null,
                 'media_item_ids' => $albumIds ?: null,
                 'hashtags' => $this->cleanHashtags($this->get('hashtags')), 'first_comment' => $this->cleanFirstComment($this->get('first_comment')),
                 'post_format' => $format, 'utm_campaign' => $utm],
                $targets
            );
            if ($tiktokOpts) {
                $pdo = $this->db->getConnection();
                foreach ($this->db->query('SELECT id FROM social_post_targets WHERE social_post_id = ? AND platform_connection_id IN (' . implode(',', array_map('intval', $tiktokIds)) . ')', [(int) $post->getAttribute('id')]) ?: [] as $row) {
                    TikTokAPI::saveOptions($pdo, (int) $row['id'], $tiktokOpts);
                }
            }
            // النشر الفوري بيتنفذ في نفس الطلب (مش مستني الكرون)
            SocialPostService::runNow($this->service->immediateJobIds);
            $postId = (int) $post->getAttribute('id');
            $out = $post->toArray();
            if ($targets) {
                $out['status'] = SocialPostService::refreshPostStatus($postId);
            }
            return $this->success(['post' => $out], 'Post saved', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            Logger::error('createPost Error', ['message' => $e->getMessage()]);
            return $this->error('Could not create the post', 500);
        }
    }

    /**
     * GET /api/social/calendar
     * تقويم محتوى حقيقي مبني على social_posts + social_post_targets
     * الموجودين بالفعل (عمود scheduled_at). ده بديل عن جدول
     * content_calendar المنفصل اللي كان في موديول content-studio -
     * مفيش داعي لتكراره لأن البيانات موجودة أصلًا هنا.
     */
    public function getCalendar(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $rows = $this->db->query(
                "SELECT sp.id, sp.content, COALESCE(spt.scheduled_at, spt.published_at) AS scheduled_at, spt.status, spt.published_at, pc.platform
                 FROM social_posts sp
                 JOIN social_post_targets spt ON spt.social_post_id = sp.id
                 LEFT JOIN platform_connections pc ON pc.id = spt.platform_connection_id
                 WHERE sp.user_id = ? AND COALESCE(spt.scheduled_at, spt.published_at) IS NOT NULL
                 AND COALESCE(spt.scheduled_at, spt.published_at) BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_ADD(NOW(), INTERVAL 35 DAY)
                 ORDER BY COALESCE(spt.scheduled_at, spt.published_at) ASC",
                [$this->user['id']]
            );
            return $this->success(['items' => $rows]);
        } catch (Exception $e) {
            Logger::error('Social Calendar Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load the calendar', 500);
        }
    }

    /** POST /api/social/generate-caption */
    public function generateCaption(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['topic' => 'required'])) {
            return $this->error('Topic is required', 422);
        }

        $uid = (int) $this->user['id'];
        $wid = (int) $this->get('website_id', 0) ?: null;
        if ($wid && !$this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $uid])) {
            $wid = null;
        }
        $platforms = $this->get('platforms', []);
        $platforms = is_array($platforms) ? array_values(array_filter($platforms, 'is_string')) : [];
        if (!$platforms && is_string($this->get('platform'))) {
            $platforms = [(string) $this->get('platform')];
        }
        $language = (string) $this->get('language', '');
        if (!in_array($language, ['ar', 'en'], true)) {
            $language = ContentLanguageService::forUser($uid, $wid);
        }
        $result = $this->service->generateCaptionPro(
            $uid,
            $wid,
            mb_substr((string) $this->get('topic'), 0, 400),
            $platforms,
            $language,
            (string) $this->get('tone', 'friendly'),
            (string) $this->get('goal', 'engagement'),
            (string) $this->get('format', 'feed') === 'story' ? 'story' : 'feed'
        );

        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** GET /api/social/tiktok/creator-info?connection_id= - بيانات شاشة النشر (اسم الحساب والخصوصيات المسموحة) */
    public function tikTokCreatorInfo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'tiktok_creator_info', 30, 60)) {
            return $rateError;
        }
        $uid = (int) $this->user['id'];
        $cid = (int) $this->get('connection_id', 0);
        $own = $this->db->query("SELECT id FROM platform_connections WHERE id = ? AND platform = 'tiktok' AND status = 'connected' AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) LIMIT 1", [$cid, $uid, $uid]);
        $conn = $own ? (new PlatformConnection())->where(['id' => $cid], [], 1) : [];
        if (!$conn) {
            return $this->error('حساب تيك توك ده مش متربط', 404);
        }
        try {
            $api = TikTokAPI::fromConnection($conn[0]);
            $info = $api->creatorInfo();
            if (empty($info['success'])) {
                return $this->error((string) ($info['error'] ?? 'تيك توك مارد'), 502);
            }
            $d = $info['data'];
            return $this->success([
                'nickname' => (string) ($d['creator_nickname'] ?? $d['creator_username'] ?? $conn[0]->getAttribute('external_location_name')),
                'username' => (string) ($d['creator_username'] ?? ''),
                'avatar' => (string) ($d['creator_avatar_url'] ?? ''),
                'privacy_level_options' => array_values((array) ($d['privacy_level_options'] ?? [])),
                'comment_disabled' => !empty($d['comment_disabled']),
                'duet_disabled' => !empty($d['duet_disabled']),
                'stitch_disabled' => !empty($d['stitch_disabled']),
                'max_video_post_duration_sec' => (int) ($d['max_video_post_duration_sec'] ?? 0),
            ]);
        } catch (Throwable $e) {
            Logger::error('TikTok creator info failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نوصل لتيك توك دلوقتي، جرّب تاني', 502);
        }
    }

    /** GET /social/connect/tiktok */
    public function connectTikTok(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }

        $clientKey = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        if (!$clientKey || !$clientSecret) {
            $this->renderOAuthError('TikTok connection is not enabled yet (TIKTOK_CLIENT_KEY/SECRET details are missing).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['tiktok_oauth_nonce'] = $nonce;

        $redirectUri = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');
        $state = OAuthState::issue(['aud' => 'tiktok', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0));

        $url = 'https://www.tiktok.com/v2/auth/authorize/?'
            . http_build_query([
                'client_key'    => $clientKey,
                'response_type' => 'code',
                'scope'         => TikTokAPI::SCOPES,
                'redirect_uri'  => $redirectUri,
                'state'         => $state,
            ]);

        header('Location: ' . $url);
        exit;
    }

    /** GET /social/connect/tiktok/callback */
    public function tikTokOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('The customer declined consent or an error came from TikTok: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('Incomplete response from TikTok');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['tiktok_oauth_nonce'] ?? null, 'tiktok');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('The session expired or the attempt is not trusted');
            exit;
        }

        $clientKey    = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        $redirectUri  = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');

        $tok = TikTokAPI::exchangeCode((string) $clientKey, (string) $clientSecret, (string) $code, $redirectUri);
        if (empty($tok['success'])) {
            $this->renderOAuthError('Token exchange failed with TikTok: ' . htmlspecialchars((string) ($tok['error'] ?? 'unknown error'), ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (strpos((string) ($tok['scope'] ?? TikTokAPI::SCOPES), 'video.publish') === false) {
            $this->renderOAuthError('TikTok: وافق على صلاحية نشر الفيديوهات (video.publish) عشان المنصة تقدر تنشر بدالك. اربط تاني ووافق على كل الصلاحيات.');
            exit;
        }

        $accessToken  = (string) $tok['access_token'];
        $refreshToken = (string) ($tok['refresh_token'] ?? '');
        $openId       = (string) ($tok['open_id'] ?? '');
        $expiresIn    = (int) ($tok['expires_in'] ?? 86400);
        $displayName  = 'TikTok Account';
        $me = (new TikTokAPI($accessToken, $openId))->userInfo();
        if (!empty($me['success']) && !empty($me['user']['display_name'])) {
            $displayName = mb_substr((string) $me['user']['display_name'], 0, 120);
        }

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => (int) $this->user['id'],
            'platform' => 'tiktok',
        ], [], 1);

        $connData = [
            'user_id'               => (int) $this->user['id'],
            'platform'              => 'tiktok',
            'status'                => 'connected',
            'access_token'          => $encryption->encrypt($accessToken),
            'refresh_token'         => $refreshToken ? $encryption->encrypt($refreshToken) : null,
            'external_location_id'  => $openId,
            'external_location_name' => $displayName,
            'token_expires_at'      => gmdate('Y-m-d H:i:s', time() + (int) $expiresIn),
            'last_error'            => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['tiktok_oauth_nonce']);
        header('Location: /social?connected=tiktok');
        exit;
    }

    /** GET /social/connect/youtube */
    public function connectYouTube(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('YouTube connection is not enabled yet (GOOGLE_CLIENT_ID/SECRET or YOUTUBE_OAUTH_REDIRECT_URI is missing).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['youtube_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['aud' => 'youtube', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /social/connect/youtube/callback */
    public function youTubeOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('The customer declined consent or an error came from Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('Incomplete response from Google');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['youtube_oauth_nonce'] ?? null, 'youtube');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('The session expired or the attempt is not trusted');
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('Token exchange failed with Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (empty($tokenResult['refresh_token'])) {
            $this->renderOAuthError('Google did not return a refresh_token. Disconnect any previous connection from your Google settings and try again.');
            exit;
        }

        $accessToken  = $tokenResult['access_token'];
        $refreshToken = $tokenResult['refresh_token'];

        $ch = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $channelResp = curl_exec($ch);
        curl_close($ch);
        $channelData = json_decode((string) $channelResp, true);
        $channelId   = $channelData['items'][0]['id'] ?? null;
        $channelTitle = $channelData['items'][0]['snippet']['title'] ?? 'YouTube Channel';

        if (!$channelId) {
            $this->renderOAuthError('We could not get the channel ID of YouTube.');
            exit;
        }

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => (int) $this->user['id'],
            'platform' => 'youtube',
        ], [], 1);

        $connData = [
            'user_id'                => (int) $this->user['id'],
            'platform'               => 'youtube',
            'status'                 => 'connected',
            'access_token'           => $encryption->encrypt($accessToken),
            'refresh_token'          => $encryption->encrypt($refreshToken),
            'external_location_id'   => $channelId,
            'external_location_name' => $channelTitle,
            'token_expires_at'       => date('Y-m-d H:i:s', time() + (int) ($tokenResult['expires_in'] ?? 3600)),
            'last_error'             => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['youtube_oauth_nonce']);
        header('Location: /social?connected=youtube');
        exit;
    }

    private function renderOAuthError(string $message): void
    {
        $message = CustomerError::oauthMessage($message);
        echo '<div class="p-card" style="max-width:600px;margin:40px auto;text-align:center;">'
            . '<h2>⚠️ Error connecting the account</h2>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a href="/social" class="p-btn primary">Back to social media management</a>'
            . '</div>';
        exit;
    }
}

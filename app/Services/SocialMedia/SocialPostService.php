<?php

/**
 * Tourfecto - Social Post Service
 * منطق عمل السوشيال ميديا: إنشاء منشور، توليد نص/هاشتاجات بالذكاء
 * الاصطناعي (يعيد استخدام GeminiClient الموجود فعلاً - نفس محرك مقالات
 * SEO - بدل عميل AI منفصل كان في الموديول الأصلي)، وجدولة النشر لكل
 * منصة عبر جدول jobs الموحّد.
 * @version 1.0.0
 */
class SocialPostService
{
    /** @var GeminiClient */
    private $ai;

    /** @var int[] مهام النشر الفوري اللي اتعملت في آخر createPost - الكنترولر بينفذها على طول */
    public array $immediateJobIds = [];

    /**
     * ينفّذ مهام النشر الفوري في نفس الطلب بدل ما تستنى الكرون (لو الكرون مش شغال على السيرفر
     * المنشور كان بيفضل "مجدول" للأبد). processJob آمنة لو الكرون سبقنا عليها.
     */
    public static function runNow(array $jobIds): void
    {
        if (!$jobIds) {
            return;
        }
        @set_time_limit(120);
        $queue = Container::getInstance()->make(QueueManager::class);
        foreach ($jobIds as $id) {
            try {
                $queue->processJob((int) $id);
            } catch (Throwable $e) {
                Logger::warning('Social publish-now failed - cron will retry', ['job_id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * حالة المنشور نفسه بتتحسب من أهدافه. قبل كده كانت بتتكتب "scheduled" وقت الإنشاء ومحدش بيحدّثها،
     * فالمنشور اللي اتنشر فعلًا كان بيفضل ظاهر "مجدول".
     */
    public static function refreshPostStatus(int $postId): string
    {
        $db = Database::getInstance();
        $rows = $db->query('SELECT status FROM social_post_targets WHERE social_post_id = ?', [$postId]);
        if (!$rows) {
            return 'draft';
        }
        $st = array_count_values(array_map(fn ($r) => (string) $r['status'], $rows));
        $total = count($rows);
        $pub = $st['published'] ?? 0;
        $failed = $st['failed'] ?? 0;
        if (($st['uncertain'] ?? 0) > 0) { $status='uncertain'; } elseif (($st['publishing'] ?? 0) > 0) {
            $status = 'publishing';
        } elseif ($pub > 0 && $pub + $failed === $total) {
            $status = $failed ? 'partial' : 'published';
        } elseif ($failed === $total) {
            $status = 'failed';
        } elseif ($pub > 0) {
            $status = 'publishing';
        } else {
            $status = 'scheduled';
        }
        $db->query('UPDATE social_posts SET status = ? WHERE id = ?', [$status, $postId]);
        return $status;
    }

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    /**
     * توليد نص منشور + هاشتاجات مقترحة بالذكاء الاصطناعي.
     * @return array ['success'=>bool,'content'=>string,'hashtags'=>array,'error'=>?string]
     */
    public function generateCaption(string $topic, string $platform, string $language = 'en'): array
    {
        $languageName = $language === 'ar' ? 'العربية' : 'English';
        $prompt = <<<PROMPT
اكتب منشور سوشيال ميديا قصير وجذاب لمنصة {$platform} عن: "{$topic}".
اللغة: {$languageName}. الأسلوب: تسويقي، طبيعي، بدون مبالغة.
رجّع الرد بصيغة JSON فقط بالشكل: {"content": "نص المنشور", "hashtags": ["tag1","tag2","tag3"]}
PROMPT;

        $response = AiText::generate($prompt, ['task'=>'social_content', 'output_language'=>$language, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json'], $this->ai);

        if (!($response['success'] ?? false)) {
            return ['success' => false, 'error' => $response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'];
        }

        $raw = trim((string) ($response['data'] ?? ''));
        // fallback احتياطي لو رجّع الرد ملفوف بـ ```json رغم responseMimeType
        $raw = preg_replace('/^```(json)?|```$/m', '', $raw);
        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed) || empty($parsed['content'])) {
            return ['success' => false, 'error' => 'تعذّر تحليل رد الذكاء الاصطناعي'];
        }

        return [
            'success' => true,
            'content' => (string) $parsed['content'],
            'hashtags' => $parsed['hashtags'] ?? [],
        ];
    }

    /**
     * إنشاء منشور + أهداف النشر لكل منصة مطلوبة (مباشر أو مجدول).
     * @param int   $userId
     * @param array $data ['content','website_id','media_item_id','hashtags']
     * @param array $targets كل عنصر: ['platform_connection_id'=>int,'scheduled_at'=>?string]
     */
    public function createPost(int $userId, array $data, array $targets): SocialPost
    {
        $albumIds = self::normalizeAlbumIds($data['media_item_ids'] ?? null);
        if ($albumIds) {
            $data['media_item_id'] = $albumIds[0]; // أول صورة = الغلاف (توافق مع باقي النظام)
        }
        $mediaId = (int)($data['media_item_id'] ?? 0);
        $lockedExtra = [];
        $locked = false;
        if ($mediaId > 0) {
            $locked = GenerationLifecycle::lock($mediaId);
            if (!$locked) throw new RuntimeException('Media currently busy');
        }
        try {
            if ($mediaId > 0) {
                $media = PrivateMedia::owned($userId, $mediaId);
                if (!$media || ($media['status'] ?? '') !== 'completed') throw new RuntimeException('Publish media unavailable');
            }
            if ($albumIds) {
                if (!self::albumTableExists()) throw new RuntimeException('شغّل migration الصور المتعددة (2026_10_18_000002_social_post_media.sql) الأول');
                foreach ($albumIds as $aid) {
                    $m = PrivateMedia::owned($userId, $aid);
                    if (!$m || ($m['status'] ?? '') !== 'completed') throw new InvalidArgumentException('إحدى الصور غير جاهزة أو مش موجودة');
                    if (($m['type'] ?? '') === 'short_video') throw new InvalidArgumentException('الصور المتعددة للصور بس - ما ينفعش تخلط فيديو معاها');
                    if ($aid !== $mediaId) {
                        if (!GenerationLifecycle::lock($aid)) throw new RuntimeException('Media currently busy');
                        $lockedExtra[] = $aid;
                    }
                }
            }
        $this->immediateJobIds = [];
        $post = new SocialPost([
            'user_id' => $userId,
            'website_id' => $data['website_id'] ?? null,
            'content' => $data['content'],
            'media_item_id' => $data['media_item_id'] ?? null,
            'hashtags' => isset($data['hashtags']) ? json_encode($data['hashtags'], JSON_UNESCAPED_UNICODE) : null,
            'status' => empty($targets) ? 'draft' : 'scheduled',
        ]);
        // أول تعليق: بيتكتب بس لو اتبعت (العمود من migration الموثوقية - ما نكتبوش لو مش مستخدم عشان الإنشاء ما يقعش قبلها)
        if (!empty($data['first_comment'])) {
            $post->setAttribute('first_comment', (string) $data['first_comment']);
        }
        $post->save();
        $postId = (int) $post->getAttribute('id');
        foreach ($albumIds as $pos => $aid) {
            Database::getInstance()->query('INSERT INTO social_post_media (social_post_id, media_item_id, position) VALUES (?, ?, ?)', [$postId, $aid, $pos]);
        }

        $queue = Container::getInstance()->make(QueueManager::class);

        foreach ($targets as $target) {
            $scheduledAt = $target['scheduled_at'] ?? null;

            $postTarget = new SocialPostTarget([
                'social_post_id' => $postId,
                'platform_connection_id' => $target['platform_connection_id'],
                'scheduled_at' => $scheduledAt,
                'status' => 'scheduled',
            ]);
            $postTarget->save();

            // ننشر فورًا لو مفيش موعد مجدول، أو نأجّل التنفيذ الفعلي حتى الموعد
            $delaySeconds = 0;
            if ($scheduledAt) {
                $delaySeconds = max(0, strtotime($scheduledAt . ' UTC') - time()); // المواعيد متخزّنة UTC
            }

            $jobId = $queue->push(PublishSocialPostJob::class, [
                'social_post_target_id' => (int) $postTarget->getAttribute('id'),
            ], 'social', $delaySeconds);
            if ($delaySeconds === 0 && $jobId) {
                $this->immediateJobIds[] = (int) $jobId;
            }
        }

        ActivityLog::record('social', 'post.created', [
            'user_id' => $userId,
            'subject_type' => 'social_posts',
            'subject_id' => $postId,
            'meta' => ['targets' => count($targets)],
        ]);

        return $post;
        } finally {
            if ($locked) GenerationLifecycle::unlock($mediaId);
            foreach ($lockedExtra as $aid) GenerationLifecycle::unlock($aid);
        }
    }

    /** ids الصور المتعددة: أرقام فريدة موجبة بالترتيب. أقل من 2 = مش ألبوم. أكتر من 10 = خطأ (حد فيسبوك/إنستجرام) */
    public static function normalizeAlbumIds($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            if (is_scalar($v) && preg_match('/^[0-9]+$/', (string) $v) && (int) $v > 0) {
                $ids[(int) $v] = (int) $v;
            }
        }
        $ids = array_values($ids);
        if (count($ids) > 10) {
            throw new InvalidArgumentException('الحد الأقصى 10 صور في المنشور الواحد');
        }
        return count($ids) >= 2 ? $ids : [];
    }

    public static function albumTableExists(): bool
    {
        try {
            return (bool) Database::getInstance()->query("SHOW TABLES LIKE 'social_post_media'");
        } catch (Throwable $e) {
            return false;
        }
    }
}

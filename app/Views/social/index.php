<?php
/**
 * Tourfecto - واجهة السوشيال ميديا الاحترافية (/social)
 * ثنائية اللغة بالكامل (عربي/إنجليزي حسب لغة اللوحة) - مفيش نصوص مخلوطة.
 * المتغيرات: $ar (bool), $uid (int), $cssUrl, $jsUrl, $monthlyCss, $monthlyJs, $pickerJs
 * @var bool $ar
 */
$t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $cssUrl ?>">
<link rel="stylesheet" href="<?= $monthlyCss ?>">
<div id="spRoot" class="sp-root" data-lang="<?= $ar ? 'ar' : 'en' ?>">

    <div class="sp-hero">
        <div>
            <h2 class="sp-hero-title"><?= $t('مركز السوشيال ميديا', 'Social media hub') ?></h2>
            <p class="sp-hero-sub"><?= $t('اكتب وصمّم وانشر وجدول، ورد على تعليقات عملائك، وتابع النتائج - من مكان واحد.', 'Write, design, publish and schedule, answer your customers\' comments, and track results — all in one place.') ?></p>
        </div>
        <div class="sp-hero-actions">
            <button class="p-btn primary" type="button" onclick="openNewPost()">＋ <?= $t('منشور جديد', 'New post') ?></button>
            <button class="p-btn outline" type="button" onclick="openNewPost('story')">◎ <?= $t('ستوري جديدة', 'New story') ?></button>
            <a class="p-btn outline" id="spReportBtn" href="/social/report" target="_blank" rel="noopener">📄 <?= $t('تقرير الشهر', 'Monthly report') ?></a>
        </div>
    </div>

    <nav class="p-tabs sp-tabs" role="tablist" aria-label="<?= $t('أقسام السوشيال', 'Social sections') ?>">
        <button class="p-tab active" role="tab" data-tab="overview" type="button">📊 <?= $t('نظرة عامة', 'Overview') ?></button>
        <button class="p-tab" role="tab" data-tab="posts" type="button">🗂 <?= $t('المنشورات والتقويم', 'Posts & calendar') ?></button>
        <button class="p-tab" role="tab" data-tab="inbox" type="button">💬 <?= $t('صندوق التعليقات', 'Comments inbox') ?> <span class="sp-badge" id="spInboxBadge" hidden></span></button>
        <button class="p-tab" role="tab" data-tab="plan" type="button">🗓 <?= $t('الخطة الشهرية', 'Monthly plan') ?></button>
        <button class="p-tab" role="tab" data-tab="accounts" type="button">🔗 <?= $t('الحسابات', 'Accounts') ?></button>
    </nav>

    <!-- ============ نظرة عامة ============ -->
    <section class="sp-panel" data-panel="overview">
        <div class="p-toolbar sp-bar">
            <select id="spDays" class="p-select" aria-label="<?= $t('الفترة', 'Period') ?>">
                <option value="7"><?= $t('آخر 7 أيام', 'Last 7 days') ?></option>
                <option value="30" selected><?= $t('آخر 30 يوم', 'Last 30 days') ?></option>
                <option value="90"><?= $t('آخر 90 يوم', 'Last 90 days') ?></option>
            </select>
            <button class="p-btn outline" type="button" id="spMetricsBtn" onclick="refreshMetrics(true)">↻ <?= $t('تحديث التفاعل من Meta', 'Refresh engagement from Meta') ?></button>
            <span class="p-cell-muted sp-note" id="spMetricsNote"></span>
        </div>

        <div class="p-grid cols-4" id="spStats">
            <div class="p-card stat-tile"><div class="stat-icon blue">📝</div><div class="stat-info"><div class="stat-value" id="stTotal">–</div><div class="stat-label"><?= $t('إجمالي المنشورات', 'Total posts') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value" id="stPub">–</div><div class="stat-label"><?= $t('اتنشرت', 'Published') ?> <span id="stRate" class="p-cell-muted"></span></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">🗓</div><div class="stat-info"><div class="stat-value" id="stSched">–</div><div class="stat-label"><?= $t('مجدولة / مسودات', 'Scheduled / drafts') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">⚠️</div><div class="stat-info"><div class="stat-value" id="stFail">–</div><div class="stat-label"><?= $t('فشلت', 'Failed') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon blue">👁</div><div class="stat-info"><div class="stat-value" id="stReach">–</div><div class="stat-label"><?= $t('الوصول (مشاهدون فريدون)', 'Reach (unique viewers)') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">👍</div><div class="stat-info"><div class="stat-value" id="stLikes">–</div><div class="stat-label"><?= $t('إعجابات', 'Likes') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">💬</div><div class="stat-info"><div class="stat-value" id="stComments">–</div><div class="stat-label"><?= $t('تعليقات', 'Comments') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">🔁</div><div class="stat-info"><div class="stat-value" id="stShares">–</div><div class="stat-label"><?= $t('مشاركات', 'Shares') ?></div></div></div>
        </div>

        <div class="p-card sp-health" id="spHealth" hidden></div>

        <div class="p-grid cols-2 sp-gap">
            <div class="p-card"><div class="p-card-head"><h3>📈 <?= $t('النشر اليومي', 'Daily publishing') ?></h3><span class="p-card-sub" id="spDailySub"></span></div><div class="sp-bars" id="spDaily"></div><div class="sp-axis p-cell-muted" id="spDailyAxis"></div></div>
            <div class="p-card"><div class="p-card-head"><h3>📊 <?= $t('حسب المنصة', 'By platform') ?></h3></div><div id="spPlat" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
        </div>
        <div class="p-grid cols-2 sp-gap">
            <div class="p-card"><div class="p-card-head"><h3>⏰ <?= $t('أفضل مواعيد النشر', 'Best times to post') ?></h3><span class="p-card-sub" id="spBestSub"></span></div><div id="spBest" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
            <div class="p-card"><div class="p-card-head"><h3>🏆 <?= $t('أعلى المنشورات تفاعلًا', 'Top posts by engagement') ?></h3></div><div id="spTop" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
        </div>
    </section>

    <!-- ============ المنشورات والتقويم ============ -->
    <section class="sp-panel" data-panel="posts" hidden>
        <div class="p-card no-pad">
            <div class="p-toolbar sp-bar sp-pad">
                <input type="search" id="spQ" class="p-select" placeholder="🔎 <?= $t('ابحث في النصوص…', 'Search posts…') ?>">
                <select id="spFStatus" class="p-select" aria-label="<?= $t('الحالة', 'Status') ?>">
                    <option value=""><?= $t('كل الحالات', 'All statuses') ?></option>
                    <option value="published"><?= $t('اتنشر', 'Published') ?></option>
                    <option value="scheduled"><?= $t('مجدول', 'Scheduled') ?></option>
                    <option value="draft"><?= $t('مسودة', 'Draft') ?></option>
                    <option value="failed"><?= $t('فشل', 'Failed') ?></option>
                    <option value="partial"><?= $t('اتنشر جزئيًا', 'Partially published') ?></option>
                    <option value="uncertain"><?= $t('محتاج مراجعة', 'Needs review') ?></option>
                </select>
                <select id="spFPlat" class="p-select" aria-label="<?= $t('المنصة', 'Platform') ?>">
                    <option value=""><?= $t('كل المنصات', 'All platforms') ?></option>
                    <option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="youtube">YouTube</option>
                </select>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="postsTable">
                <thead><tr><th><?= $t('المحتوى', 'Content') ?></th><th><?= $t('اتنشر على', 'Published on') ?></th><th><?= $t('التفاعل', 'Engagement') ?></th><th><?= $t('الحالة', 'Status') ?></th><th><?= $t('التاريخ', 'Date') ?></th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="6"><?= $t('جارِ التحميل…', 'Loading…') ?></td></tr></tbody>
            </table></div>
        </div>
        <div class="p-card no-pad sp-gap">
            <div class="p-card-head sp-pad"><h3>📅 <?= $t('تقويم المحتوى', 'Content calendar') ?></h3><span class="p-card-sub"><?= $t('الأسبوع اللي فات + الشهر الجاي', 'Last week + next month') ?></span></div>
            <div class="sp-cal" id="calendarGrid"><span class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></span></div>
        </div>
    </section>

    <!-- ============ صندوق التعليقات ============ -->
    <section class="sp-panel" data-panel="inbox" hidden>
        <div class="p-card">
            <div class="p-toolbar sp-bar">
                <select id="ibStatus" class="p-select" aria-label="<?= $t('الحالة', 'Status') ?>">
                    <option value="open"><?= $t('محتاجة رد', 'Needs reply') ?></option>
                    <option value="replied"><?= $t('اترد عليها', 'Replied') ?></option>
                    <option value="hidden"><?= $t('مخفية', 'Hidden') ?></option>
                    <option value="done"><?= $t('اتقفلت', 'Closed') ?></option>
                    <option value="all"><?= $t('الكل', 'All') ?></option>
                </select>
                <select id="ibIntent" class="p-select" aria-label="<?= $t('النوع', 'Type') ?>">
                    <option value=""><?= $t('كل الأنواع', 'All types') ?></option>
                    <option value="price">💰 <?= $t('سؤال عن السعر', 'Price question') ?></option>
                    <option value="booking">📅 <?= $t('حجز / تفاصيل', 'Booking / details') ?></option>
                    <option value="question">❓ <?= $t('سؤال', 'Question') ?></option>
                    <option value="complaint">⚠️ <?= $t('شكوى', 'Complaint') ?></option>
                    <option value="praise">💚 <?= $t('مدح', 'Praise') ?></option>
                    <option value="spam">🚫 <?= $t('سبام', 'Spam') ?></option>
                    <option value="other"><?= $t('غير ذلك', 'Other') ?></option>
                </select>
                <button class="p-btn outline" type="button" id="ibSync">↻ <?= $t('اسحب التعليقات الجديدة', 'Fetch new comments') ?></button>
                <span class="p-cell-muted sp-note" id="ibCounts"></span>
            </div>
            <div id="ibNotice"></div>
            <div id="ibList" class="ib-list"><span class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></span></div>
        </div>
    </section>

    <!-- ============ الخطة الشهرية ============ -->
    <section class="sp-panel" data-panel="plan" hidden>
        <section id="monthlySocialRoot" class="smp-root p-card" data-user-id="<?= (int) $uid ?>" aria-label="<?= $t('الخطة الشهرية', 'Monthly social media plan') ?>"><p><?= $t('جارِ تحميل الخطط الشهرية…', 'Loading monthly plans…') ?></p></section>
    </section>

    <!-- ============ الحسابات ============ -->
    <section class="sp-panel" data-panel="accounts" hidden>
        <div class="p-card">
            <div class="p-card-head"><h3>🔗 <?= $t('الحسابات المربوطة', 'Connected accounts') ?></h3></div>
            <div id="spAccounts" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div>
            <div class="sp-connect">
                <a class="sp-conn fb" href="/ads/connect/meta?for=social"><b>Facebook + Instagram</b><span><?= $t('صفحات فيسبوك وحسابات إنستجرام بيزنس: منشورات، ريلز، ألبومات، ستوري، تعليقات', 'Facebook Pages & Instagram Business: posts, reels, albums, stories, comments') ?></span></a>
                <a class="sp-conn tt" href="/social/connect/tiktok"><b>TikTok</b><span><?= $t('فيديوهات قصيرة بإعدادات الخصوصية المطلوبة من تيك توك', 'Short videos with TikTok\'s required privacy settings') ?></span></a>
                <a class="sp-conn yt" href="/social/connect/youtube"><b>YouTube Shorts</b><span><?= $t('رفع الفيديوهات القصيرة على قناتك', 'Upload short videos to your channel') ?></span></a>
            </div>
            <details class="sp-help">
                <summary><?= $t('ليه صندوق التعليقات أو أول تعليق مش شغالين؟', 'Why don\'t the comments inbox or first comment work?') ?></summary>
                <p><?= $t('قراءة التعليقات والرد عليها محتاجين صلاحيات إضافية من Meta: pages_read_user_content و pages_manage_engagement (فيسبوك) و instagram_manage_comments (إنستجرام). مدير المنصة يضيفهم في إعداد META_SOCIAL_EXTRA_SCOPES ويطلب موافقة Meta عليهم (App Review)، وبعدها اربط فيسبوك وإنستجرام تاني ووافق على كل الصلاحيات.', 'Reading and replying to comments needs extra Meta permissions: pages_read_user_content and pages_manage_engagement (Facebook) and instagram_manage_comments (Instagram). The platform admin adds them to META_SOCIAL_EXTRA_SCOPES and requests Meta App Review; then reconnect Facebook & Instagram and accept all permissions.') ?></p>
            </details>
        </div>
    </section>

    <!-- ============ محرر المنشور ============ -->
    <div class="p-modal-overlay" id="newPostModal">
        <div class="p-modal wide sp-composer" role="dialog" aria-modal="true" aria-labelledby="spComposerTitle">
            <div class="p-modal-head"><h3 id="spComposerTitle"><?= $t('منشور جديد', 'New post') ?></h3><button class="p-modal-close" type="button" onclick="closeComposer()" aria-label="<?= $t('إغلاق', 'Close') ?>">×</button></div>
            <div class="p-modal-body sp-cmp-grid">
                <div class="sp-cmp-main">
                    <div class="sp-seg" role="radiogroup" aria-label="<?= $t('نوع المحتوى', 'Content type') ?>">
                        <label><input type="radio" name="spFormat" value="feed" checked> 📝 <?= $t('منشور', 'Post') ?></label>
                        <label><input type="radio" name="spFormat" value="story"> ◎ <?= $t('ستوري', 'Story') ?></label>
                    </div>

                    <div class="sp-step"><span class="sp-num">1</span><b><?= $t('هتنشر فين؟', 'Where to publish?') ?></b>
                        <button type="button" class="p-btn ghost xs" onclick="refreshSocialTargets()">↻ <?= $t('تحديث الصفحات', 'Refresh pages') ?></button></div>
                    <div id="targetsList" class="sp-targets p-cell-muted"><?= $t('جارِ تحميل الصفحات المربوطة…', 'Loading connected pages…') ?></div>
                    <div id="ttPanel" class="tt-panel" hidden></div>

                    <div class="sp-step"><span class="sp-num">2</span><b><?= $t('الصورة أو الفيديو', 'Image or video') ?></b></div>
                    <div class="sp-media">
                        <div class="sp-prev" id="spPrev">🖼️</div>
                        <div class="sp-media-actions">
                            <div class="sp-row">
                                <label class="p-btn outline sm sp-file">⬆️ <?= $t('ارفع من جهازك', 'Upload') ?><input type="file" id="spFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" hidden></label>
                                <button type="button" class="p-btn outline sm" onclick="toggleLib()">🎨 <?= $t('من المكتبة', 'From library') ?></button>
                                <button type="button" class="p-btn outline sm" id="spAlbumBtn" onclick="toggleAlbumMode()" title="<?= $t('من 2 لـ 10 صور (ألبوم فيسبوك / كاروسيل إنستجرام)', '2–10 photos (Facebook album / Instagram carousel)') ?>">🖼️ <?= $t('صور متعددة', 'Multiple photos') ?></button>
                                <a class="p-btn outline sm" href="/creative-studio" target="_blank" rel="noopener">✨ <?= $t('صمّم بالذكاء الاصطناعي ↗', 'Create with AI ↗') ?></a>
                                <button type="button" class="p-btn ghost sm" id="spClear" hidden onclick="clearMedia()"><?= $t('إزالة', 'Remove') ?></button>
                            </div>
                            <div id="spAlbumStrip" class="sp-album"></div>
                            <div class="p-cell-muted sp-small" id="spMediaNote"><?= $t('صورة JPG/PNG/WEBP لحد 10 ميجا، أو فيديو MP4 لحد 100 ميجا. مقاس الصورة بيتظبط لإنستجرام تلقائي.', 'JPG/PNG/WEBP up to 10 MB, or MP4 up to 100 MB. Images are auto-fitted for Instagram.') ?></div>
                        </div>
                    </div>
                    <div class="sp-lib" id="spLib" hidden></div>

                    <div class="sp-feed-only">
                        <div class="sp-step"><span class="sp-num">3</span><b><?= $t('النص', 'Caption') ?></b></div>
                        <div class="sp-ai">
                            <div class="sp-row">
                                <input type="text" id="topicInput" class="p-select sp-grow" maxlength="400" placeholder="<?= $t('موضوع المنشور - مثال: رحلة سنوركلينج في الغردقة للعائلات', 'Post topic — e.g. family snorkeling trip in Hurghada') ?>">
                                <select id="aiTone" class="p-select" aria-label="<?= $t('الأسلوب', 'Tone') ?>">
                                    <option value="friendly"><?= $t('ودود', 'Friendly') ?></option><option value="luxury"><?= $t('فخم', 'Luxury') ?></option><option value="adventure"><?= $t('مغامرة', 'Adventure') ?></option><option value="family"><?= $t('عائلي', 'Family') ?></option><option value="professional"><?= $t('رسمي', 'Professional') ?></option><option value="fun"><?= $t('مرح', 'Fun') ?></option>
                                </select>
                                <select id="aiGoal" class="p-select" aria-label="<?= $t('الهدف', 'Goal') ?>">
                                    <option value="engagement"><?= $t('تفاعل', 'Engagement') ?></option><option value="bookings"><?= $t('حجوزات', 'Bookings') ?></option><option value="offer"><?= $t('عرض', 'Offer') ?></option><option value="awareness"><?= $t('انتشار', 'Awareness') ?></option><option value="trust"><?= $t('ثقة', 'Trust') ?></option>
                                </select>
                                <button class="p-btn outline" type="button" id="aiCapBtn" onclick="generateCaption()">✨ <?= $t('اكتب 3 نسخ', 'Write 3 versions') ?></button>
                            </div>
                            <div id="aiVariants" class="sp-variants" hidden></div>
                        </div>
                        <textarea id="contentInput" rows="6" class="p-select sp-text" placeholder="<?= $t('اكتب النص أو اختار نسخة من الذكاء الاصطناعي…', 'Write your caption or pick an AI version…') ?>"></textarea>
                        <div class="sp-counter" id="spCounter"></div>
                        <div class="sp-row sp-gap-s">
                            <label class="sp-field sp-grow"><?= $t('هاشتاجات', 'Hashtags') ?><input type="text" id="hashtagsInput" class="p-select" placeholder="#Hurghada #RedSea"></label>
                        </div>
                        <details class="sp-more">
                            <summary><?= $t('خيارات متقدمة: أول تعليق وتتبّع الروابط', 'Advanced: first comment & link tracking') ?></summary>
                            <label class="sp-field"><?= $t('أول تعليق تلقائي (رابط الحجز أو سؤال للجمهور) - فيسبوك وإنستجرام', 'Automatic first comment (booking link or a question) — Facebook & Instagram') ?><textarea id="firstCommentInput" rows="2" class="p-select" maxlength="2000"></textarea></label>
                            <label class="sp-check"><input type="checkbox" id="utmOn" checked> <?= $t('تتبّع روابط موقعي بـ UTM (تعرف الحجوزات جت من أنهي منشور في Google Analytics)', 'Track my website links with UTM (see which post brought bookings in Google Analytics)') ?></label>
                            <label class="sp-field"><?= $t('اسم الحملة', 'Campaign name') ?><input type="text" id="utmCampaign" class="p-select" maxlength="60"></label>
                        </details>
                    </div>
                    <div class="sp-story-only alert alert-info" hidden><?= $t('الستوري بتتنشر صورة أو فيديو واحد من غير نص (فيسبوك وإنستجرام). اكتب العرض أو الرسالة على التصميم نفسه من "صمّم بالذكاء الاصطناعي"، والأفضل مقاس 9:16.', 'Stories publish a single image or video with no caption (Facebook & Instagram). Put your offer/message on the design itself via "Create with AI"; 9:16 works best.') ?></div>

                    <div class="sp-step"><span class="sp-num" id="spWhenNum">4</span><b><?= $t('امتى؟', 'When?') ?></b></div>
                    <div class="sp-row">
                        <label class="sp-radio"><input type="radio" name="spWhen" value="now" checked> <?= $t('دلوقتي', 'Now') ?></label>
                        <label class="sp-radio"><input type="radio" name="spWhen" value="later"> <?= $t('جدولة', 'Schedule') ?></label>
                        <input type="datetime-local" id="spAt" class="p-select" hidden>
                        <button type="button" class="p-btn ghost sm" id="spBestBtn" hidden onclick="pickBestTime()">⏰ <?= $t('أفضل ميعاد', 'Best time') ?></button>
                    </div>
                    <div class="p-cell-muted sp-small" id="spWhenNote"><?= $t('المواعيد بتوقيت حسابك. لو ما اخترتش صفحة، المنشور هيتحفظ مسودة.', 'Times use your account timezone. With no page selected the post is saved as a draft.') ?></div>
                </div>

                <aside class="sp-cmp-side" aria-label="<?= $t('معاينة', 'Preview') ?>">
                    <div class="sp-prevtabs" id="spPrevTabs"></div>
                    <div class="sp-mock" id="spMock"></div>
                    <ul class="sp-checklist" id="spChecklist"></ul>
                </aside>
            </div>
            <div class="p-modal-foot">
                <button class="p-btn outline" type="button" onclick="closeComposer()"><?= $t('إلغاء', 'Cancel') ?></button>
                <button class="p-btn primary" type="button" id="spSubmit" onclick="createPost()"><?= $t('انشر', 'Publish') ?></button>
            </div>
        </div>
    </div>

    <!-- ============ تعديل منشور ============ -->
    <div class="p-modal-overlay" id="editPostModal">
        <div class="p-modal" role="dialog" aria-modal="true">
            <div class="p-modal-head"><h3><?= $t('تعديل المنشور', 'Edit post') ?></h3><button class="p-modal-close" type="button" onclick="document.getElementById('editPostModal').classList.remove('open')" aria-label="<?= $t('إغلاق', 'Close') ?>">×</button></div>
            <div class="p-modal-body">
                <label class="sp-field"><?= $t('نص المنشور', 'Post text') ?><textarea id="editContent" rows="6" class="p-select"></textarea></label>
                <div id="editWhenBox" hidden>
                    <label class="sp-field"><?= $t('موعد جديد (اختياري)', 'New time (optional)') ?><input type="datetime-local" id="editAt" class="p-select"></label>
                </div>
                <div class="p-cell-muted sp-small" id="editNote"></div>
            </div>
            <div class="p-modal-foot">
                <button class="p-btn outline" type="button" onclick="document.getElementById('editPostModal').classList.remove('open')"><?= $t('إلغاء', 'Cancel') ?></button>
                <button class="p-btn primary" type="button" id="editSubmit" onclick="saveEdit()"><?= $t('حفظ', 'Save') ?></button>
            </div>
        </div>
    </div>
</div>
<script defer src="<?= $pickerJs ?>"></script>
<script defer src="<?= $monthlyJs ?>"></script>
<script defer src="<?= $jsUrl ?>"></script>

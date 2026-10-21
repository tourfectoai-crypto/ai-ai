<?php
// php tests/Automation/schema_updater_mysql_compat_test.php
// MySQL مابيدعمش IF NOT EXISTS في ADD COLUMN/INDEX — SchemaUpdater بيحوّلها بنفسه.
// قبل الإصلاح: ALTER فيه أكتر من ADD كانت أول إضافة بس بتتحوّل والباقي syntax error.
require_once __DIR__ . '/../../app/Services/Admin/SchemaUpdater.php';
function check(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } echo "PASS: {$message}\n"; }

$existing = ['email_campaigns.attributed_revenue' => true, 'email_subscribers.engagement_score' => true];
$col = fn ($t, $c) => isset($existing["$t.$c"]);
$idx = fn ($t, $i) => $i === 'idx_old';

$sql = "ALTER TABLE `email_campaigns`\n    ADD COLUMN IF NOT EXISTS `attributed_bookings_count` INT UNSIGNED NOT NULL DEFAULT 0,\n    ADD COLUMN IF NOT EXISTS `attributed_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00";
$out = SchemaUpdater::rewriteForMysql($sql, $col, $idx);
check($out === "ALTER TABLE `email_campaigns` ADD COLUMN `attributed_bookings_count` INT UNSIGNED NOT NULL DEFAULT 0", 'multi-clause ALTER: existing column dropped, rest kept without IF NOT EXISTS');

check(SchemaUpdater::rewriteForMysql("ALTER TABLE `email_subscribers` ADD COLUMN IF NOT EXISTS `engagement_score` INT", $col, $idx) === null, 'nothing left to add => skipped');

$out = SchemaUpdater::rewriteForMysql("ALTER TABLE t ADD COLUMN IF NOT EXISTS `c` ENUM('a','b,c') DEFAULT 'a' COMMENT 'x, y (z)', ADD INDEX IF NOT EXISTS `idx_new` (`c`), ADD INDEX IF NOT EXISTS `idx_old` (`c`)", $col, $idx);
check($out === "ALTER TABLE `t` ADD COLUMN `c` ENUM('a','b,c') DEFAULT 'a' COMMENT 'x, y (z)', ADD INDEX `idx_new` (`c`)", 'commas inside ENUM/COMMENT are not split; existing index skipped');
check(stripos((string) $out, 'IF NOT EXISTS') === false, 'no IF NOT EXISTS reaches MySQL');

check(SchemaUpdater::rewriteForMysql("CREATE INDEX IF NOT EXISTS `idx_new` ON `email_campaigns` (`recurrence`, `next_run_at`)", $col, $idx) === "CREATE INDEX `idx_new` ON `email_campaigns` (`recurrence`, `next_run_at`)", 'CREATE INDEX IF NOT EXISTS converted');
check(SchemaUpdater::rewriteForMysql("CREATE INDEX IF NOT EXISTS `idx_old` ON `t` (`c`)", $col, $idx) === null, 'existing CREATE INDEX skipped');
check(SchemaUpdater::rewriteForMysql("ALTER TABLE t MODIFY `c` INT", $col, $idx) === "ALTER TABLE t MODIFY `c` INT", 'plain statements untouched');

// كل ملفات الـ migrations: مفيش IF NOT EXISTS بيوصل لـ ADD/INDEX بعد التحويل
$split = new ReflectionMethod('SchemaUpdater', 'split');
$split->setAccessible(true);
$u = (new ReflectionClass('SchemaUpdater'))->newInstanceWithoutConstructor();
$bad = [];
foreach (glob(__DIR__ . '/../../database/migrations/2026_10_*.sql') as $f) {
    foreach ($split->invoke($u, file_get_contents($f)) as $st) {
        $r = SchemaUpdater::rewriteForMysql($st, fn () => false, fn () => false);
        if ($r !== null && preg_match('/\b(ADD\s+(COLUMN\s+)?|INDEX\s+|KEY\s+)IF\s+NOT\s+EXISTS/i', $r)) {
            $bad[] = basename($f);
        }
    }
}
check($bad === [], 'all October migrations are MySQL-compatible after rewrite' . ($bad ? ': ' . implode(', ', array_unique($bad)) : ''));
echo "All schema updater checks passed\n";

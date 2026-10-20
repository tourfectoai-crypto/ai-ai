<?php
// php tests/Automation/email_platform_bridge_test.php
// منطق ربط التسويق بالبريد بالمنصة — من غير قاعدة بيانات (stub DB).
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailPlatformBridge.php';

function check(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } echo "PASS: {$message}\n"; }

/** DB وهمي: بيرد حسب أول نمط يطابق الـ SQL، وبيسجّل كل استعلام */
class StubDb
{
    public array $log = [];
    private array $routes;
    public function __construct(array $routes) { $this->routes = $routes; }
    public function query(string $sql, array $params = []) {
        $this->log[] = [$sql, $params];
        foreach ($this->routes as $pattern => $result) {
            if (stripos($sql, $pattern) !== false) { return is_callable($result) ? $result($params) : $result; }
        }
        return [];
    }
    public function ran(string $needle): bool {
        foreach ($this->log as [$sql]) { if (stripos($sql, $needle) !== false) return true; }
        return false;
    }
}

// ---------- pure helpers ----------
check(EmailPlatformBridge::appendQueryParam('https://a.com/t', 'tf_em', 'abc') === 'https://a.com/t?tf_em=abc', 'append param to bare url');
check(EmailPlatformBridge::appendQueryParam('https://a.com/t?x=1#book', 'tf_em', 'abc') === 'https://a.com/t?x=1&tf_em=abc#book', 'append keeps query and fragment');
check(EmailPlatformBridge::appendQueryParam('https://a.com/t?tf_em=old&x=1', 'tf_em', 'new') === 'https://a.com/t?x=1&tf_em=new', 'existing param is replaced, not duplicated');

check(EmailPlatformBridge::hostAllowed('www.mytours.com', ['mytours.com']), 'www host matches bare domain');
check(EmailPlatformBridge::hostAllowed('book.mytours.com', ['www.mytours.com']), 'subdomain matches');
check(!EmailPlatformBridge::hostAllowed('evilmytours.com', ['mytours.com']), 'suffix without dot does not match');
check(!EmailPlatformBridge::hostAllowed('tripadvisor.com', ['mytours.com']), 'third-party host never gets the token');

check(EmailPlatformBridge::pathInSites('/sites/nile', ['nile']) && EmailPlatformBridge::pathInSites('/sites/nile/tours/x', ['nile']), 'own site paths on platform domain');
check(!EmailPlatformBridge::pathInSites('/sites/nile-other/tours', ['nile']), 'another tenant slug with same prefix rejected');

check(EmailPlatformBridge::isValidToken(str_repeat('a', 32)) && !EmailPlatformBridge::isValidToken('abc') && !EmailPlatformBridge::isValidToken(str_repeat('z', 32)), 'token format validation');

[$from, $to] = EmailPlatformBridge::tripWindow('trip_upcoming', 3, '2026-10-20');
check($from === '2026-10-20' && $to === '2026-10-23', 'upcoming window = today .. today+N');
[$from, $to] = EmailPlatformBridge::tripWindow('trip_completed', 1, '2026-10-20');
check($from === '2026-10-12' && $to === '2026-10-19', 'completed window = ended N..N+7 days ago (cron catch-up)');
[$from, $to] = EmailPlatformBridge::tripWindow('trip_upcoming', 0, '2026-10-20');
check($to === '2026-10-23', 'upcoming default is 3 days');

check(EmailPlatformBridge::triggerMatches('booking_confirmed', [], ['product_id' => 9]), 'booking trigger without product filter matches all');
check(!EmailPlatformBridge::triggerMatches('trip_upcoming', ['product_id' => 5], ['product_id' => 9]), 'product filter excludes other products');
check(EmailPlatformBridge::triggerMatches('crm_deal_won', [], []), 'crm trigger matches');
check(!EmailPlatformBridge::triggerMatches('subscribed', [], []), 'non-bridge trigger never matches here');

$vars = EmailPlatformBridge::stepVariables(['name' => 'Sara', 'email' => 's@x.com'], ['trip_name' => 'Luxor', 'booking_reference' => 'TF-1']);
check(EmailPlatformBridge::fill('Call {{name}} about {{trip_name}} ({{booking_reference}})', $vars) === 'Call Sara about Luxor (TF-1)', 'task title personalization');

$ctx = EmailPlatformBridge::bookingContext(['id' => 4, 'product_id' => 2, 'booking_reference' => 'TF-9', 'product_name' => 'Giza', 'start_date' => '2026-11-01', 'total_amount' => '150', 'currency' => 'USD']);
check($ctx['source'] === 'booking' && $ctx['trip_name'] === 'Giza' && $ctx['booking_total'] === '150.00', 'booking context for email variables');

// ---------- safety rules with stub DB ----------
$booking = ['id' => 4, 'user_id' => 7, 'product_id' => 2, 'customer_email' => 'Guest@Example.com', 'customer_name' => 'Guest', 'booking_reference' => 'TF-9', 'product_name' => 'Giza', 'start_date' => '2026-11-01', 'total_amount' => 150, 'currency' => 'USD', 'customer_phone' => ''];

// 1) جهة اتصال عملت إلغاء اشتراك: ما بترجعش مشتركة ومفيش أتمتة
$db = new StubDb([
    'FROM bookings b' => [$booking],
    'FROM email_platform_settings' => [['sync_booking_customers' => 1, 'booking_list_id' => null, 'sync_crm_contacts' => 0, 'crm_list_id' => null, 'revenue_attribution' => 1, 'attribution_window_days' => 30]],
    'FROM email_subscribers WHERE user_id = ? AND email = ?' => [['id' => 55, 'status' => 'unsubscribed', 'attributes' => null]],
]);
(new EmailPlatformBridge($db))->onBookingEvent(4, 'booking_created');
check(!$db->ran('FROM email_automations'), 'unsubscribed customer is never enrolled in automations');
check(!$db->ran("status = 'subscribed'") && !$db->ran('INSERT INTO email_subscribers'), 'unsubscribed customer is not re-subscribed');
check($db->ran('UPDATE email_subscribers SET attributes'), 'existing contact still gets booking attributes (for segments)');

$emailParam = null;
foreach ($db->log as [$sql, $p]) { if (stripos($sql, 'FROM email_subscribers WHERE user_id = ? AND email = ?') !== false) { $emailParam = $p[1]; } }
check($emailParam === 'guest@example.com', 'customer email is normalized before lookup');

// 2) عميل جديد والمزامنة مقفولة (الافتراضي): ما يتضافش
$db = new StubDb([
    'FROM bookings b' => [$booking],
    'FROM email_platform_settings' => [],
    'FROM email_subscribers WHERE user_id = ? AND email = ?' => [],
]);
(new EmailPlatformBridge($db))->onBookingEvent(4, 'booking_created');
check(!$db->ran('FROM email_automations') && !$db->ran('email_suppressions'), 'new customer is not added when sync is off (default)');

// 3) settings defaults when table missing
$db = new StubDb(['FROM email_platform_settings' => function () { throw new RuntimeException('no table'); }]);
$s = (new EmailPlatformBridge($db))->settings(7);
check($s['sync_booking_customers'] === 0 && $s['revenue_attribution'] === 1 && $s['attribution_window_days'] === 30, 'safe defaults before migration');

// 4) لا يرمي أبدًا لنقطة الاستدعاء
$db = new StubDb(['FROM bookings b' => function () { throw new RuntimeException('db down'); }]);
$threw = false; try { (new EmailPlatformBridge($db))->onBookingEvent(4, 'booking_confirmed'); } catch (Throwable $e) { $threw = true; }
check(!$threw, 'booking hook never throws into BookingEngine');

// 5) attribution ignores bad tokens without touching the DB
$db = new StubDb([]);
(new EmailPlatformBridge($db))->attributeBooking(7, 4, 'not-a-token');
check(count($db->log) === 0, 'invalid attribution token is ignored');

echo "All email platform bridge checks passed\n";

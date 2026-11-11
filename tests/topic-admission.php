<?php
/** آزمون تشخیص/گزارش و بررسی مجدد؛ SQLite و HTTP/WP fixture، بدون اتصال واقعی. */
define( 'ACM_TOPIC_BOOTSTRAP_ONLY', true );
require __DIR__ . '/campaigns.php';
use AIContentManager\Services\TopicDiversity;
use AIContentManager\Admin\CampaignsPage;

function topic_case( $question, $angle, $title = 'عنوان پیشنهادی' ) {
    return array( 'title' => $title, 'question' => $question, 'angle' => $angle, 'summary' => $angle, 'intent' => 'implementation', 'entities' => array( 'کش','وردپرس' ), 'source_urls' => array( 'https://docs.example.org/first' ) );
}
function source_token( $id ) { global $service, $campaigns; return $service->source_signature( $campaigns->get( $id ), $campaigns->discovery( $id ) ); }
function seed_profile( $topic, $legacy = false ) {
    global $repo, $wpdb;
    $repo->insert_batch( wp_generate_uuid4(), array( array( 'content_type' => 'post', 'language' => 'en', 'payload' => array( 'topic' => $topic['title'], 'primary_keyword' => 'کش امن', 'topic_profile' => $topic ) ) ), 7 );
    $id = (int) $wpdb->get_var( 'SELECT MAX(item_id) FROM wp_acm_queue' );
    if ( $legacy ) { $wpdb->update( 'wp_acm_queue', array( 'topic_signature' => hash( 'sha256', TopicDiversity::normalize( 'کش امن' ) . ':' . $topic['intent'] ) ), array( 'item_id' => $id ) ); }
    return $id;
}

setup_campaign(); $diversity = new TopicDiversity();
$a = topic_case( 'چگونه خطای کش وردپرس را پس از تغییر پوسته رفع کنیم؟', 'عیب‌یابی خطا در تعویض پوسته', 'رفع اشکال پس از تغییر قالب' );
$b = topic_case( 'چگونه داده‌های خصوصی کاربران را از کش وردپرس حذف کنیم؟', 'حفاظت از اطلاعات حساب کاربران', 'حذف داده محرمانه از حافظه' );
$copy = $a; $copy['title'] = 'عنوان کاملاً متفاوت'; $copy['intent'] = 'security';
$r = $diversity->review( array( $a, $copy ), 'کش وردپرس', array(), 3 );
verify( 1 === count( $r['accepted'] ) && 'same_question' === $r['decisions'][1]['reason'] && 1.0 === $r['decisions'][1]['score'], 'different title and intent cannot bypass identical core question' );
verify( 'proposal' === $r['decisions'][1]['reference']['kind'] && 1 === $r['decisions'][1]['reference']['proposal'], 'duplicate report identifies earlier proposal without private corpus text' );
$r = $diversity->review( array( $a, $b ), 'کش وردپرس', array(), 3 );
verify( 2 === count( $r['accepted'] ) && $r['accepted'][0]['signature'] !== $r['accepted'][1]['signature'], 'same keyword and same broad intent permit independent questions with distinct unique signatures' );
$farsi = topic_case( 'چگونه می‌توان کش وردپرس را در شبکه توزیع محتوا پاک کرد؟', 'پاک‌کردن کش در لبه شبکه' );
$arabic = topic_case( 'چِگونه مي توان كش وردپرس را در شبكه توزیع محتوا پاک كرد؟', 'پاک کردن کش در لبه شبکه', 'عنوان دیگر' );
$r = $diversity->review( array( $farsi, $arabic ), 'کش وردپرس', array(), 3 );
verify( 1 === count( $r['accepted'] ) && $diversity->signature( $farsi['question'] ) === $diversity->signature( $arabic['question'] ), 'Persian/Arabic yeh kaf diacritics and half-space share canonical question signature' );
$negated = topic_case( 'چگونه کش وردپرس را بدون حذف اطلاعات خصوصی فعال کنیم؟', 'فعال کردن کش با حفظ اطلاعات' );
$opposite = topic_case( 'چگونه کش وردپرس را با حذف اطلاعات خصوصی غیرفعال کنیم؟', 'غیرفعال کردن کش و حذف اطلاعات' );
verify( 2 === count( $diversity->accept( array( $negated, $opposite ), 'کش وردپرس', array(), 3 ) ), 'negation and meaningful action words are retained rather than erased as stop words' );
$near = topic_case( 'How can cache invalidation failures after rolling deployment across regional application clusters with versioned payload schemas be diagnosed safely?', 'Diagnosing invalidation failures through deployment telemetry and payload versions' );
$near_copy = $near; $near_copy['question'] = str_replace( 'safely', 'reliably safely', $near['question'] );
$r = $diversity->review( array( $near, $near_copy ), 'cache', array(), 3 );
verify( 'question_angle_overlap' === $r['decisions'][1]['reason'] && $r['decisions'][1]['metrics']['question_jaccard'] >= 0.84 && $r['decisions'][1]['metrics']['angle_jaccard'] >= 0.50, 'near-identical question requires high symmetric question order and angle evidence to reject' );
$bare = topic_case( 'کش وردپرس', 'مقدمه عمومی' );
$r = $diversity->review( array( $bare ), 'کش وردپرس', array( array( 'profile' => $bare ) ), 3 );
verify( 'invalid_profile' === $r['decisions'][0]['reason'] && 0.0 === $r['decisions'][0]['score'], 'bare keyword without a core question is not falsely reported as a duplicate comparison' );

$profile_words = array_unique( explode( ' ', TopicDiversity::normalize( $a['question'] . ' ' . $a['angle'] . ' ' . $a['summary'] ) ) );
$long = '<h2>راهنمای عمومی سامانه</h2>'; $private_marker = 'PRIVATE_BODY_SENTINEL_NOT_FOR_REPORT';
foreach ( array_reverse( $profile_words ) as $word ) { $long .= '<p>' . $word . ' ' . implode( ' ', array_map( static function ( $i ) { return 'واژهعمومی' . $i; }, range( 1, 95 ) ) ) . "</p>\n"; }
$long .= '<p>' . $private_marker . '</p>';
$r = $diversity->review( array( $a ), 'کش وردپرس', array( array( 'kind' => 'post', 'id' => 99, 'title' => 'مقاله عمومی', 'content' => $long ) ), 3 );
verify( 1 === count( $r['accepted'] ), 'long general article containing all candidate words does not reject an independent specialized question' );
verify( false === strpos( wp_json_encode( $r['decisions'] ), $private_marker ), 'diagnostic output never copies private body or matching excerpt' );
$r = $diversity->review( array( $a ), 'کش وردپرس', array( array( 'kind' => 'post', 'id' => 99, 'title' => 'مقاله با بخش مرتبط', 'content' => '<h2>' . $a['question'] . '</h2><p>' . $a['angle'] . '</p>' ) ), 3 );
verify( ! $r['accepted'] && 'same_section_question' === $r['decisions'][0]['reason'] && 99 === $r['decisions'][0]['reference']['id'], 'actual identical question in related section rejects with post ID section number and score' );
$r = $diversity->review( array( $a ), 'کش وردپرس', array( array( 'kind' => 'post', 'id' => 99, 'content' => '<p>خطای رفع</p>' ), array( 'kind' => 'queue', 'id' => 7, 'profile' => $a ) ), 3 );
verify( 7 === $r['decisions'][0]['reference']['id'] && ! isset( $r['decisions'][0]['section'] ), 'winning profile comparison cannot retain an unrelated section number from earlier weaker match' );
$why = topic_case( 'چرا خطای کش وردپرس پس از تغییر پوسته رخ می‌دهد؟', 'علت ریشه‌ای خطای پوسته' );
verify( 2 === count( $diversity->accept( array( $a, $why ), 'کش وردپرس', array(), 3 ) ), 'root cause and repair questions are not duplicates merely due to shared technical words' );
$r = $diversity->review( array( $a, $b ), 'کش وردپرس', array(), 1 );
verify( 2 === count( $r['decisions'] ) && 'target_limit' === $r['decisions'][1]['reason'] && 'deferred' === $r['decisions'][1]['status'], 'every proposal gets a decision and target overflow is distinct from duplicate rejection' );

// بازسازی حالت توقف ۰.۷.۰ با خروجی discover موفق و admit ذخیره‌شده؛ داده‌ها همگی ساختگی‌اند.
setup_campaign(); $id = create_started(); step_ok();
foreach ( topics_fixture() as $i => $topic ) { $topic['question'] = 'Which unrelated archival procedure ' . $i . ' applies to offsite records?'; $topic['angle'] = 'Archival custody paperwork'; seed_profile( $topic, true ); }
$legacy_signatures = $wpdb->get_col( 'SELECT topic_signature FROM wp_acm_queue ORDER BY item_id' );
$repo->lock_worker(); $claim = $campaigns->claim(); $original = $campaigns->discovery( $id );
$campaigns->save_result( $claim, array( 'value' => json_decode( $original['output'], true )['value'] ) );
$campaigns->stop( $id, 'review', 'پیام کلی قدیمی بدون دلیل ثبت‌شده' ); $repo->unlock_worker();
$before_usage = $campaigns->usage( $id ); $before_requests = $api_calls; $before_gets = $source_gets; $before_steps = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_acm_campaign_steps' );
$page = new CampaignsPage( $campaigns, $service ); $html = capture( static function () use ( $page ) { $page->render(); } );
verify( false !== strpos( $html, 'دلیل تاریخی' ) && false !== strpos( $html, 'Foundations' ) && false !== strpos( $html, 'What fundamental concepts' ), 'old stopped campaign shows saved title question angle and unknown historical rejection instead of inventing a cause' );
verify( $before_steps === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_acm_campaign_steps' ) && $before_requests === $api_calls, 'GET display performs no evaluation writes or network requests' );
unset( $options['acm_openai_api_key'], $options['acm_campaign_pricing'] );
$reviewed = $service->review_saved( $id, source_token( $id ) ); $report = $campaigns->latest_admission( $id );
verify( ! is_wp_error( $reviewed ) && 3 === count( array_filter( $report['decisions'], static function ( $d ) { return 'accepted' === $d['status']; } ) ), 'explicit local recheck accepts independent questions despite old identical keyword:intent signatures' );
verify( $api_calls === $before_requests && $source_gets === $before_gets && $before_usage === $campaigns->usage( $id ), 'stored-output recheck has zero network and zero new token search monetary usage' );
verify( $original['output'] === $campaigns->discovery( $id )['output'] && 'review' === $campaigns->get( $id )['status'] && 0 === (int) $campaigns->get( $id )['accepted_count'], 'local preview preserves original discovery and does not activate or admit campaign' );
$r2 = $service->review_saved( $id, source_token( $id ) );
verify( ! is_wp_error( $r2 ) && 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_acm_campaign_steps WHERE kind='admit_review'" ), 'repeat recheck appends a separate history record without replacing prior decisions' );
verify( ! is_wp_error( start_campaign( $id ) ), 'continuation of stored admit output needs no paid discovery replay' ); step_ok(); $service->control( $id, 'pause' );
verify( 3 === (int) $campaigns->get( $id )['accepted_count'] && $api_calls === $before_requests && $before_usage === $campaigns->usage( $id ), 'same campaign admits saved topics under lock without another API call or consumption' );
verify( $legacy_signatures === array_slice( $wpdb->get_col( 'SELECT topic_signature FROM wp_acm_queue ORDER BY item_id' ), 0, 3 ), 'legacy signatures and queue payload history are not rewritten or cleared' );
$new = $wpdb->get_col( 'SELECT topic_signature FROM wp_acm_queue WHERE campaign_id IS NOT NULL ORDER BY item_id' );
verify( 3 === count( array_unique( $new ) ) && ! array_intersect( $new, $legacy_signatures ), 'new question-based signatures coexist with old keyword:intent index entries' );

setup_campaign(); foreach ( topics_fixture() as $topic ) { seed_profile( $topic ); } $id = create_started(); step_ok(); $worker->run_once();
$report = $campaigns->latest_admission( $id );
verify( 'review' === $campaigns->get( $id )['status'] && 3 === count( $report['decisions'] ) && 'same_question' === $report['decisions'][0]['reason'] && 1 === $api_calls, 'all actual duplicate core questions leave durable reason score and queue reference even after admit rollback' );
$original = $campaigns->discovery( $id ); $old_usage = $campaigns->usage( $id );
$quote = $service->quote( $campaigns->get( $id ), 'rediscover' );
verify( is_wp_error( $service->rediscover( $id, false, $quote['signature'], source_token( $id ) ) ) && is_wp_error( $service->rediscover( $id, true, $service->quote( $campaigns->get( $id ) )['signature'], source_token( $id ) ) ), 'fresh discovery requires separate explicit cost confirmation and purpose-bound quote' );
verify( ! is_wp_error( $service->rediscover( $id, true, $quote['signature'], source_token( $id ) ) ) && 1 === $api_calls, 'fresh discovery command schedules only after separate confirmation and does not dispatch inline' );
verify( 'superseded' === $campaigns->step( $report['admit_step_id'] )['state'] && $original['output'] === $campaigns->step( $original['step_id'] )['output'] && $old_usage === $campaigns->usage( $id ), 'fresh discovery retires pending admit without deleting old result or consumption' );
step_ok(); $worker->run_once();
verify( 2 === $api_calls && 2 === $campaigns->call_count( $id, 0 ) && 'review' === $campaigns->get( $id )['status'], 'fresh discovery is a new bounded guarded attempt in same campaign with prior usage counted' );
$quote = $service->quote( $campaigns->get( $id ), 'rediscover' );
verify( is_wp_error( $service->rediscover( $id, true, $quote['signature'], source_token( $id ) ) ) && 2 === $api_calls, 'two-attempt discovery ceiling cannot be reset by recheck or new-discovery control' );

setup_campaign(); $id = create_started(); step_ok(); $service->control( $id, 'pause' );
$page = new CampaignsPage( $campaigns, $service ); $_SERVER['REQUEST_METHOD'] = 'GET';
$input = array( 'campaign_id' => $id, 'operation' => 'review_saved', '_wpnonce' => wp_create_nonce( 'acm_campaign_' . $id ), 'source_signature' => source_token( $id ) );
verify( is_wp_error( $page->command( $input ) ), 'local recheck cannot mutate history via GET' ); $_SERVER['REQUEST_METHOD'] = 'POST';
$bad = $input; $bad['_wpnonce'] = 'invalid'; verify( is_wp_error( $page->command( $bad ) ), 'local recheck nonce enforced' );
$bad = $input; $bad['source_signature'] = 'stale'; verify( is_wp_error( $page->command( $bad ) ), 'stale or tampered saved source signature denied' );
$caps['manage_ai_content'] = false; verify( is_wp_error( $page->command( $input ) ), 'local recheck capability enforced' ); $caps['manage_ai_content'] = true;
$fixture_current_user = 9; $caps['manage_options'] = false; $other_actor = $input; $other_actor['source_signature'] = source_token( $id ); verify( is_wp_error( $page->command( $other_actor ) ), 'another non-administrator with own valid source signature cannot recheck owner campaign through controller' );
$fixture_current_user = 7; $caps['manage_options'] = true; $wpdb->block_worker = true;
verify( is_wp_error( $page->command( $input ) ), 'recheck respects global worker lock and cannot race active admission' ); $wpdb->block_worker = false;
verify( ! is_wp_error( $page->command( $input ) ) && 1 === $api_calls, 'authorized explicit POST recheck uses only saved output' );
verify( is_wp_error( $service->rediscover( $id, true, $service->quote( $campaigns->get( $id ), 'rediscover' )['signature'], source_token( $id ) ) ), 'fresh paid discovery cannot replace saved proposals currently acceptable by local review' );

// صلاحیت نمایش عنوان مرجع مستقل از صلاحیت مدیریت کمپین است.
setup_campaign(); $id = create_started(); step_ok(); $service->control( $id, 'pause' );
$q = topics_fixture()[0]; $secret_title = 'PRIVATE_TITLE_SENTINEL'; $private_marker = 'PRIVATE_BODY_SENTINEL';
$wpdb->insert( 'wp_posts', array( 'ID' => 99, 'post_type' => 'post', 'post_status' => 'private', 'post_title' => $secret_title, 'post_excerpt' => '', 'post_content' => '<h2>' . $q['question'] . '</h2><p>' . $private_marker . '</p>' ) );
$fixture_posts[99] = (object) array( 'ID' => 99, 'post_title' => $secret_title, 'post_status' => 'private' ); $post_capabilities[99] = false;
$service->review_saved( $id, source_token( $id ) ); $report_json = wp_json_encode( $campaigns->latest_admission( $id ) );
$html = capture( static function () use ( $campaigns, $service ) { ( new CampaignsPage( $campaigns, $service ) )->render(); } );
verify( false === strpos( $report_json, $secret_title ) && false === strpos( $report_json, $private_marker ) && false === strpos( $html, $secret_title ) && false === strpos( $html, $private_marker ) && false !== strpos( $html, 'نوشته #99' ), 'private matching title body and excerpt are not persisted or revealed without edit permission while reference ID remains inspectable' );
verify( false === strpos( $html, 'same_section_question' ) && false !== strpos( $html, 'پرسش اصلی در یک بخش' ), 'rejection reason is rendered in Persian rather than an internal code' );

setup_campaign(); $id = create_started(); $fixture_topics_override = array( $a, $b ); step_ok(); step_ok();
verify( 2 === (int) $campaigns->get( $id )['accepted_count'] && 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_acm_queue' ), 'actual admission and SQL unique index accept two independent questions with same broad intent' );
setup_campaign(); $id = create_started(); step_ok(); $source = $campaigns->discovery( $id ); $wpdb->fail_review = true; $worker->run_once();
verify( 'review' === $campaigns->get( $id )['status'] && $campaigns->pending_result( $id ) && ! $campaigns->has_items( $id ) && 1 === $api_calls, 'failed diagnostic storage prevents admission and preserves paid output for review' );
$wpdb->fail_review = false; start_campaign( $id ); step_ok();
verify( 1 === $api_calls && 3 === (int) $campaigns->get( $id )['accepted_count'] && $source['output'] === $campaigns->discovery( $id )['output'], 'retry after diagnostic storage repair admits same saved output without paid regeneration' );

// قفل مشترک و یکتایی واقعی SQLite، به‌همراه بازخوانی corpus پیش از پذیرش دوم.
setup_campaign(); $first = create_started(); step_ok(); step_ok(); $service->control( $first, 'pause' );
$second = create_started(); step_ok(); $worker->run_once();
verify( 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_acm_queue' ) && 'review' === $campaigns->get( $second )['status'], 'second serialized admission re-reads first campaign and cannot accept same core question concurrently' );
$row = $repo->get( 1 ); $insert = $row; unset( $insert['item_id'] ); $insert['campaign_id'] = $second; $insert['batch_id'] = wp_generate_uuid4();
verify( false === $wpdb->insert( 'wp_acm_queue', $insert ), 'unique question signature rejects duplicate even if an insert attempts to bypass evaluator' );
verify( 0 === $network_calls && ! AIContentManager\Services\ExecutionContext::active(), 'entire suite used local fixtures with zero external network and no leaked execution scope' );

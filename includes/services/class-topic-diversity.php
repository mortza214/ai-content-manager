<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** مقایسه محلی و قابل توضیح پرسش و زاویه؛ بدون ادعای تشخیص کامل معنایی. */
final class TopicDiversity {
    const VERSION = 'question-sections-v2';
    private $keyword = array();
    public static function normalize( $text ) {
        $text = str_replace( array( 'ي', 'ى', 'ك', 'أ', 'إ', '‌', 'ـ' ), array( 'ی', 'ی', 'ک', 'ا', 'ا', ' ', '' ), (string) $text );
        $text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
    }
    private function words( $text ) {
        static $stop;
        if ( null === $stop ) {
            $stop = array_fill_keys( explode( ' ', 'از به با برای در را و یا این آن یک است هستند بود باشد شود شده شدن می میتوان توان تواند باید کنیم کنید کردن کرد کرده ها های ترین تر تا که هم هر درباره مورد مقاله راهنما روش the a an of to in on at for from with and or is are was be been being can could should would do does did this that these those it its about article guide' ), true );
        }
        return array_values( array_filter( explode( ' ', self::normalize( $text ) ), static function ( $word ) use ( $stop ) { return '' !== $word && ! isset( $stop[$word] ); } ) );
    }
    private function frame( $text ) {
        foreach ( $this->words( $text ) as $word ) {
            foreach ( array( 'how' => array( 'چگونه','چطور','how' ), 'why' => array( 'چرا','why' ), 'what' => array( 'چیست','چیستند','چه','what' ), 'which' => array( 'کدام','which' ), 'when' => array( 'کی','چهزمانی','when' ), 'where' => array( 'کجا','where' ), 'whether' => array( 'آیا','ایا','whether' ) ) as $frame => $words ) {
                if ( in_array( $word, $words, true ) ) { return $frame; }
            }
        }
        return '';
    }
    private function content_words( $text, $remove_keyword = true ) {
        return array_values( array_filter( $this->words( $text ), function ( $word ) use ( $remove_keyword ) {
            return ! in_array( $word, array( 'چگونه','چطور','چرا','چیست','چیستند','چه','کدام','کی','چهزمانی','کجا','آیا','ایا','how','why','what','which','when','where','whether' ), true ) && ( ! $remove_keyword || ! isset( $this->keyword[$word] ) );
        } ) );
    }
    private function canonical_question( $question ) { return $this->frame( $question ) . ':' . implode( ' ', $this->content_words( $question, false ) ); }
    public function signature( $question ) { return hash( 'sha256', self::VERSION . ':' . $this->canonical_question( $question ) ); }
    private function jaccard( $a, $b ) {
        $a = array_unique( $a ); $b = array_unique( $b ); $union = count( array_unique( array_merge( $a, $b ) ) );
        return $union ? count( array_intersect( $a, $b ) ) / $union : 0.0;
    }
    private function bigrams( $words ) {
        $pairs = array(); for ( $i = 1; $i < count( $words ); $i++ ) { $pairs[] = $words[$i - 1] . ' ' . $words[$i]; } return $pairs;
    }
    private function compare( $topic, $question, $angle, $section = false ) {
        $a = $this->content_words( $topic['question'] ); $b = $this->content_words( $question );
        $q = $this->jaccard( $a, $b ); $pairs = $this->jaccard( $this->bigrams( $a ), $this->bigrams( $b ) );
        $angle_score = $this->jaccard( $this->content_words( $topic['angle'] ), $this->content_words( $angle ) );
        $same = '' !== implode( '', $this->content_words( $question, false ) ) && $this->canonical_question( $topic['question'] ) === $this->canonical_question( $question );
        $frames_match = '' === $this->frame( $question ) || '' === $this->frame( $topic['question'] ) || $this->frame( $question ) === $this->frame( $topic['question'] );
        // پرسش همسان با عنوان متفاوت هم تکرار است؛ در حالت غیرهمسان، شاهدِ زاویه و ترتیب نیز لازم است.
        $duplicate = $same || ( count( array_unique( $a ) ) >= 3 && count( array_unique( $b ) ) >= 3 && $frames_match && $q >= 0.84 && $pairs >= 0.65 && $angle_score >= 0.50 );
        return array( 'duplicate' => $duplicate, 'reason' => $same ? ( $section ? 'same_section_question' : 'same_question' ) : ( $duplicate ? 'question_angle_overlap' : 'independent' ),
            'score' => $same ? 1.0 : round( $q * 0.6 + $pairs * 0.2 + $angle_score * 0.2, 6 ),
            'metrics' => array( 'canonical_question_equal' => $same ? 1 : 0, 'question_jaccard' => round( $q, 6 ), 'question_bigrams' => round( $pairs, 6 ), 'angle_jaccard' => round( $angle_score, 6 ) ) );
    }
    /** فقط بخش‌های کوتاه مقایسه می‌شوند؛ متن خصوصی در گزارش قرار نمی‌گیرد. */
    private function sections( $entry ) {
        $html = $entry['content'] ?? $entry['text'] ?? '';
        $html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
        $parts = preg_split( '#(?:</(?:p|h[1-6]|li|section)>|[؟?]|[\r\n]+)#iu', $html );
        $sections = array(); $heading = $entry['title'] ?? '';
        if ( '' !== $heading ) { $sections[] = array( 'question' => $heading, 'angle' => $entry['excerpt'] ?? '', 'section' => 0 ); }
        foreach ( $parts as $part ) {
            $text = trim( html_entity_decode( wp_strip_all_tags( $part ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( '' === $text ) { continue; }
            if ( preg_match( '#<h[1-6]\b#i', $part ) ) { $heading = $text; }
            $words = preg_split( '/\s+/u', $text );
            // حداکثر ۸۰ قطعهٔ حداکثر ۸۰ واژه‌ای؛ محدودیت در گزارش الگوریتم مستند است.
            for ( $offset = 0; $offset < count( $words ) && count( $sections ) < 80; $offset += 40 ) {
                $window = implode( ' ', array_slice( $words, $offset, 80 ) );
                $sections[] = array( 'question' => $window, 'angle' => $heading . ' ' . $window, 'section' => count( $sections ) + 1 );
                if ( $offset + 80 >= count( $words ) ) { break; }
            }
            if ( count( $sections ) >= 80 ) { break; }
        }
        return $sections;
    }
    private function reference( $entry ) {
        return array( 'kind' => $entry['kind'] ?? 'existing', 'id' => (int) ( $entry['id'] ?? 0 ), 'proposal' => (int) ( $entry['proposal'] ?? 0 ) );
    }
    public function review( $topics, $keyword, $corpus, $target ) {
        $this->keyword = array_fill_keys( $this->content_words( $keyword, false ), true );
        $accepted = array(); $decisions = array();
        foreach ( $corpus as &$entry ) { $entry['sections'] = $this->sections( $entry ); } unset( $entry );
        foreach ( array_values( $topics ) as $index => $topic ) {
            $decision = array( 'proposal' => $index + 1, 'title' => $topic['title'] ?? '', 'question' => $topic['question'] ?? '', 'angle' => $topic['angle'] ?? '',
                'status' => 'accepted', 'reason' => 'independent', 'score' => 0.0, 'metrics' => array(), 'reference' => null );
            if ( ! is_string( $decision['question'] ) || ! is_string( $decision['angle'] ) || ! preg_match( '//u', $decision['question'] . $decision['angle'] ) || ! $this->content_words( $decision['question'], false ) ||
                ( ! $this->content_words( $decision['question'] ) && '' === $this->frame( $decision['question'] ) ) || '' === trim( $decision['angle'] ) ) {
                $decision['status'] = 'rejected'; $decision['reason'] = 'invalid_profile'; $decisions[] = $decision; continue;
            }
            foreach ( $corpus as $entry ) {
                $matches = array(); $profile = $entry['profile'] ?? null;
                if ( is_array( $profile ) && is_string( $profile['question'] ?? null ) && is_string( $profile['angle'] ?? null ) ) { $matches[] = $this->compare( $topic, $profile['question'], $profile['angle'] ); }
                foreach ( $entry['sections'] as $section ) { $match = $this->compare( $topic, $section['question'], $section['angle'], true ); $match['section'] = $section['section']; $matches[] = $match; }
                foreach ( $matches as $match ) {
                    if ( $match['duplicate'] || $match['score'] > $decision['score'] ) {
                        unset( $decision['section'] );
                        $decision = array_merge( $decision, array_intersect_key( $match, array_flip( array( 'score', 'metrics', 'section' ) ) ) );
                        $decision['reference'] = $this->reference( $entry );
                    }
                    if ( $match['duplicate'] ) { $decision['status'] = 'rejected'; $decision['reason'] = $match['reason']; break 2; }
                }
            }
            if ( 'accepted' === $decision['status'] && count( $accepted ) >= $target ) { $decision['status'] = 'deferred'; $decision['reason'] = 'target_limit'; }
            if ( 'accepted' === $decision['status'] ) {
                $topic['signature'] = $this->signature( $topic['question'] ); $topic['signature_version'] = self::VERSION; $accepted[] = $topic;
                $corpus[] = array( 'profile' => $topic, 'kind' => 'proposal', 'proposal' => $index + 1, 'sections' => array() );
            }
            $decisions[] = $decision;
        }
        return array( 'algorithm' => self::VERSION, 'accepted' => $accepted, 'decisions' => $decisions, 'corpus_count' => count( $corpus ) - count( $accepted ) );
    }
    public function accept( $topics, $keyword, $corpus, $target ) { return $this->review( $topics, $keyword, $corpus, $target )['accepted']; }
    public static function reason_label( $reason ) {
        return array( 'same_question' => 'پرسش اصلی پس از نرمال‌سازی یکسان است؛ عنوان یا برچسب قصد معیار رد نیست.', 'same_section_question' => 'پرسش اصلی در یک بخش مشخص از محتوای موجود یکسان است.',
            'question_angle_overlap' => 'هم‌زمان پرسش، ترتیب واژه‌های پرسش و زاویهٔ مرتبط شباهت بالا دارند.', 'independent' => 'با معیار محلی تکراری تشخیص داده نشد؛ بازبینی انسانی همچنان لازم است.',
            'invalid_profile' => 'پرسش یا زاویهٔ ذخیره‌شده برای مقایسه معتبر نیست.', 'target_limit' => 'به سقف تعداد هدف رسیده‌ایم؛ این پیشنهاد به علت تکرار رد نشده است.' )[ $reason ] ?? 'علت تاریخی ثبت نشده است.';
    }
}

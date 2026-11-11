<?php
namespace AIContentManager\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Conservative local intent + question/summary/content overlap, not an embeddings claim. */
final class TopicDiversity {
    public static function normalize( $text ) {
        $text = str_replace( array( 'ي', 'ك', '‌' ), array( 'ی', 'ک', ' ' ), $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
    }
    private function tokens( $text ) { return array_values( array_unique( array_filter( explode( ' ', self::normalize( $text ) ), static function ( $word ) { return strlen( $word ) > 2; } ) ) ); }
    private function near( $first, $second ) {
        $a = $this->tokens( $first ); $b = $this->tokens( $second );
        if ( count( $a ) < 4 || count( $b ) < 4 ) { return self::normalize( $first ) === self::normalize( $second ); }
        return count( array_intersect( $a, $b ) ) / min( count( $a ), count( $b ) ) >= 0.78;
    }
    public function accept( $topics, $keyword, $corpus, $target ) {
        $accepted = array(); $intents = array(); $key = self::normalize( $keyword );
        foreach ( $topics as $topic ) {
            if ( isset( $intents[ $topic['intent'] ] ) ) { continue; }
            $profile_text = $topic['question'] . ' ' . $topic['angle'] . ' ' . $topic['summary'];
            $duplicate = false;
            foreach ( $corpus as $existing ) {
                $profile = $existing['profile'] ?? null;
                if ( is_array( $profile ) && ( ( self::normalize( $existing['keyword'] ?? '' ) === $key && $profile['intent'] === $topic['intent'] ) || $this->near( $topic['question'], $profile['question'] ) || $this->near( $profile_text, $profile['question'] . ' ' . $profile['angle'] . ' ' . $profile['summary'] ) ) ) { $duplicate = true; break; }
                if ( ! empty( $existing['text'] ) && $this->near( $profile_text, $existing['text'] ) ) { $duplicate = true; break; }
            }
            if ( $duplicate ) { continue; }
            $topic['signature'] = hash( 'sha256', $key . ':' . $topic['intent'] );
            $accepted[] = $topic; $intents[ $topic['intent'] ] = true;
            $corpus[] = array( 'profile' => $topic, 'keyword' => $keyword, 'text' => $profile_text );
            if ( count( $accepted ) >= $target ) { break; }
        }
        return $accepted;
    }
}

<?php

defined( 'ABSPATH' ) || exit;

final class GI_Scheduler {
    public const HOOK = 'gi_run_due_sources';

    public static function ensure_schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::HOOK );
        }
    }

    public static function clear_schedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
            $timestamp = wp_next_scheduled( self::HOOK );
        }
    }

    public static function run_due_sources(): void {
        GI_Storage::prune_runs();
        $now = time();
        foreach ( GI_Storage::list_sources() as $source ) {
            if ( empty( $source['is_saved'] ) || empty( $source['schedule']['enabled'] ) ) {
                continue;
            }
            $next = sanitize_text_field( $source['schedule']['next_run_gmt'] ?? '' );
            if ( ! $next ) {
                self::advance_source( (int) $source['id'], true );
                continue;
            }
            $next_timestamp = strtotime( $next . ' UTC' );
            if ( $next_timestamp && $next_timestamp <= $now ) {
                GI_Runner::run_source( (int) $source['id'], 'scheduled' );
            }
        }
    }

    public static function advance_source( int $source_id, bool $from_now = false ): string {
        $source = GI_Storage::get_source( $source_id );
        if ( ! $source || empty( $source['is_saved'] ) ) {
            return '';
        }
        $schedule = $source['schedule'];
        $tz = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $parts = array_map( 'intval', explode( ':', $schedule['time'] ?? '08:00' ) );
        $hour = $parts[0] ?? 8;
        $minute = $parts[1] ?? 0;
        $weekday = min( 6, max( 0, absint( $schedule['weekday'] ?? 1 ) ) );
        $monthday = min( 28, max( 1, absint( $schedule['monthday'] ?? 1 ) ) );
        $cadence = sanitize_key( $schedule['cadence'] ?? 'daily' );
        $existing = sanitize_text_field( $schedule['next_run_gmt'] ?? '' );
        $base = $now;

        if ( ! $from_now && $existing ) {
            try {
                $base = ( new DateTimeImmutable( $existing, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
                if ( $base < $now->modify( '-5 minutes' ) ) {
                    $base = $now;
                }
            } catch ( Throwable $e ) {
                $base = $now;
            }
        }

        switch ( $cadence ) {
            case 'hourly':
                $candidate = $base->setTime( (int) $base->format( 'H' ), $minute, 0 );
                if ( $candidate <= $now || ( ! $from_now && $candidate <= $base ) ) {
                    $candidate = $candidate->modify( '+1 hour' );
                }
                $next = $candidate;
                break;

            case 'weekly':
                if ( ! $from_now && $existing ) {
                    $next = $base->modify( '+7 days' )->setTime( $hour, $minute, 0 );
                    break;
                }
                $days_ahead = ( $weekday - (int) $now->format( 'w' ) + 7 ) % 7;
                $next = $now->modify( '+' . $days_ahead . ' days' )->setTime( $hour, $minute, 0 );
                if ( $next <= $now ) {
                    $next = $next->modify( '+7 days' );
                }
                break;

            case 'monthly':
                if ( ! $from_now && $existing ) {
                    $next = $base->modify( 'first day of next month' )->setDate( (int) $base->modify( 'first day of next month' )->format( 'Y' ), (int) $base->modify( 'first day of next month' )->format( 'm' ), $monthday )->setTime( $hour, $minute, 0 );
                    break;
                }
                $next = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'm' ), $monthday )->setTime( $hour, $minute, 0 );
                if ( $next <= $now ) {
                    $next_month = $now->modify( 'first day of next month' );
                    $next = $next_month->setDate( (int) $next_month->format( 'Y' ), (int) $next_month->format( 'm' ), $monthday )->setTime( $hour, $minute, 0 );
                }
                break;

            case 'daily':
            default:
                if ( ! $from_now && $existing ) {
                    $next = $base->modify( '+1 day' )->setTime( $hour, $minute, 0 );
                    break;
                }
                $next = $now->setTime( $hour, $minute, 0 );
                if ( $next <= $now ) {
                    $next = $next->modify( '+1 day' );
                }
                break;
        }
        $next_gmt = $next->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        GI_Storage::update_source( $source_id, array( 'schedule' => array( 'next_run_gmt' => $next_gmt ) ) );
        return $next_gmt;
    }
}
